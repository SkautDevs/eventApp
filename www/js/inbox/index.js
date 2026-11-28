/**
 * The Zprávy view of Informace (/informace/zpravy): the two views (views.js), and the
 * unsent message as a draft in localStorage, one per event. The form itself is a plain POST
 * (shell/write.js sends it). Typing saves the draft; the form is filled from it when its
 * screen is wired, but only a form the server rendered empty, so the echo of a refused send
 * (422, 429) wins. The landing of a confirmed send, the Zprávy view with SENT_HASH, forgets
 * it. Informace is one of the loader's screens: each [data-info-root] is wired once
 * (data-info-ready), at evaluation for the document's own screen and on screen:shown for
 * one the loader inserted, the warm-up's hidden copy included.
 *
 * The draft names its owner, the participant id of the push-identity meta, because a
 * logout leaves localStorage as it is and the next reader of the browser must not get the
 * text and contact of the last one: any page whose identity is not the owner's, a logged
 * out one included, removes it when this module evaluates. A login or a logout is a full
 * navigation, so it is seen, and the identity of a document never changes.
 */

import {onScreenShown} from '../events.js';
import {setupViews} from './views.js';

/** The hash of the landing on the Zprávy view after a confirmed send (Module/Inbox/SendAction.php). */
export const SENT_HASH = '#odeslano';

const EMPTY = {owner: '', kind: '', body: '', contact: ''};

/** The storage key of an event's draft: 'inboxDraft:' + base. */
export function draftKey(base) {
	return 'inboxDraft:' + base;
}

const text = value => (typeof value === 'string' ? value : '');

/**
 * {owner, kind, body, contact} of strings, or null for nothing stored, broken JSON, a
 * throwing storage or a draft without an owner.
 */
export function readDraft(storage, key) {
	let draft;
	try {
		draft = JSON.parse(storage.getItem(key));
	} catch {
		return null;
	}
	if (!draft || typeof draft !== 'object' || Array.isArray(draft) || text(draft.owner) === '') {
		return null;
	}
	return {owner: draft.owner, kind: text(draft.kind), body: text(draft.body), contact: text(draft.contact)};
}

/** Stores the draft; removes the key when every field is empty or there is no owner; never throws. */
export function writeDraft(storage, key, draft) {
	try {
		if (shouldRestore(draft) || draft.owner === '') {
			storage.removeItem(key);
		} else {
			storage.setItem(key, JSON.stringify({owner: draft.owner, kind: draft.kind, body: draft.body, contact: draft.contact}));
		}
	} catch {
		// private mode, a full or a blocked storage: the form works without the draft
	}
}

/** Whether a stored draft (readDraft's answer) belongs to the page's reader: never for a logged-out page. */
export function keepDraft(draft, identity) {
	return draft !== null && identity !== '' && draft.owner === identity;
}

/** True only when all three fields of the rendered form are empty. */
export function shouldRestore(fields) {
	return fields.kind === '' && fields.body === '' && fields.contact === '';
}

// window.localStorage itself throws where site data is blocked
function localStore() {
	try {
		return window.localStorage;
	} catch {
		return null;
	}
}

// for kind, the checked radio's value, or '' with none checked
function fieldsOf(form) {
	const kind = form.querySelector('input[name="kind"]:checked');
	return {
		kind: kind ? kind.value : '',
		body: form.elements.body ? form.elements.body.value : '',
		contact: form.elements.contact ? form.elements.contact.value : '',
	};
}

function fill(form, draft) {
	form.querySelectorAll('input[name="kind"]').forEach(radio => {
		radio.checked = radio.value === draft.kind;
	});
	if (form.elements.body) {
		form.elements.body.value = draft.body;
	}
	if (form.elements.contact) {
		form.elements.contact.value = draft.contact;
	}
}

/**
 * Makes what the form holds the reader's own: a value set from a script marks its field
 * dirty, as typing does, so a background morph to the server's empty form (after a push)
 * writes the textarea's text and the inputs' value and checked attributes without reaching
 * the values. Without it the echo of a refused send (422, 429), which is rendered and not
 * typed, would empty itself on the next revalidation.
 */
function adopt(form) {
	fill(form, fieldsOf(form));
}

/**
 * Fills the form of `root` from the reader's draft when the server rendered it empty, adopts
 * what it then holds, and saves the draft on every input and change. The listeners sit on
 * the root, which the morph never replaces.
 */
export function setupDraft(root, storage, key, identity) {
	const form = root.querySelector('form[data-inbox-form]');
	if (!form) {
		return;
	}
	const draft = storage ? readDraft(storage, key) : null;
	if (keepDraft(draft, identity) && shouldRestore(fieldsOf(form))) {
		fill(form, draft);
	} else {
		adopt(form);
	}
	if (!storage) {
		return;
	}
	const save = event => {
		const target = event.target.closest && event.target.closest('form[data-inbox-form]');
		if (target) {
			writeDraft(storage, key, {owner: identity, ...fieldsOf(target)});
		}
	};
	root.addEventListener('input', save);
	root.addEventListener('change', save);
}

// the page's storage, its event's key and its reader, read once: none of them changes
// without a full navigation
const pageStorage = localStore();
const base = document.querySelector('meta[name="event-base"]');
const pageKey = draftKey(base ? base.getAttribute('content') : '/');
const who = document.querySelector('meta[name="push-identity"]');
const pageIdentity = who ? who.getAttribute('content') || '' : '';

/**
 * The landing of a confirmed send: the draft is removed and the hash taken away, as
 * shell/toast.js does with #offline, so a reload does not read it again; the loader's
 * state object is passed through.
 */
function takeSent() {
	if (location.hash !== SENT_HASH) {
		return;
	}
	if (pageStorage) {
		writeDraft(pageStorage, pageKey, EMPTY);
	}
	history.replaceState(history.state, '', location.pathname + location.search);
}

function init(root) {
	if (!root || root.dataset.infoReady === '1') {
		return;
	}
	root.dataset.infoReady = '1';
	takeSent();
	setupViews(root);
	setupDraft(root, pageStorage, pageKey, pageIdentity);
}

function initAll(scope) {
	scope.querySelectorAll('[data-info-root]').forEach(init);
}

// the owner rule, on every page
if (pageStorage && !keepDraft(readDraft(pageStorage, pageKey), pageIdentity)) {
	writeDraft(pageStorage, pageKey, EMPTY);
}
initAll(document);
onScreenShown(scope => {
	takeSent();
	initAll(scope);
});
