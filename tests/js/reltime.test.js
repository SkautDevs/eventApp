import {drawSentAt, relativeTime} from '/js/ui/reltime.js';
import {assert, test} from './harness.js';

// Prague wall-clock times in May (CEST, UTC+2), as NotificationListTest::bands() writes them
const prague = text => Date.parse(text.replace(' ', 'T') + '+02:00');

// the bands of NotificationList::relative(), case for case from NotificationListTest
const bands = [
	['just now', '2027-05-20 10:00:00', '2027-05-20 10:00:59', 'právě teď'],
	['a clock a little ahead', '2027-05-20 10:00:30', '2027-05-20 10:00:00', 'právě teď'],
	['one minute', '2027-05-20 10:00:00', '2027-05-20 10:01:00', 'před 1 min'],
	['minutes', '2027-05-20 10:00:00', '2027-05-20 10:59:59', 'před 59 min'],
	['minutes across midnight', '2027-05-20 23:50:00', '2027-05-21 00:10:00', 'před 20 min'],
	['hours the same day', '2027-05-20 08:00:00', '2027-05-20 11:30:00', 'před 3 h'],
	['one hour', '2027-05-20 08:00:00', '2027-05-20 09:00:00', 'před 1 h'],
	['yesterday, not hours ago', '2027-05-20 22:00:00', '2027-05-21 00:10:00', 'včera 22:00'],
	['yesterday morning', '2027-05-20 07:05:00', '2027-05-21 23:00:00', 'včera 07:05'],
	['older as a date', '2027-05-19 21:40:00', '2027-05-21 08:00:00', '19. 5. 21:40'],
];

for (const [name, at, current, expected] of bands) {
	test('relativeTime: ' + name, () => {
		assert.equal(relativeTime(prague(at), prague(current)), expected);
	});
}

test('relativeTime reads the calendar in Prague, whatever zone the instants come in', () => {
	assert.equal(relativeTime(Date.parse('2027-05-20T20:00:00Z'), Date.parse('2027-05-20T22:10:00Z')), 'včera 22:00');
});

test('drawSentAt writes every [data-sent-at] and skips one it cannot read', () => {
	const box = document.createElement('div');
	box.innerHTML = '<time data-sent-at="2027-05-20T08:00:00+02:00">old</time><time data-sent-at="nonsense">kept</time>';
	document.body.appendChild(box);
	drawSentAt(prague('2027-05-20 11:30:00'));
	const [sent, broken] = box.querySelectorAll('time');
	box.remove();
	assert.equal(sent.textContent, 'před 3 h');
	assert.equal(broken.textContent, 'kept');
});
