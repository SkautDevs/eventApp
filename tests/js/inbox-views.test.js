import {emitScreenMorphed} from '/js/events.js';
import {draftKey, setupDraft} from '/js/inbox/index.js';
import {infoViewFromPath, infoViewPath, setupViews} from '/js/inbox/views.js';
import {assert, test} from './harness.js';

test('infoViewFromPath reads the two addresses of Informace', () => {
	assert.equal(infoViewFromPath('/obrok19/informace', '/obrok19/informace'), 'info');
	assert.equal(infoViewFromPath('/obrok19/informace/zpravy', '/obrok19/informace'), 'inbox');
	assert.equal(infoViewFromPath('/obrok19/mapa', '/obrok19/informace'), null);
	assert.equal(infoViewFromPath('/obrok19/zpravy', '/obrok19/informace'), null, "the form's first address is gone");
	assert.equal(infoViewFromPath('/obrok19/informace', ''), null, 'a root in no screen');
});

test('infoViewPath is the inverse of infoViewFromPath', () => {
	assert.equal(infoViewPath('info', '/obrok19/informace'), '/obrok19/informace');
	assert.equal(infoViewPath('inbox', '/obrok19/informace'), '/obrok19/informace/zpravy');
	['info', 'inbox'].forEach(view => {
		assert.equal(infoViewFromPath(infoViewPath(view, '/o/informace'), '/o/informace'), view);
	});
});

/** Informace's two panes and pills, in a screen whose path is `screen`, root on `view`. */
function informace(screen, view) {
	const host = document.createElement('div');
	host.innerHTML =
		'<section data-screen="' +
		screen +
		'"><div class="infoview" data-info-root data-info-ready="1" data-view="' +
		view +
		'">' +
		'<div id="info-test-info" role="tabpanel"></div><div id="info-test-inbox" role="tabpanel"></div>' +
		'<div role="tablist"><a role="tab" href="#info-test-info" data-info-view="info">Informace</a>' +
		'<a role="tab" href="#info-test-inbox" data-info-view="inbox">Zprávy</a></div></div></section>';
	document.body.appendChild(host);

	return host;
}

test('a pill is a link: a plain click or Space switches the view, a modified click is left to the browser', () => {
	// a root in no screen of this page's path: nothing is pushed, the view switches all the same
	const host = informace('', 'info');
	// after the root's listener: records what it left, then keeps the test page where it is
	const prevented = [];
	const record = event => {
		prevented.push(event.defaultPrevented);
		event.preventDefault();
	};
	document.addEventListener('click', record);
	try {
		const root = host.querySelector('[data-info-root]');
		setupViews(root);
		const [info, inbox] = Array.from(root.querySelectorAll('[role="tab"]'));
		assert.equal(info.getAttribute('aria-selected'), 'true');
		assert.equal(info.getAttribute('aria-current'), 'page');
		assert.equal(info.tabIndex, 0);
		assert.equal(inbox.tabIndex, -1, 'the roving tabindex is put on at wiring');
		const address = location.href;

		inbox.dispatchEvent(new MouseEvent('click', {bubbles: true, cancelable: true, metaKey: true}));
		assert.equal(prevented.pop(), false);
		assert.equal(root.dataset.view, 'info');

		inbox.dispatchEvent(new MouseEvent('click', {bubbles: true, cancelable: true}));
		assert.equal(prevented.pop(), true);
		assert.equal(root.dataset.view, 'inbox');
		assert.equal(inbox.getAttribute('aria-selected'), 'true');
		assert.equal(inbox.getAttribute('aria-current'), 'page');
		assert.equal(inbox.tabIndex, 0);
		assert.equal(info.getAttribute('aria-selected'), 'false');
		assert.equal(info.hasAttribute('aria-current'), false);
		assert.equal(info.tabIndex, -1);
		assert.equal(location.href, address, 'a root in no screen has no address to push');

		const space = new KeyboardEvent('keydown', {key: ' ', bubbles: true, cancelable: true});
		info.dispatchEvent(space);
		assert.equal(space.defaultPrevented, true);
		assert.equal(prevented.pop(), true);
		assert.equal(root.dataset.view, 'info');

		const arrow = new KeyboardEvent('keydown', {key: 'ArrowRight', bubbles: true, cancelable: true});
		info.dispatchEvent(arrow);
		assert.equal(arrow.defaultPrevented, true);
		assert.equal(root.dataset.view, 'inbox');
		assert.equal(document.activeElement, inbox, 'the focus follows the arrow');
	} finally {
		document.removeEventListener('click', record);
		host.remove();
	}
});

test('the wiring reads the view from the address, not from the markup', () => {
	// a copy stored for the other address: the screen's own path is the Informace view
	const host = informace(location.pathname, 'inbox');
	try {
		const root = host.querySelector('[data-info-root]');
		setupViews(root);
		assert.equal(root.dataset.view, 'info');
		assert.equal(root.querySelector('[data-info-view="info"]').getAttribute('aria-current'), 'page');
	} finally {
		host.remove();
	}
});

