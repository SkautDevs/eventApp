import {TICK} from '/js/events.js';
import {now, onClockRedraw, pragueDay, pragueParts, two} from '/js/ui/clock.js';
import {assert, test} from './harness.js';

// 22:30 UTC on 20 May is 00:30 on 21 May in Prague (CEST, UTC+2)
const AROUND_MIDNIGHT = Date.parse('2027-05-20T22:30:00Z');

test('two pads to two digits', () => {
	assert.equal(two(7), '07');
	assert.equal(two(12), '12');
	assert.equal(two(0), '00');
});

test('pragueDay reads the Prague calendar, not UTC', () => {
	assert.equal(new Date(AROUND_MIDNIGHT).toISOString().slice(0, 10), '2027-05-20');
	assert.equal(pragueDay(AROUND_MIDNIGHT), '2027-05-21');
});

test('pragueParts is the Prague wall clock', () => {
	assert.deepEqual(pragueParts(AROUND_MIDNIGHT), {year: '2027', month: '05', day: '21', hour: '00', minute: '30'});
	// winter: CET, UTC+1
	assert.deepEqual(pragueParts(Date.parse('2027-01-03T09:05:00Z')), {year: '2027', month: '01', day: '03', hour: '10', minute: '05'});
});

test('now follows a window.pgClock assigned after the module loaded', () => {
	window.pgClock = () => 1234;
	try {
		assert.equal(now(), 1234);
		window.pgClock = () => 5678;
		assert.equal(now(), 5678);
	} finally {
		delete window.pgClock;
	}
	const before = Date.now();
	const value = now();
	assert.ok(value >= before && value <= Date.now());
});

test('onClockRedraw calls every subscriber in order with one now', () => {
	const calls = [];
	onClockRedraw(at => calls.push(['a', at]));
	onClockRedraw(at => calls.push(['b', at]));
	let clock = 100;
	window.pgClock = () => clock++;
	try {
		document.dispatchEvent(new CustomEvent(TICK));
	} finally {
		delete window.pgClock;
	}
	assert.deepEqual(calls, [
		['a', 100],
		['b', 100],
	]);
});
