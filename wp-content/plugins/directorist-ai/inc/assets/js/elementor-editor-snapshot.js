/* global elementor */
/* One initial native Elementor document. No saved-tree fallback or in-app document switch. */
(function () {
	'use strict';

	const boot = window.directoristAIElementorBoot;
	if (!boot || boot.v !== 2 || typeof boot.session !== 'string') {
		return;
	}
	let generation = boot.generation;
	let initialDocument = null;
	let unavailable = false;
	let reloadRequired = false;
	let binding = null;
	let pendingSave = null;
	let capturing = false;
	let installed = false;

	function currentDocument() {
		if (!window.elementor || !elementor.documents || typeof elementor.documents.getCurrent !== 'function') {
			return null;
		}
		return elementor.documents.getCurrent();
	}

	function documentIsInitial(document) {
		return !unavailable && document && document === initialDocument && currentDocument() === document &&
			document.config && Number(document.config.id) === boot.main && document.config.type === boot.document_type &&
			document.container && document.container.model && document.container.model.get('elements');
	}

	function invalidate(requireReload) {
		binding = null;
		if (requireReload) {
			unavailable = true;
			reloadRequired = true;
		}
	}

	async function request(path, payload, keepalive) {
		const response = await fetch(boot.endpoint + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/json', 'X-WP-Nonce': boot.nonce},
			body: JSON.stringify(payload),
			keepalive: !!keepalive
		});
		if (!response.ok) {
			throw new Error('Elementor editor evidence unavailable');
		}
		return response.json();
	}

	function close() {
		const old = generation;
		invalidate(true);
		request('/editor-close', {v: 2, session: boot.session, generation: old}, true).catch(function () {});
	}

	function fullTree(document) {
		if (!documentIsInitial(document)) {
			throw new Error('Initial Elementor document unavailable');
		}
		const collection = document.container.model.get('elements');
		if (!collection || typeof collection.toJSON !== 'function') {
			throw new Error('Native Elementor serializer unavailable');
		}
		const tree = collection.toJSON({remove: ['default', 'editSettings', 'defaultEditSettings']});
		if (!Array.isArray(tree)) {
			throw new Error('Native Elementor tree unavailable');
		}
		const wire = JSON.stringify(tree);
		if (typeof wire !== 'string' || wire.length > 2097152) {
			throw new Error('Native Elementor tree exceeds bound');
		}
		return {tree: tree, wire: wire};
	}

	function namedControls(model, settings) {
		const native = elementor.getElementControls(model);
		if (!native || typeof native !== 'object' || Array.isArray(native)) {
			throw new Error('Native Elementor controls unavailable');
		}
		const selected = {};
		Object.keys(settings || {}).forEach(function (key) {
			if (Object.prototype.hasOwnProperty.call(native, key)) {
				const control = native[key];
				if (!control || typeof control.type !== 'string') {
					throw new Error('Native Elementor control type unavailable');
				}
				selected[key] = {type: control.type};
			}
		});
		return selected;
	}

	function observedElements(document) {
		const found = new Map();
		let nodes = 0;
		function visit(collection, depth) {
			if (depth > 40 || !collection || typeof collection.each !== 'function') {
				throw new Error('Native Elementor collection unavailable');
			}
			collection.each(function (model) {
				if (++nodes > 1900 || !model || typeof model.get !== 'function') {
					throw new Error('Native Elementor tree exceeds bound');
				}
				const elType = model.get('elType');
				const identity = elType === 'widget' ? model.get('widgetType') : elType;
				const kind = elType === 'widget' ? 'widget' : 'element';
				if (typeof identity !== 'string' || !/^[A-Za-z0-9][A-Za-z0-9._:\/-]{0,255}$/.test(identity)) {
					throw new Error('Native Elementor identity unavailable');
				}
				const settingsModel = model.get('settings');
				const settings = settingsModel && typeof settingsModel.toJSON === 'function' ? settingsModel.toJSON({remove: ['default']}) : {};
				const controls = namedControls(model, settings);
				const previous = found.get(identity);
				if (previous && previous.kind !== kind) {
					throw new Error('Native Elementor identity is ambiguous');
				}
				const entry = previous || {id: identity, kind: kind, controls: {}, child_types: []};
				Object.keys(controls).forEach(function (key) {
					if (entry.controls[key] && entry.controls[key].type !== controls[key].type) {
						throw new Error('Native Elementor control is ambiguous');
					}
					entry.controls[key] = controls[key];
				});
				found.set(identity, entry);
				const children = model.get('elements');
				if (children) {
					visit(children, depth + 1);
				}
			});
		}
		visit(document.container.model.get('elements'), 0);
		if (found.size > 150) {
			throw new Error('Native Elementor identities exceed bound');
		}
		return Array.from(found.values()).sort(function (a, b) {return a.id.localeCompare(b.id);});
	}

	function allowedElements(document) {
		const names = new Set();
		[elementor.config && elementor.config.elements, document.config && document.config.widgets].forEach(function (map) {
			if (map && typeof map === 'object' && !Array.isArray(map)) {
				Object.keys(map).forEach(function (key) {names.add(key);});
			}
		});
		if (names.size > 500) {
			throw new Error('Native Elementor allowlist exceeds bound');
		}
		return Array.from(names).sort();
	}

	function breakpoints() {
		const native = elementor.config && elementor.config.responsive && elementor.config.responsive.breakpoints;
		if (!native || typeof native !== 'object' || Array.isArray(native)) {
			throw new Error('Native Elementor breakpoints unavailable');
		}
		const ids = Object.keys(native);
		if (ids.length > 20) {
			throw new Error('Native Elementor breakpoints exceed bound');
		}
		return ids.map(function (id) {
			const entry = native[id];
			if (!entry || typeof entry !== 'object' || typeof entry.value === 'undefined') {
				throw new Error('Native Elementor breakpoint unavailable');
			}
			const result = {id: id, value: entry.value};
			['direction', 'label', 'is_enabled'].forEach(function (field) {
				if (Object.prototype.hasOwnProperty.call(entry, field)) {
					result[field] = entry[field];
				}
			});
			return result;
		});
	}

	async function capture(directoryId = boot.directory_id) {
		const document = currentDocument();
		if (!documentIsInitial(document) || reloadRequired || pendingSave || capturing || (directoryId !== null && (!Number.isInteger(directoryId) || directoryId < 1))) {
			return null;
		}
		capturing = true;
		invalidate(false);
		let first;
		try {
			first = fullTree(document);
			const elements = observedElements(document);
			const allowed = allowedElements(document);
			const nativeBreakpoints = breakpoints();
			const challenge = await request('/editor-challenge', {v: 2, session: boot.session, generation: generation, directory_id: directoryId});
			if (!documentIsInitial(document) || pendingSave || fullTree(document).wire !== first.wire || challenge.session !== boot.session || !Number.isInteger(challenge.generation)) {
				throw new Error('Native Elementor document changed');
			}
			generation = challenge.generation;
			const response = await request('/editor-snapshot', {
				v: 2, session: boot.session, generation: generation, binding_id: challenge.binding_id,
				nonce: challenge.nonce, contract_revision: challenge.contract_revision,
				document_type: challenge.document_type, allowed_elements: allowed,
				elements: elements, breakpoints: nativeBreakpoints, document_elements: first.tree
			});
			if (!documentIsInitial(document) || pendingSave || fullTree(document).wire !== first.wire || response.binding_id !== challenge.binding_id || response.generation !== generation) {
				throw new Error('Native Elementor document changed');
			}
			binding = {binding_id: response.binding_id, fingerprint: response.fingerprint, generation: generation, directory_id: directoryId, wire: first.wire};
			capturing = false;
			return current();
		} catch (error) {
			close();
			return null;
		} finally {
			capturing = false;
		}
	}

	function current() {
		if (!binding || reloadRequired || pendingSave || capturing || !documentIsInitial(currentDocument())) {
			return null;
		}
		try {
			if (fullTree(initialDocument).wire !== binding.wire) {
				invalidate(false);
				return null;
			}
			return {binding_id: binding.binding_id, fingerprint: binding.fingerprint, generation: binding.generation, directory_id: binding.directory_id};
		} catch (error) {
			invalidate(true);
			return null;
		}
	}

	function onDocumentLoaded(document) {
		if (unavailable) {
			return;
		}
		if (!initialDocument) {
			if (document && document.config && Number(document.config.id) === boot.main && document.config.type === boot.document_type) {
				initialDocument = document;
			} else {
				close();
			}
		} else if (document !== initialDocument) {
			close();
		}
	}

	function onBeforeSave(args) {
		invalidate(false);
		const document = args && args.document || currentDocument();
		if (!documentIsInitial(document) || pendingSave) {
			close();
			return;
		}
		pendingSave = {document: document, generation: generation, status: args && args.status || 'draft'};
	}

	function onAutosave(data) {
		const pending = pendingSave;
		pendingSave = null;
		const receipt = data && data.directorist_ai_editor_receipt;
		if (!pending || pending.status !== 'autosave' || !documentIsInitial(pending.document) || !receipt || receipt.v !== 2 || receipt.session !== boot.session || receipt.epoch !== boot.epoch || receipt.main !== boot.main || receipt.source !== boot.source || !Number.isInteger(receipt.generation) || receipt.generation <= pending.generation) {
			close();
			return;
		}
		generation = receipt.generation;
	}

	function install() {
		if (installed || !window.elementor || !elementor.documents || !elementor.saver || typeof elementor.on !== 'function') {
			return;
		}
		installed = true;
		elementor.on('document:loaded', onDocumentLoaded);
		elementor.on('document:unloaded', function (document) {
			if (document === initialDocument) {close();}
		});
		elementor.saver.on('before:save', onBeforeSave);
		elementor.saver.on('after:save:autosave', onAutosave);
		elementor.saver.on('after:save:draft after:save:publish after:save:private after:save:pending after:saveError', function () {close();});
		const already = currentDocument();
		if (already) {onDocumentLoaded(already);}
	}

	window.directoristAIElementorEditor = Object.freeze({capture: capture, current: current, needsReload: function () {return reloadRequired;}});
	window.addEventListener('pagehide', close, {once: true});
	install();
	window.addEventListener('DOMContentLoaded', install, {once: true});
	window.addEventListener('load', install, {once: true});
}());
