/**
 * Napiš organizátorům (/zpravy): the unsent message as a draft in localStorage, one per
 * event. The form itself is a plain POST (shell/write.js sends it). Typing saves the draft;
 * a later visit fills the form from it, but only a form the server rendered empty, so the
 * echo of a refused send (422, 429) wins. The landing of a confirmed send, Informace with
 * SENT_HASH, forgets it. /zpravy is no tab, so it is always a full navigation and
 * DOMContentLoaded is where this starts, on every page, since every module's script loads
 * on every page.
 *
 * The draft names its owner, the participant id of the push-identity meta, because a
 * logout leaves localStorage as it is and the next reader of the browser must not get the
 * text and contact of the last one: any page whose identity is not the owner's, a logged
 * out one included, removes it. A login or a logout is a full navigation, so it is seen.
 */

/** The hash of the landing on Informace after a confirmed send (Module/Inbox/SendAction.php). */
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

document.addEventListener('DOMContentLoaded', () => {
	const storage = localStore();
	const meta = document.querySelector('meta[name="event-base"]');
	const key = draftKey(meta ? meta.getAttribute('content') : '/');
	const who = document.querySelector('meta[name="push-identity"]');
	const identity = who ? who.getAttribute('content') || '' : '';

	// the hash is read once and taken away, as shell/toast.js does with #offline, so a
	// reload does not read it again; the loader's state object is passed through
	if (location.hash === SENT_HASH) {
		if (storage) {
			writeDraft(storage, key, EMPTY);
		}
		history.replaceState(history.state, '', location.pathname + location.search);
	}

	if (!storage) {
		return;
	}
	const draft = readDraft(storage, key);
	const kept = keepDraft(draft, identity);
	if (!kept) {
		writeDraft(storage, key, EMPTY);
	}
	const form = document.querySelector('form[data-inbox-form]');
	if (!form) {
		return;
	}
	if (kept && shouldRestore(fieldsOf(form))) {
		fill(form, draft);
	}
	const save = () => {
		writeDraft(storage, key, {owner: identity, ...fieldsOf(form)});
	};
	form.addEventListener('input', save);
	form.addEventListener('change', save);
});
