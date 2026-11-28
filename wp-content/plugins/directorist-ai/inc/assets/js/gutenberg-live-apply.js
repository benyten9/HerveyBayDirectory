/* global wp, directoristAIGutenbergApplyBoot */
( function () {
	'use strict';

	const boot = window.directoristAIGutenbergApplyBoot;
	if ( ! boot || ! window.wp || ! wp.plugins || ! wp.editPost || ! wp.apiFetch || ! window.crypto?.subtle ) {
		return;
	}
	let armedPermit = null;
	let capturedBinding = null;
	const autosaveLock = 'directorist-ai-reviewed-apply';
	wp.data.subscribe( () => {
		if ( ! armedPermit ) {
			return;
		}
		const blocks = wp.data.select( 'core/block-editor' )?.getBlocks();
		if ( ! Array.isArray( blocks ) ) {
			return;
		}
		const current = JSON.stringify( serialized( blocks ) );
		if ( current === armedPermit.beforeTree ) {
			armedPermit.phase = 'undone';
		} else if ( current === armedPermit.candidateTree ) {
			armedPermit.phase = 'candidate';
		} else {
			armedPermit.phase = 'changed';
		}
	} );
	wp.apiFetch.use( async ( options, next ) => {
		const method = ( options.method || 'GET' ).toUpperCase();
		const route = options.path || options.url || '';
		const pageRoute = new RegExp( '/wp/v2/pages/' + boot.pageId + '(?:/autosaves)?(?:\\?|$)' );
		if ( ! armedPermit || ! [ 'POST', 'PUT', 'PATCH' ].includes( method ) || ! pageRoute.test( route ) ) {
			return next( options );
		}
		const autosave = route.includes( '/autosaves' );
		if ( armedPermit.phase === 'changed' ) {
			reject( 'The reviewed block changed after apply. Undo that edit or reload before saving.' );
		}
		const guarded = ! autosave && armedPermit.phase === 'undone' ? options : { ...options, headers: { ...( options.headers || {} ), 'X-Directorist-AI-Live-Operation': armedPermit.operationId } };
		const response = await next( guarded );
		if ( ! autosave ) {
			if ( armedPermit.phase === 'candidate' ) {
				const result = await getJson( liveRestUrl( armedPermit.operationId ) );
				if ( result.state !== 'succeeded' || result.reviewed_state_current !== true ) {
					reject( 'WordPress saved, but the reviewed live receipt is not confirmed. Reload and inspect the saved page.' );
				}
			}
			armedPermit = null;
			wp.data.dispatch( 'core/editor' ).unlockPostAutosaving( autosaveLock );
		}
		return response;
	} );

	const text = ( value ) => wp.i18n.__( value, 'directorist-ai' );

	function reject( code ) {
		throw new Error( code );
	}

	function tree( blocks ) {
		return blocks.map( ( block ) => ( {
			name: block.name,
			clientId: block.clientId,
			attributes: block.attributes,
			innerBlocks: tree( block.innerBlocks || [] ),
		} ) );
	}

	function editorSnapshot() {
		const state = wp.data.select( 'core/editor' );
		const blocks = wp.data.select( 'core/block-editor' );
		if ( ! state || ! blocks || Number( state.getCurrentPostId() ) !== boot.pageId || state.getCurrentPostType() !== 'page' || state.isSavingPost() || state.isEditedPostDirty() ) {
			reject( 'The page has unsaved changes or is being saved. Review and save those changes before applying this draft.' );
		}
		const post = state.getCurrentPost();
		const saved = typeof post?.content === 'string' ? post.content : post?.content?.raw;
		if ( typeof saved !== 'string' ) {
			reject( 'The saved page content is unavailable.' );
		}
		const live = blocks.getBlocks();
		const key = JSON.stringify( [ saved, post.modified_gmt, tree( live ) ] );
		if ( key.length > 5000000 ) {
			reject( 'The open page is too large for a guarded apply.' );
		}
		return { saved, live, key };
	}

	async function sha256( value ) {
		const bytes = new TextEncoder().encode( value );
		const digest = await window.crypto.subtle.digest( 'SHA-256', bytes );
		return Array.from( new Uint8Array( digest ), ( byte ) => byte.toString( 16 ).padStart( 2, '0' ) ).join( '' );
	}

	async function getJson( url ) {
		const response = await fetch( url, { credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': boot.nonce } } );
		if ( ! response.ok ) {
			reject( 'The reviewed draft or page is no longer available.' );
		}
		return response.json();
	}

	function serialized( blocks ) {
		return blocks.map( ( block ) => wp.blocks.serialize( block ) );
	}

	function draftRestUrl( postId ) {
		const url = new URL( boot.pageRest );
		if ( url.searchParams.has( 'rest_route' ) ) {
			url.searchParams.set( 'rest_route', url.searchParams.get( 'rest_route' ) + postId );
		} else {
			url.pathname += postId;
		}
		url.searchParams.set( 'context', 'edit' );
		url.searchParams.set( '_fields', 'id,content' );
		return url.toString();
	}

	function liveRestUrl( operationId ) {
		const url = new URL( boot.liveRest );
		if ( url.searchParams.has( 'rest_route' ) ) {
			url.searchParams.set( 'rest_route', url.searchParams.get( 'rest_route' ) + operationId );
		} else {
			url.pathname += encodeURIComponent( operationId );
		}
		return url.toString();
	}

	function allRegistered( blocks ) {
		return blocks.every( ( block ) => block.name && wp.blocks.getBlockType( block.name ) && allRegistered( block.innerBlocks || [] ) );
	}

	function singleChange( before, after ) {
		let index = 0;
		while ( index < before.length && index < after.length && before[ index ] === after[ index ] ) {
			index++;
		}
		if ( before.length === after.length && index < before.length && before.slice( index + 1 ).every( ( item, offset ) => item === after[ index + 1 + offset ] ) ) {
			return { kind: 'replace', index };
		}
		if ( after.length === before.length + 1 && before.slice( index ).every( ( item, offset ) => item === after[ index + 1 + offset ] ) ) {
			return { kind: 'insert', index };
		}
		reject( 'This draft changes more than one top-level block. Keep working in the private draft.' );
	}

	async function apply( operationId ) {
		if ( armedPermit ) {
			reject( 'A reviewed live edit is already applied in this editor.' );
		}
		const initial = editorSnapshot();
		const live = await getJson( liveRestUrl( operationId ) );
		const ticket = live.ticket;
		if ( live.operation_id !== operationId || live.state !== 'unknown' || ! ticket || ticket.builder !== 'gutenberg' || ticket.source_page_id !== boot.pageId || ticket.draft_operation_id !== boot.operationId || ticket.editor_binding_id !== capturedBinding?.id || ticket.directory_id !== capturedBinding.directoryId ) {
			reject( 'The separately approved live ticket is no longer available for this editor.' );
		}
		const reviewed = await getJson( boot.endpoint );
		const permit = reviewed.live_apply;
		if ( reviewed.operation_id !== ticket.draft_operation_id || reviewed.reviewed_content_current !== true || ! permit || permit.source_page_id !== boot.pageId || permit.directory_id !== ticket.directory_id ) {
			reject( 'The draft or source page changed after approval.' );
		}
		const candidate = await getJson( draftRestUrl( reviewed.draft_post_id ) );
		const raw = candidate?.content?.raw;
		if ( candidate.id !== reviewed.draft_post_id || typeof raw !== 'string' || raw.length > 5000000 || await sha256( raw ) !== permit.candidate_hash || await sha256( initial.saved ) !== permit.source_hash ) {
			reject( 'The reviewed draft or saved page bytes no longer match approval.' );
		}
		const savedBlocks = wp.blocks.parse( initial.saved );
		const candidateBlocks = wp.blocks.parse( raw );
		if ( ! allRegistered( candidateBlocks ) || JSON.stringify( serialized( initial.live ) ) !== JSON.stringify( serialized( savedBlocks ) ) ) {
			reject( 'The current editor tree differs from the saved source or contains unavailable blocks.' );
		}
		const before = serialized( savedBlocks );
		const after = serialized( candidateBlocks );
		const change = singleChange( before, after );
		const latest = await getJson( boot.endpoint );
		if ( latest.reviewed_content_current !== true || JSON.stringify( latest.live_apply ) !== JSON.stringify( permit ) || editorSnapshot().key !== initial.key ) {
			reject( 'The page or approval changed while the draft was loading.' );
		}
		const dispatch = wp.data.dispatch( 'core/block-editor' );
		if ( change.kind === 'replace' ) {
			dispatch.replaceBlocks( initial.live[ change.index ].clientId, candidateBlocks[ change.index ] );
		} else {
			dispatch.insertBlocks( candidateBlocks[ change.index ], change.index );
		}
		const resulting = wp.data.select( 'core/block-editor' ).getBlocks();
		const actual = serialized( resulting );
		if ( JSON.stringify( actual ) !== JSON.stringify( after ) ) {
			reject( 'The editor did not settle on the reviewed block tree. Inspect the page and use Undo if needed.' );
		}
		const offset = change.kind === 'insert' ? 1 : 0;
		if ( initial.live.some( ( block, index ) => ( change.kind !== 'replace' || index !== change.index ) && ( resulting[ index + ( index >= change.index ? offset : 0 ) ]?.clientId !== block.clientId ) ) ) {
			reject( 'A protected sibling changed identity. Inspect the page and use Undo if needed.' );
		}
		armedPermit = { operationId, beforeTree: JSON.stringify( before ), candidateTree: JSON.stringify( after ), phase: 'candidate' };
		wp.data.dispatch( 'core/editor' ).lockPostAutosaving( autosaveLock );
		return text( 'Applied in this editor. Review the page, then save it. The reviewed save will refuse a changed source or candidate. WordPress Undo can revert this one-block edit.' );
	}

	async function captureForReview(operationId) {
		if (typeof operationId !== 'string' || !/^[A-Za-z0-9_.:-]{1,128}$/.test(operationId)) {
			reject('The reviewed draft identity is unavailable.');
		}
		const initial = editorSnapshot();
		const url = new URL(boot.draftRest);
		if (url.searchParams.has('rest_route')) {
			url.searchParams.set('rest_route', url.searchParams.get('rest_route') + operationId);
		} else { url.pathname += encodeURIComponent(operationId); }
		const reviewed = await getJson(url.toString());
		if (reviewed.operation_id !== operationId || reviewed.reviewed_content_current !== true || !reviewed.live_apply || reviewed.live_apply.source_page_id !== boot.pageId || !Number.isInteger(reviewed.live_apply.directory_id)) {
			reject('The reviewed draft is no longer current for this source page.');
		}
		const capture = await window.directoristAIGutenbergCapture?.capture();
		if (!capture?.bindingId || editorSnapshot().key !== initial.key) { reject('The open editor changed while its binding was captured.'); }
		boot.operationId = operationId;
		boot.endpoint = url.toString();
		capturedBinding = { id: capture.bindingId, sourceId: boot.pageId, directoryId: reviewed.live_apply.directory_id };
		return { bindingId: capture.bindingId, sourceId: boot.pageId, directoryId: capturedBinding.directoryId };
	}

	// The native assistant requests a separately approved ticket; no dashboard popup.
	window.directoristAIGutenbergLive = Object.freeze({ capture: captureForReview, apply });
}() );
