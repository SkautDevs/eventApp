import {draftKey, keepDraft, readDraft, SENT_HASH, shouldRestore, writeDraft} from '/js/inbox/index.js';
import {assert, test} from './harness.js';

/** A Storage stand-in over a Map; `broken` makes every call throw, as a blocked storage does. */
function fakeStorage(broken) {
	const items = new Map();
	const guard = () => {
		if (broken) {
			throw new Error('storage is blocked');
		}
	};
	return {
		items,
		getItem(key) {
			guard();
			return items.has(key) ? items.get(key) : null;
		},
		setItem(key, value) {
			guard();
			items.set(key, String(value));
		},
		removeItem(key) {
			guard();
			items.delete(key);
		},
	};
}

test('SENT_HASH is the landing hash of a confirmed send', () => {
	assert.equal(SENT_HASH, '#odeslano');
});

test('draftKey is one key per event', () => {
	assert.equal(draftKey('/obrok27'), 'inboxDraft:/obrok27');
});

test('writeDraft then readDraft round-trips the three fields and the owner', () => {
	const storage = fakeStorage(false);
	const key = draftKey('/obrok27');
	writeDraft(storage, key, {owner: '1234', kind: 'help', body: 'a\nb', contact: ''});
	assert.deepEqual(readDraft(storage, key), {owner: '1234', kind: 'help', body: 'a\nb', contact: ''});
});

test('writeDraft with every field empty removes the key', () => {
	const storage = fakeStorage(false);
	const key = draftKey('/obrok27');
	writeDraft(storage, key, {owner: '1234', kind: 'idea', body: 'x', contact: ''});
	writeDraft(storage, key, {owner: '1234', kind: '', body: '', contact: ''});
	assert.equal(storage.items.has(key), false);
	assert.equal(readDraft(storage, key), null);
});

test('writeDraft without an owner removes the key: such a draft belongs to nobody', () => {
	const storage = fakeStorage(false);
	const key = draftKey('/obrok27');
	writeDraft(storage, key, {owner: '1234', kind: 'idea', body: 'x', contact: ''});
	writeDraft(storage, key, {owner: '', kind: 'idea', body: 'x', contact: ''});
	assert.equal(storage.items.has(key), false);
});

test('readDraft is null for nothing stored, broken JSON, an array and a throwing storage', () => {
	const storage = fakeStorage(false);
	assert.equal(readDraft(storage, 'missing'), null);
	storage.items.set('broken', '{');
	assert.equal(readDraft(storage, 'broken'), null);
	storage.items.set('array', '["help", "a", ""]');
	assert.equal(readDraft(storage, 'array'), null);
	assert.equal(readDraft(fakeStorage(true), 'any'), null);
});

test('readDraft is null for a draft without an owner, with an empty one or a number', () => {
	const storage = fakeStorage(false);
	storage.items.set('none', '{"kind": "help", "body": "a", "contact": ""}');
	assert.equal(readDraft(storage, 'none'), null);
	storage.items.set('empty', '{"owner": "", "kind": "help", "body": "a", "contact": ""}');
	assert.equal(readDraft(storage, 'empty'), null);
	storage.items.set('number', '{"owner": 1234, "kind": "help", "body": "a", "contact": ""}');
	assert.equal(readDraft(storage, 'number'), null);
});

test('writeDraft does not throw when the storage does', () => {
	writeDraft(fakeStorage(true), 'any', {owner: '1234', kind: 'help', body: 'a', contact: ''});
	writeDraft(fakeStorage(true), 'any', {owner: '1234', kind: '', body: '', contact: ''});
	assert.ok(true);
});

test('keepDraft only for a draft of the reader the page is for', () => {
	const draft = {owner: '1234', kind: 'help', body: 'a', contact: ''};
	assert.equal(keepDraft(draft, '1234'), true);
	assert.equal(keepDraft(draft, '5678'), false, 'another participant');
	assert.equal(keepDraft(draft, ''), false, 'logged out');
	assert.equal(keepDraft(null, '1234'), false, 'nothing or a broken draft');
});

test('shouldRestore only over a form whose three fields are empty', () => {
	assert.equal(shouldRestore({kind: '', body: '', contact: ''}), true);
	assert.equal(shouldRestore({kind: 'help', body: '', contact: ''}), false);
	assert.equal(shouldRestore({kind: '', body: 'a', contact: ''}), false);
	assert.equal(shouldRestore({kind: '', body: '', contact: 'kaja@example.cz'}), false);
});
