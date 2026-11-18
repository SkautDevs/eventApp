import {SCREEN_FRESHNESS} from '/js/events.js';
import {syncFreshness} from '/js/loader/loader.js';
import {assert, test} from './harness.js';

const section = attributes => {
	const element = document.createElement('section');
	for (const [name, value] of Object.entries(attributes)) {
		element.setAttribute(name, value);
	}
	return element;
};

const reports = (from, fresh) => {
	let count = 0;
	from.addEventListener(SCREEN_FRESHNESS, () => {
		count++;
	});
	syncFreshness(from, fresh);
	return count;
};

test('syncFreshness reports a new data-fetched-at and writes it', () => {
	const from = section({'data-fetched-at': '2027-05-20T10:00:00+02:00'});
	assert.equal(reports(from, section({'data-fetched-at': '2027-05-20T10:05:00+02:00'})), 1);
	assert.equal(from.getAttribute('data-fetched-at'), '2027-05-20T10:05:00+02:00');
});

test('syncFreshness reports a data-stale the server stopped sending and removes it', () => {
	const from = section({'data-fetched-at': '2027-05-20T10:00:00+02:00', 'data-stale': '1'});
	assert.equal(reports(from, section({'data-fetched-at': '2027-05-20T10:00:00+02:00'})), 1);
	assert.ok(!from.hasAttribute('data-stale'));
});

test('syncFreshness reports a data-stale the server started sending', () => {
	const from = section({'data-fetched-at': '2027-05-20T10:00:00+02:00'});
	assert.equal(reports(from, section({'data-fetched-at': '2027-05-20T10:00:00+02:00', 'data-stale': '1'})), 1);
	assert.equal(from.getAttribute('data-stale'), '1');
});

test('syncFreshness reports one change when both attributes differ', () => {
	const from = section({'data-fetched-at': '2027-05-20T10:00:00+02:00'});
	assert.equal(reports(from, section({'data-fetched-at': '2027-05-20T10:05:00+02:00', 'data-stale': '1'})), 1);
});

test('syncFreshness reports nothing when both attributes are equal', () => {
	const attributes = {'data-fetched-at': '2027-05-20T10:00:00+02:00', 'data-stale': '1'};
	assert.equal(reports(section(attributes), section(attributes)), 0);
	assert.equal(reports(section({}), section({})), 0);
});

test('syncFreshness reports a bubbling event', () => {
	const box = document.createElement('div');
	const from = section({'data-fetched-at': 'a'});
	box.appendChild(from);
	let seen = null;
	box.addEventListener(SCREEN_FRESHNESS, event => {
		seen = event.target;
	});
	syncFreshness(from, section({'data-fetched-at': 'b'}));
	assert.ok(seen === from);
});
