import {age, netstateText} from '/js/shell/netstate.js';
import {assert, test} from './harness.js';

// the banner's age and its HH:MM are the device's own clock, so the instants are local too
const at = new Date(2027, 4, 20, 10, 5, 0);
const later = minutes => at.getTime() + minutes * 60000;

test('age: under a minute is just now', () => {
	assert.equal(age(at, later(0)), '(právě teď)');
	assert.equal(age(at, later(0.9)), '(právě teď)');
	// a clock a little behind is never a negative age
	assert.equal(age(at, later(-3)), '(právě teď)');
});

test('age: minutes', () => {
	assert.equal(age(at, later(25)), '(před 25 min)');
	assert.equal(age(at, later(59)), '(před 59 min)');
});

test('age: hours', () => {
	assert.equal(age(at, later(3 * 60 + 20)), '(před 3 h)');
	assert.equal(age(at, later(60)), '(před 1 h)');
});

test('age: a day old is the day itself', () => {
	assert.equal(age(at, later(25 * 60)), 'z 20. 5.');
	assert.equal(age(new Date(2027, 4, 19, 22, 0), new Date(2027, 4, 20, 23, 0).getTime()), 'z 19. 5.');
});

const fetchedAt = at.toISOString();

test('netstateText offline names the time and the age', () => {
	assert.equal(netstateText(fetchedAt, false, false, later(25), false), 'Jsi offline. Data byla naposledy aktualizována v 10:05 (před 25 min).');
	// a stale copy offline is still offline first
	assert.equal(netstateText(fetchedAt, true, false, later(25), false), 'Jsi offline. Data byla naposledy aktualizována v 10:05 (před 25 min).');
});

test('netstateText online with a copy the server could not refresh', () => {
	assert.equal(netstateText(fetchedAt, true, true, later(25), false), 'Nepodařilo se načíst aktuální data. Zobrazuji stav z 10:05 (před 25 min).');
});

test('netstateText spoken leaves the ticking age out but keeps the day', () => {
	assert.equal(netstateText(fetchedAt, false, false, later(25), true), 'Jsi offline. Data byla naposledy aktualizována v 10:05.');
	assert.equal(netstateText(fetchedAt, true, true, later(3 * 60), true), 'Nepodařilo se načíst aktuální data. Zobrazuji stav z 10:05.');
	assert.equal(netstateText(fetchedAt, false, false, later(25 * 60), true), 'Jsi offline. Data byla naposledy aktualizována v 10:05 z 20. 5.');
	assert.equal(netstateText(fetchedAt, false, false, later(25 * 60), false), 'Jsi offline. Data byla naposledy aktualizována v 10:05 z 20. 5.');
});

test('netstateText says nothing online with a fresh copy, or without a time', () => {
	assert.equal(netstateText(fetchedAt, false, true, later(25), false), '');
	assert.equal(netstateText(null, false, false, later(25), false), '');
	assert.equal(netstateText('nonsense', true, false, later(25), true), '');
});
