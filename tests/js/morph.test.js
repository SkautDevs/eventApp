import {captureFocus, keyOf, kindOf, morphChildren, ownsIframe, patchAttributes, restoreFocus} from '/js/loader/morph.js';
import {assert, test} from './harness.js';

const tree = html => {
	const holder = document.createElement('div');
	holder.innerHTML = html;
	return holder;
};

const texts = node => Array.from(node.childNodes, child => child.textContent);

test('keyOf reads the id, then data-key, and nothing off a text node', () => {
	const box = tree('<p id="a" data-key="k"></p><p data-key="k"></p><p></p>text');
	const [byId, byKey, none, text] = box.childNodes;
	assert.equal(keyOf(byId), 'a');
	assert.equal(keyOf(byKey), 'k');
	assert.equal(keyOf(none), null);
	assert.equal(keyOf(text), null);
});

test('kindOf tells elements apart by tag and other nodes by type', () => {
	const box = tree('<p></p><span></span>text<!--c-->');
	assert.deepEqual(Array.from(box.childNodes, kindOf), ['e:P', 'e:SPAN', 'n:3', 'n:8']);
});

test('ownsIframe is true for an iframe and for anything holding one', () => {
	const box = tree('<iframe></iframe><div><p><iframe></iframe></p></div><div></div>text');
	assert.deepEqual(Array.from(box.childNodes, ownsIframe), [true, true, false, false]);
});

test('children are matched on their id', () => {
	const from = tree('<div id="a">A</div><div id="b">B</div>');
	const [a, b] = from.children;
	morphChildren(from, tree('<div id="b">B2</div><div id="a">A</div>'));
	assert.ok(from.children[0] === b && from.children[1] === a, 'the same two nodes, reordered');
	assert.deepEqual(texts(from), ['B2', 'A']);
});

test('children are matched on data-key', () => {
	const from = tree('<p data-key="1">one</p><p data-key="2">two</p>');
	const [one, two] = from.children;
	morphChildren(from, tree('<p data-key="0">zero</p><p data-key="1">one</p><p data-key="2">two!</p>'));
	assert.deepEqual(texts(from), ['zero', 'one', 'two!']);
	assert.ok(from.children[1] === one && from.children[2] === two, 'an insertion moves nothing after it');
});

test('unkeyed children are matched by position among same-tag siblings', () => {
	const from = tree('<p>one</p><span>s</span><p>two</p>');
	const [one, span, two] = from.children;
	morphChildren(from, tree('<span>s</span><p>one</p><p>TWO</p>'));
	assert.ok(from.children[0] === span && from.children[1] === one && from.children[2] === two);
	assert.deepEqual(texts(from), ['s', 'one', 'TWO']);
});

test('a keyed fresh child of another tag is not matched to the old one', () => {
	const from = tree('<p id="x">p</p>');
	const old = from.firstChild;
	morphChildren(from, tree('<div id="x">div</div>'));
	assert.equal(from.childNodes.length, 1);
	assert.equal(from.firstChild.tagName, 'DIV');
	assert.ok(!old.isConnected && old.parentNode === null);
});

test('a subtree equal to its fresh counterpart is not touched', () => {
	const from = tree('<ul id="list"><li>a</li><li>b</li></ul><p id="other">old</p>');
	const list = from.firstChild;
	const item = list.firstChild;
	const observer = new MutationObserver(() => {});
	observer.observe(list, {subtree: true, childList: true, attributes: true, characterData: true});
	morphChildren(from, tree('<ul id="list"><li>a</li><li>b</li></ul><p id="other">new</p>'));
	const records = observer.takeRecords();
	observer.disconnect();
	assert.ok(from.firstChild === list && list.firstChild === item, 'the same nodes');
	assert.equal(records.length, 0, 'no mutation inside the equal subtree');
	assert.equal(from.lastChild.textContent, 'new');
});

