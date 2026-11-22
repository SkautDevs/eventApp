import {SCREEN_FRESHNESS} from '/js/events.js';
import {parseAliases, resolveScreen, shouldInsertHome, syncFreshness} from '/js/loader/loader.js';
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

test('parseAliases reads the space-separated paths', () => {
	assert.deepEqual(parseAliases('/k/programy/prehled  /k/programy/moje', 'http://x/k/'), ['/k/programy/prehled', '/k/programy/moje']);
	assert.deepEqual(parseAliases('', 'http://x/k/'), []);
});

test('resolveScreen maps an alias to its screen and anything else to itself', () => {
	const map = new Map([['/k/programy/moje', '/k/programy']]);
	assert.equal(resolveScreen(map, '/k/programy/moje'), '/k/programy');
	assert.equal(resolveScreen(map, '/k/informace'), '/k/informace');
});

test('shouldInsertHome only on a fresh navigation from outside the event to another screen', () => {
	const base = {screen: '/k/informace', home: '/k/', navigationType: 'navigate', state: null, referrer: '', eventUrl: 'http://x/k/'};
	assert.ok(shouldInsertHome(base));
	assert.ok(!shouldInsertHome({...base, screen: '/k/'}));
	assert.ok(!shouldInsertHome({...base, navigationType: 'reload'}));
	assert.ok(!shouldInsertHome({...base, navigationType: 'back_forward'}));
	assert.ok(!shouldInsertHome({...base, state: {screen: '/k/informace'}}));
	assert.ok(!shouldInsertHome({...base, referrer: 'http://x/k/programy'}));
	assert.ok(shouldInsertHome({...base, referrer: 'http://x/other/'}));
});
