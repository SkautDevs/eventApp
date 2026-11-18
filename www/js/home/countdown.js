/**
 * Domů before the event, and during it: the countdown to the start and the date heading,
 * both read on the Prague calendar, so they turn at Prague's midnight on any phone.
 */

import {pragueDay, pragueParts} from '../ui/clock.js';

export function czechDays(n) {
	const forms = ['den', 'dny', 'dní'];

	return n === 1 ? forms[0] : n >= 2 && n <= 4 ? forms[1] : forms[2];
}

/** Whole Prague days from the day nowMs falls on to startYmd ("YYYY-MM-DD"); NaN for no date. */
export function daysUntil(startYmd, nowMs) {
	const today = Date.parse(pragueDay(nowMs) + 'T00:00:00Z');

	return Math.round((Date.parse(startYmd + 'T00:00:00Z') - today) / 86400000);
}

// "Pátek 21. 5." on the Prague calendar, as HomeModel::todayLabel()
const WEEKDAYS = ['Neděle', 'Pondělí', 'Úterý', 'Středa', 'Čtvrtek', 'Pátek', 'Sobota'];

export function dateLabel(nowMs) {
	const today = pragueParts(nowMs);
	const weekday = new Date(Date.UTC(Number(today.year), Number(today.month) - 1, Number(today.day))).getUTCDay();

	return WEEKDAYS[weekday] + ' ' + Number(today.day) + '. ' + Number(today.month) + '.';
}

/** The days to the start, until it has come; then the card is gone. */
export function drawCountdown(nowMs) {
	document.querySelectorAll('[data-countdown]').forEach(card => {
		const days = daysUntil(card.dataset.start, nowMs);
		if (Number.isNaN(days) || days <= 0) {
			card.hidden = true;
			return;
		}
		const count = card.querySelector('[data-countdown-days]');
		const unit = card.querySelector('[data-countdown-unit]');
		if (count && count.textContent !== String(days)) {
			count.textContent = String(days);
		}
		if (unit && unit.textContent !== czechDays(days)) {
			unit.textContent = czechDays(days);
		}
		if (card.hidden) {
			card.hidden = false;
		}
	});
}

export function drawHomeDate(nowMs) {
	const text = dateLabel(nowMs);
	document.querySelectorAll('[data-home-date]').forEach(heading => {
		if (heading.textContent !== text) {
			heading.textContent = text;
		}
	});
}
