/* global directoristAIDiviEditorBoot */
(function () {
	'use strict';

	const boot = window.directoristAIDiviEditorBoot;
	if (!boot || !window.divi || !window.divi.data || !window.fetch) {
		return;
	}

	let lastCapture = null;

	function plain(value) {
		return JSON.parse(JSON.stringify(value));
	}

	function serializedNames(modules, names, depth = 0) {
		if (!Array.isArray(modules) || depth > 12) {
			throw new Error('Divi source/view state is incomplete.');
		}
		for (const module of modules) {
			if (!module || typeof module.name !== 'string') {
				throw new Error('Divi source/view module is incomplete.');
			}
			names.add(module.name);
			serializedNames(module.children || [], names, depth + 1);
		}
	}

	function editorFacts() {
		const store = window.divi.data.select('divi/edit-post');
		const modalStore = window.divi.data.select('divi/modal-library');
		if (!store || typeof store.getContent !== 'function' || typeof store.getAllModuleIds !== 'function' || !modalStore) {
			throw new Error('Divi editor state is unavailable.');
		}
		const content = store.getContent();
		const ids = store.getAllModuleIds();
		if (!content || !Array.isArray(ids) || ids.length > 2000) {
			throw new Error('Divi editor state exceeds the capture boundary.');
		}
		const flat = ids.map((id) => {
			const node = content[id];
			if (!node || !Array.isArray(node.children) || !node.props || typeof node.name !== 'string') {
				throw new Error('Divi editor tree is incomplete.');
			}
			return {
				id,
				name: node.name,
				parent_id: node.parent === 'root' ? null : node.parent,
				children_ids: [...node.children],
				attrs: plain(node.props.attrs || {}),
			};
		});
		const cards = flat.filter((node) => node.name === 'directorist/listings-card');
		const modal = modalStore.getModal('divi/module');
		const activeOwner = modal && modal.isActive && cards.some((card) => card.id === modal.owner) ? modal.owner : null;
		const states = [];
		const cardKeys = [];
		for (const card of cards) {
			const attrs = card.attrs;
			const values = attrs.state_by_source_view?.innerContent?.desktop?.value || {};
			const source = attrs.source?.innerContent?.desktop?.value || values._source;
			const view = attrs.view?.innerContent?.desktop?.value || values._view;
			if (typeof source === 'string' && typeof view === 'string') {
				cardKeys.push({ id: card.id, key: source + '|' + view });
			}
			for (const [key, current] of Object.entries(values)) {
				if (!/^[A-Za-z0-9._-]{1,128}\|(grid|list|map|single)$/.test(key) || !current || typeof current !== 'object') {
					continue;
				}
				const [sourceSlug, stateView] = key.split('|');
				const { childrenIds, ...serializedState } = current;
				states.push({
					owner_id: card.id,
					key,
					source_slug: sourceSlug,
					view: stateView,
					children_ids: Array.isArray(childrenIds) ? childrenIds : [],
					serialized_state: serializedState,
				});
			}
		}
		const activeKey = activeOwner ? cardKeys.find((item) => item.id === activeOwner)?.key : null;
		const currentKey = activeKey || (cardKeys.length === 1 ? cardKeys[0].key : '');
		const nameSet = new Set(flat.map((node) => node.name));
		for (const state of states) {
			serializedNames(state.serialized_state.modules, nameSet);
		}
		const names = [...nameSet].sort();
		return {
			allowed_modules: names,
			flat_source: 'server_hash',
			graph_source: { flat_modules: flat },
			registry_source: 'server_registered',
			modal_state: { active: !!activeOwner, owner_id: activeOwner, source_view_key: activeOwner ? activeKey : null },
			source_view_key: currentKey,
			state_by_source_view: states,
			view_modes: ['desktop', 'tablet', 'phone'],
		};
	}

	function signature(facts) {
		return JSON.stringify(facts);
	}

	async function request(path, payload) {
		const response = await window.fetch(boot.endpoint + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': boot.nonce },
			body: JSON.stringify(payload),
		});
		const data = await response.json();
		if (!response.ok) {
			const error = new Error(data.message || 'Divi editor capture failed.');
			error.code = data.code || 'editor_capture_failed';
			throw error;
		}
		return data;
	}

	async function capture(options = {}) {
		lastCapture = null;
		const kind = options.documentKind || boot.defaultKind;
		if (typeof kind !== 'string' || !kind || (options.documentRef && options.documentRef !== boot.documentRef)) {
			throw new Error('Divi document identity is unavailable.');
		}
		const directoryId = options.directoryId == null ? null : options.directoryId;
		const facts = editorFacts();
		const currentSignature = signature(facts);
		const challenge = await request('editor-challenge', {
			document_kind: kind,
			directory_id: directoryId,
			document_ref: boot.documentRef,
		});
		if (signature(editorFacts()) !== currentSignature) {
			throw new Error('Divi editor changed during capture.');
		}
		const result = await request('editor-snapshot', {
			...facts,
			binding_id: challenge.binding_id,
			contract_revision: challenge.contract_revision,
			document_type: challenge.document_type,
			nonce: challenge.nonce,
		});
		if (signature(editorFacts()) !== currentSignature) {
			throw new Error('Divi editor changed during capture.');
		}
		lastCapture = { signature: currentSignature, result };
		if (!(await current())) {
			throw new Error('Divi editor changed during capture.');
		}
		return result;
	}

	async function current() {
		if (!lastCapture) {
			return null;
		}
		try {
			const captured = lastCapture;
			if (signature(editorFacts()) === captured.signature) {
				await request('editor-current', {
					binding_id: captured.result.binding_id,
					fingerprint: captured.result.fingerprint,
				});
				if (lastCapture === captured && signature(editorFacts()) === captured.signature) {
					return captured.result;
				}
			}
		} catch (error) {
			// A detached store or changed server revision cannot carry a binding.
		}
		lastCapture = null;
		return null;
	}

	window.directoristAIDiviEditor = Object.freeze({ capture, current });
})();