test('a pill tap pushes its address once and keeps the query; the view on show pushes nothing', () => {
	const address = location.href;
	const screen = location.pathname;
	const host = informace(screen, 'info');
	history.replaceState(history.state, '', screen + '?x=1');
	const length = history.length;
	try {
		const root = host.querySelector('[data-info-root]');
		setupViews(root);
		const inbox = root.querySelector('[data-info-view="inbox"]');

		inbox.dispatchEvent(new MouseEvent('click', {bubbles: true, cancelable: true}));
		assert.equal(location.pathname, screen + '/zpravy');
		assert.equal(location.search, '?x=1');
		assert.deepEqual(history.state, {screen, infoView: 'inbox'});
		assert.equal(history.length, length + 1);

		inbox.dispatchEvent(new MouseEvent('click', {bubbles: true, cancelable: true}));
		assert.equal(history.length, length + 1, 'the view on show pushes nothing');
	} finally {
		history.replaceState(null, '', address);
		host.remove();
	}
});

test('a morph puts back the view the reader is on', () => {
	const host = informace('', 'info');
	try {
		const root = host.querySelector('[data-info-root]');
		setupViews(root);
		const inbox = root.querySelector('[data-info-view="inbox"]');
		inbox.click();
		// what a morph of a pill it replaced leaves: the server's state
		inbox.setAttribute('aria-selected', 'false');
		inbox.removeAttribute('aria-current');
		emitScreenMorphed(host);
		assert.equal(root.dataset.view, 'inbox');
		assert.equal(inbox.getAttribute('aria-selected'), 'true');
		assert.equal(inbox.getAttribute('aria-current'), 'page');
	} finally {
		host.remove();
	}
});

/** A Storage stand-in over a Map. */
function memoryStorage() {
	const items = new Map();
	return {
		items,
		getItem: key => (items.has(key) ? items.get(key) : null),
		setItem: (key, value) => items.set(key, String(value)),
		removeItem: key => items.delete(key),
	};
}

function formRoot(body) {
	const host = document.createElement('div');
	host.innerHTML =
		'<div data-info-root><form data-inbox-form>' +
		'<input type="radio" name="kind" value="help"><input type="radio" name="kind" value="idea">' +
		'<textarea name="body">' +
		body +
		'</textarea><input type="text" name="contact"></form></div>';
	document.body.appendChild(host);

	return host;
}

test("setupDraft fills an empty form with its owner's draft and saves what is typed", () => {
	const storage = memoryStorage();
	const key = draftKey('/o/');
	storage.setItem(key, JSON.stringify({owner: '7', kind: 'idea', body: 'Rozepsané', contact: 'k@example.cz'}));
	const host = formRoot('');
	try {
		const form = host.querySelector('form');
		setupDraft(host.firstChild, storage, key, '7');
		assert.equal(form.elements.body.value, 'Rozepsané');
		assert.equal(form.elements.contact.value, 'k@example.cz');
		assert.equal(form.querySelector('input[value="idea"]').checked, true);

		form.elements.body.value = 'Jiný text';
		form.elements.body.dispatchEvent(new Event('input', {bubbles: true}));
		assert.equal(JSON.parse(storage.getItem(key)).body, 'Jiný text');
	} finally {
		host.remove();
	}
});

test('setupDraft leaves the echo of a refused send as the server rendered it', () => {
	const storage = memoryStorage();
	const key = draftKey('/o/');
	storage.setItem(key, JSON.stringify({owner: '7', kind: 'idea', body: 'Starý koncept', contact: ''}));
	const host = formRoot('Odmítnutý text');
	try {
		setupDraft(host.firstChild, storage, key, '7');
		assert.equal(host.querySelector('textarea').value, 'Odmítnutý text');
	} finally {
		host.remove();
	}
});

test('setupDraft puts back no draft of another reader', () => {
	const storage = memoryStorage();
	const key = draftKey('/o/');
	storage.setItem(key, JSON.stringify({owner: '8', kind: 'idea', body: 'Cizí', contact: ''}));
	const host = formRoot('');
	try {
		setupDraft(host.firstChild, storage, key, '7');
		assert.equal(host.querySelector('textarea').value, '');
	} finally {
		host.remove();
	}
});

test("the echo of a refused send is the reader's once wired: the server's writes no longer reach it", () => {
	const host = document.createElement('div');
	host.innerHTML =
		'<div data-info-root><form data-inbox-form>' +
		'<input type="radio" name="kind" value="help" checked><input type="radio" name="kind" value="idea">' +
		'<textarea name="body">Odmítnutý text</textarea><input type="text" name="contact" value="k@example.cz"></form></div>';
	document.body.appendChild(host);
	try {
		// no storage at all: the echo is kept all the same
		setupDraft(host.firstChild, null, draftKey('/o/'), '7');
		// what a background morph to an empty form writes: the text node, the attributes
		const form = host.querySelector('form');
		form.elements.body.textContent = '';
		form.elements.contact.setAttribute('value', '');
		form.querySelector('input[value="help"]').removeAttribute('checked');
		assert.equal(form.elements.body.value, 'Odmítnutý text');
		assert.equal(form.elements.contact.value, 'k@example.cz');
		assert.equal(form.querySelector('input[value="help"]').checked, true);
	} finally {
		host.remove();
	}
});
