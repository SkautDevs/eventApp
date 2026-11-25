/**
 * Můj program on Domů: every one of the participant's programmes is a card carrying its
 * instants. The server (HomeModel) already shows the running one and the next two, each with
 * its lead line, and hides the rest; this script redoes that pick and those lines by the
 * device clock, so a stored or long-open page stays right. The times are the server's (data-time, data-until: Prague wall-clock), so a phone in another
 * zone still reads the event's own.
 */

export const NEXT_SHOWN = 3;
const HOUR = 3600000;

/** The lead line of a card ({start, end, time, until, location}: a card's dataset) at nowMs. */
export function nextLead(card, nowMs) {
	const place = card.location ? ' · ' + card.location : '';
	const start = Date.parse(card.start);
	if (start <= nowMs) {
		return 'Právě · ' + card.until + place;
	}
	if (start - nowMs <= HOUR) {
		return 'Za ' + Math.ceil((start - nowMs) / 60000) + ' min · ' + card.time + place;
	}

	return card.time + place;
}

/** The indexes of the cards shown at nowMs: those not yet over, in order, at most limit. */
export function shownCards(cards, nowMs, limit) {
	const shown = [];
	cards.forEach((card, index) => {
		if (shown.length < limit && Date.parse(card.end) > nowMs) {
			shown.push(index);
		}
	});

	return shown;
}

export function drawNextUp(nowMs) {
	document.querySelectorAll('[data-next-up]').forEach(box => {
		const cards = Array.from(box.querySelectorAll('.next-card'));
		const data = cards.map(card => card.dataset);
		const shown = shownCards(data, nowMs, NEXT_SHOWN);
		cards.forEach((card, index) => {
			const show = shown.includes(index);
			if (show) {
				const lead = card.querySelector('[data-next-lead]');
				const text = nextLead(card.dataset, nowMs);
				if (lead && lead.textContent !== text) {
					lead.textContent = text;
				}
			}
			if (card.hidden === show) {
				card.hidden = !show;
			}
		});
		if (box.hidden !== (shown.length === 0)) {
			box.hidden = shown.length === 0;
		}
	});
}
