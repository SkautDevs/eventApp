/**
 * The network banner: one banner above the tab bar, for the visible screen. Offline, how
 * old its data is; online with an old copy the server could not refresh (data-stale), the
 * same; otherwise nothing. Read off the screen's own data-fetched-at / data-stale every
 * time it is drawn, never remembered: a morph deferred while the reader was busy updates
 * the attributes only when the screen is shown again, and the banner has to say what they
 * say then.
 *
 * What it says is spoken through its own live region (data-netstate-live), without the
 * age: "před N min" moves on every minute, and a live region would say the whole sentence
 * again each time. The banner itself is hidden from assistive tech, so nothing is read
 * twice.
 */

import {onScreenFreshness, onScreenMorphed, onScreenShown, onTick} from '../events.js';
import {now, two} from '../ui/clock.js';
import {visibleScreen} from '../ui/screen.js';

const root = document.documentElement;

/** "(právě teď)", "(před 25 min)", "(před 3 h)", or the day itself once it is a day old. */
export function age(at, nowMs) {
	const minutes = Math.max(0, Math.floor((nowMs - at.getTime()) / 60000));
	if (minutes < 1) {
		return '(právě teď)';
	}
	if (minutes < 60) {
		return '(před ' + minutes + ' min)';
	}
	if (minutes < 24 * 60) {
		return '(před ' + Math.floor(minutes / 60) + ' h)';
	}

	return 'z ' + at.getDate() + '. ' + (at.getMonth() + 1) + '.';
}

/**
 * The banner's sentence for a screen fetched at fetchedAt (its data-fetched-at), stale or
 * not, with the phone online or not; '' when there is nothing to say. spoken: the sentence
 * for the live region, which keeps the day but not the ticking age.
 */
export function netstateText(fetchedAt, stale, online, nowMs, spoken) {
	const at = new Date(fetchedAt || '');
	if (Number.isNaN(at.getTime())) {
		return '';
	}
	// HH:MM on the reader's own clock; the age from the device clock, the tests' when pinned
	const ago = age(at, nowMs);
	const when = two(at.getHours()) + ':' + two(at.getMinutes()) + (spoken && ago.charAt(0) === '(' ? '' : ' ' + ago);
	// the date band already ends in its own full stop
	const end = when.charAt(when.length - 1) === '.' ? '' : '.';
	if (!online) {
		return 'Jsi offline. Data byla naposledy aktualizována v ' + when + end;
	}
	if (stale) {
		return 'Nepodařilo se načíst aktuální data. Zobrazuji stav z ' + when + end;
	}

	return '';
}

function textFor(section, spoken) {
	return netstateText(section.getAttribute('data-fetched-at'), section.getAttribute('data-stale') === '1', navigator.onLine !== false, now(), spoken);
}

// The banner's height as --netstate-height on the root (0px while it is hidden): the
// docked view switcher stands on it, and every screen's bottom padding and one-screenful
// height make room for it (www/style.css), so it covers nothing. Measured on every draw,
// and again whenever its box changes by itself (a font arriving, a rotated phone
// wrapping it onto another number of lines).
let netstateHeight = -1;
let netstateWatched = false;

function watchNetstate(banner) {
	if (netstateWatched) {
		return;
	}
	netstateWatched = true;
	if (typeof ResizeObserver === 'function') {
		new ResizeObserver(() => {
			publishNetstateHeight(banner);
		}).observe(banner);
	} else {
		window.addEventListener('resize', drawNetstate);
	}
}

function publishNetstateHeight(banner) {
	const height = banner.hidden ? 0 : Math.ceil(banner.getBoundingClientRect().height);
	if (height !== netstateHeight) {
		netstateHeight = height;
		root.style.setProperty('--netstate-height', height + 'px');
	}
}

function drawNetstate() {
	const banner = document.querySelector('[data-netstate]');
	if (!banner) {
		return;
	}
	const section = visibleScreen();
	const text = section ? textFor(section, false) : '';
	if (banner.textContent !== text) {
		banner.textContent = text;
	}
	// shown only once it has something to say
	if (banner.hidden !== (text === '')) {
		banner.hidden = text === '';
	}
	// written only when the state (or the time it names) changes, so it speaks only then
	const live = document.querySelector('[data-netstate-live]');
	const spoken = section ? textFor(section, true) : '';
	if (live && live.textContent !== spoken) {
		live.textContent = spoken;
	}
	publishNetstateHeight(banner);
	watchNetstate(banner);
}

// the banner follows the screen, and a morph or a revalidation changes its data
document.addEventListener('DOMContentLoaded', drawNetstate);
onScreenShown(drawNetstate);
onScreenMorphed(drawNetstate);
onScreenFreshness(drawNetstate);
window.addEventListener('online', drawNetstate);
window.addEventListener('offline', drawNetstate);
// "před N min" moves on by itself
setInterval(drawNetstate, 60000);
onTick(drawNetstate);
document.addEventListener('visibilitychange', () => {
	if (!document.hidden) {
		drawNetstate();
	}
});
