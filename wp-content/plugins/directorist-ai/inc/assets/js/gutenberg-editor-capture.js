/* global directoristAIGutenbergBoot, wp */
( function () {
	'use strict';

	const boot = window.directoristAIGutenbergBoot;
	if ( ! boot || ! window.wp || ! wp.data || ! wp.blocks ) {
		return;
	}

	const TOKEN = /^[A-Za-z0-9][A-Za-z0-9_.:/-]{0,127}$/;
	const BASE_BLOCKS = [
		'core/group', 'core/columns', 'core/column', 'core/heading',
		'core/paragraph', 'core/image', 'core/buttons', 'core/button',
		'core/spacer',
	];
	const BLOCK_KEYS = [
		'allowedBlocks', 'ancestor', 'attributes', 'name', 'parent',
		'providesContext', 'selectors', 'styles', 'supports', 'usesContext',
	];
	const ATTRIBUTE_KEYS = [
		'__experimentalRole', 'attribute', 'default', 'description',
		'enabled', 'enum', 'items', 'query', 'role', 'selector', 'source', 'type',
	];
	let last = null;

	function fail( code ) {
		const error = new Error( code );
		error.code = code;
		throw error;
	}

	function plain( value ) {
		return JSON.parse( JSON.stringify( value ) );
	}

	function editor() {
		const state = wp.data.select( 'core/editor' );
		const blocks = wp.data.select( 'core/block-editor' );
		if (
			! state || ! blocks ||
			Number( state.getCurrentPostId() ) !== boot.pageId ||
			state.getCurrentPostType() !== 'page' ||
			state.isSavingPost()
		) {
			fail( 'editor_unavailable' );
		}
		return { state, blocks };
	}

	function tree( blocks ) {
		return blocks.map( ( block ) => ( {
			name: block.name,
			clientId: block.clientId,
			attributes: plain( block.attributes ),
			innerBlocks: tree( block.innerBlocks || [] ),
		} ) );
	}

	function observe() {
		const stores = editor();
		const documentBlocks = tree( stores.blocks.getBlocks() );
		const content = stores.state.getEditedPostContent();
		const post = stores.state.getCurrentPost();
		const savedContent = typeof post?.content === 'string' ? post.content : post?.content?.raw;
		if ( typeof savedContent !== 'string' ) {
			fail( 'saved_source_unavailable' );
		}
		const key = JSON.stringify( [
			boot.pageId,
			post && post.modified_gmt,
			post && post.status,
			stores.state.isEditedPostDirty(),
			savedContent,
			content,
			documentBlocks,
		] );
		if ( key.length > 5000000 ) {
			fail( 'document_too_large' );
		}
		return { documentBlocks, key, savedContent };
	}

	function blockNames( documentBlocks, extra ) {
		if ( ! Array.isArray( extra ) || extra.length > 80 ) {
			fail( 'invalid_component_selection' );
		}
		const names = new Set( BASE_BLOCKS );
		const visit = ( blocks ) => blocks.forEach( ( block ) => {
			names.add( block.name );
			visit( block.innerBlocks );
		} );
		visit( documentBlocks );
		extra.forEach( ( name ) => {
			if ( typeof name !== 'string' || ! TOKEN.test( name ) ) {
				fail( 'invalid_component_selection' );
			}
			names.add( name );
		} );
		if ( names.size > 100 ) {
			fail( 'component_selection_too_large' );
		}
		return Array.from( names ).sort();
	}

	function registeredBlocks( names ) {
		return names.map( ( name ) => {
			const registered = wp.blocks.getBlockType( name );
			if ( ! registered ) {
				fail( 'component_unavailable' );
			}
			const block = {};
			BLOCK_KEYS.forEach( ( key ) => {
				if ( key in registered && registered[ key ] !== undefined ) {
					block[ key ] = registered[ key ];
				}
			} );
			block.name = name;
			if ( block.attributes ) {
				const attributes = {};
				Object.entries( block.attributes ).forEach( ( [ key, spec ] ) => {
					if ( spec && typeof spec === 'object' ) {
						attributes[ key ] = {};
						ATTRIBUTE_KEYS.forEach( ( field ) => {
							if ( field in spec ) {
								attributes[ key ][ field ] = spec[ field ];
							}
						} );
					}
				} );
				block.attributes = attributes;
			}
			return plain( block );
		} );
	}

	function groupVariations() {
		const found = wp.blocks.getBlockVariations( 'core/group' ) || [];
		return found.filter( ( item ) => [ 'group', 'group-row', 'group-stack', 'group-grid' ].includes( item.name ) )
			.map( ( item ) => plain( {
				name: item.name,
				attributes: item.attributes || {},
				scope: item.scope || [],
			} ) );
	}

	function allowedBlockTypes() {
		const settings = wp.data.select( 'core/block-editor' ).getSettings();
		const allowed = settings && settings.allowedBlockTypes;
		return allowed === true || allowed === false || Array.isArray( allowed ) ? allowed : true;
	}

	async function request( route, data ) {
		const response = await fetch( boot.endpoint + route, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': boot.nonce },
			body: JSON.stringify( data ),
		} );
		const body = await response.json();
		if ( ! response.ok ) {
			fail( body && body.code ? body.code : 'capture_failed' );
		}
		return body;
	}

	async function capture( componentNames = [] ) {
		last = null;
		const before = observe();
		const names = blockNames( before.documentBlocks, componentNames );
		const registry = registeredBlocks( names );
		const allowed = allowedBlockTypes();
		const variations = groupVariations();
		const challenge = await request( 'editor-challenge', {
			document_kind: 'page', directory_id: null, document_ref: boot.documentRef,
			saved_content: before.savedContent,
		} );
		if ( observe().key !== before.key ) {
			fail( 'editor_changed' );
		}
		const revision = 'rev-' + Date.now().toString( 36 ) + '-' + Math.random().toString( 36 ).slice( 2 );
		const binding = await request( 'editor-snapshot', {
			binding_id: challenge.binding_id,
			nonce: challenge.nonce,
			blocks: registry,
			allowed_block_types: allowed,
			group_variations: variations,
			document_blocks: before.documentBlocks,
			editor_revision: revision,
		} );
		if ( observe().key !== before.key ) {
			fail( 'editor_changed' );
		}
		last = { bindingId: binding.binding_id, fingerprint: binding.fingerprint, editorRevision: revision, key: before.key };
		return { bindingId: last.bindingId, fingerprint: last.fingerprint, editorRevision: last.editorRevision };
	}

	// Local editor comparison only; the server always rechecks the saved source.
	function unchangedInEditor() {
		if ( ! last ) {
			return null;
		}
		try {
			return observe().key === last.key ? {
				bindingId: last.bindingId,
				fingerprint: last.fingerprint,
				editorRevision: last.editorRevision,
			} : null;
		} catch ( error ) {
			return null;
		}
	}

	window.directoristAIGutenbergCapture = Object.freeze( { capture, unchangedInEditor } );
}() );
