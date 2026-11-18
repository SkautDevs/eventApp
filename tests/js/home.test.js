import {czechDays, dateLabel, daysUntil} from '/js/home/countdown.js';
import {nextLead, shownCards} from '/js/home/nextup.js';
import {assert, test} from './harness.js';

const at = iso => Date.parse(iso);
const NOW = at('2027-05-20T13:35:00+02:00');

// what the server writes on a card: its instants, and its Prague wall-clock times
const card = (start, end, extra = {}) => ({start, end, time: '14:00', until: 'do 12:30', ...extra});

test('nextLead: a running card says so, with its end and place', () => {
	const running = card('2027-05-20T11:00:00+02:00', '2027-05-20T12:30:00+02:00', {time: '11:00', location: 'Žlutý les'});
	assert.equal(nextLead(running, at('2027-05-20T12:00:00+02:00')), 'Právě · do 12:30 · Žlutý les');
});

test('nextLead: within the hour the minutes to go', () => {
	const soon = card('2027-05-20T14:00:00+02:00', '2027-05-20T15:00:00+02:00', {location: 'Modrý les'});
	assert.equal(nextLead(soon, NOW), 'Za 25 min · 14:00 · Modrý les');
});

test('nextLead: beyond the hour its time', () => {
	const later = card('2027-05-20T14:00:00+02:00', '2027-05-20T15:00:00+02:00', {location: 'Modrý les'});
	assert.equal(nextLead(later, at('2027-05-20T12:00:00+02:00')), '14:00 · Modrý les');
});

test('nextLead: no place, no separator for it', () => {
	const soon = card('2027-05-20T14:00:00+02:00', '2027-05-20T15:00:00+02:00');
	assert.equal(nextLead(soon, NOW), 'Za 25 min · 14:00');
	assert.equal(nextLead(soon, at('2027-05-20T12:00:00+02:00')), '14:00');
});

test('shownCards: the running one and the next two, never an ended one', () => {
	const cards = [
		card('2027-05-20T09:00:00+02:00', '2027-05-20T10:00:00+02:00'),
		card('2027-05-20T13:00:00+02:00', '2027-05-20T14:00:00+02:00'),
		card('2027-05-20T15:00:00+02:00', '2027-05-20T16:00:00+02:00'),
		card('2027-05-20T17:00:00+02:00', '2027-05-20T18:00:00+02:00'),
		card('2027-05-20T19:00:00+02:00', '2027-05-20T20:00:00+02:00'),
	];
	assert.deepEqual(shownCards(cards, NOW, 3), [1, 2, 3]);
	assert.deepEqual(shownCards(cards, at('2027-05-20T21:00:00+02:00'), 3), []);
});

test('czechDays: den, dny, dní', () => {
	assert.equal(czechDays(1), 'den');
	assert.equal(czechDays(2), 'dny');
	assert.equal(czechDays(4), 'dny');
	assert.equal(czechDays(5), 'dní');
	assert.equal(czechDays(21), 'dní');
});

test('daysUntil counts Prague days across a UTC midnight', () => {
	// 22:30 UTC on 20 May is already 21 May in Prague: the 22nd is one day away, not two
	assert.equal(daysUntil('2027-05-22', at('2027-05-20T22:30:00Z')), 1);
	assert.equal(daysUntil('2027-05-22', at('2027-05-20T21:30:00Z')), 2);
	assert.equal(daysUntil('2027-05-20', at('2027-05-20T10:00:00Z')), 0);
	assert.ok(Number.isNaN(daysUntil('', NOW)));
});

test('dateLabel is the Prague day, as HomeModel::todayLabel() writes it', () => {
	assert.equal(dateLabel(at('2027-05-20T22:30:00Z')), 'Pátek 21. 5.');
	assert.equal(dateLabel(at('2027-05-20T21:30:00Z')), 'Čtvrtek 20. 5.');
});