test('an iframe is neither written to nor moved', () => {
	const from = tree('<div data-key="a">a</div><iframe src="about:blank#old" title="old"></iframe>');
	const iframe = from.querySelector('iframe');
	const observer = new MutationObserver(() => {});
	observer.observe(from, {subtree: true, childList: true, attributes: true});
	// a keyed sibling inserted before it, and its old neighbour now after it
	morphChildren(from, tree('<div data-key="n">n</div><iframe src="about:blank#new" title="new"></iframe><div data-key="a">a</div>'));
	const records = observer.takeRecords();
	observer.disconnect();
	assert.ok(from.querySelector('iframe') === iframe, 'the same iframe');
	assert.equal(iframe.getAttribute('src'), 'about:blank#old');
	assert.equal(iframe.getAttribute('title'), 'old');
	assert.equal(Array.prototype.indexOf.call(from.childNodes, iframe), 1, 'its index among its siblings is unchanged');
	assert.deepEqual(
		Array.from(from.childNodes, node => keyOf(node) || node.tagName),
		['n', 'IFRAME', 'a'],
	);
	assert.ok(!records.some(record => Array.prototype.includes.call(record.removedNodes, iframe) || record.target === iframe), 'never removed or written');
});

test('an element holding an iframe is not moved either', () => {
	const from = tree('<div data-key="a">a</div><div class="map"><iframe src="about:blank#map"></iframe></div>');
	const map = from.querySelector('.map');
	morphChildren(from, tree('<div data-key="n">n</div><div class="map"><iframe src="about:blank#map"></iframe></div><div data-key="a">a</div>'));
	assert.ok(from.childNodes[1] === map);
	assert.deepEqual(
		Array.from(from.childNodes, node => keyOf(node) || node.className),
		['n', 'map', 'a'],
	);
});

test('an attribute named in data-morph-keep survives a different value in the fresh markup', () => {
	const from = tree('<div id="pg" data-morph-keep="data-view" data-view="list" data-step="1"><p>x</p></div>');
	morphChildren(from, tree('<div id="pg" data-morph-keep="data-view" data-view="timeline" data-step="2"><p>y</p></div>'));
	const pg = from.firstChild;
	assert.equal(pg.getAttribute('data-view'), 'list');
	assert.equal(pg.getAttribute('data-step'), '2');
	assert.equal(pg.textContent, 'y');
});

test('patchAttributes writes what differs and leaves what the fresh markup does not carry', () => {
	const [from] = tree('<div class="old" style="--hour-width: 80px" data-pg-ready="1"></div>').children;
	const [to] = tree('<div class="new"></div>').children;
	patchAttributes(from, to);
	assert.equal(from.getAttribute('class'), 'new');
	assert.equal(from.getAttribute('style'), '--hour-width: 80px');
	assert.equal(from.getAttribute('data-pg-ready'), '1');
});

test('children the fresh markup no longer has are removed', () => {
	const from = tree('<p id="a">a</p><p id="b">b</p>text<span>s</span>');
	morphChildren(from, tree('<p id="b">b</p>'));
	assert.equal(from.innerHTML, '<p id="b">b</p>');
});

test('the caret of a focused input comes back', () => {
	const section = document.createElement('section');
	section.innerHTML = '<input value="hello world"><button type="button">b</button>';
	document.body.appendChild(section);
	try {
		const input = section.querySelector('input');
		input.focus();
		input.setSelectionRange(2, 5);
		const state = captureFocus(section);
		assert.ok(state !== null && state.element === input);
		assert.equal(state.start, 2);
		assert.equal(state.end, 5);
		section.querySelector('button').focus();
		input.setSelectionRange(0, 0);
		restoreFocus(state);
		assert.ok(document.activeElement === input);
		assert.equal(input.selectionStart, 2);
		assert.equal(input.selectionEnd, 5);
	} finally {
		section.remove();
	}
});

test('captureFocus remembers nothing when the focus is outside the section', () => {
	const section = document.createElement('section');
	document.body.appendChild(section);
	try {
		assert.equal(captureFocus(section), null);
		restoreFocus(null);
	} finally {
		section.remove();
	}
});
