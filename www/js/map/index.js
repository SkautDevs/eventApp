/**
 * Místa: each [data-map-root] is wired once (data-map-ready), at evaluation for the
 * document's own screen and on screen:shown for one the loader inserts. The offline note
 * subscribes first, as it did when the shell held it. The place (`?misto=`) is put on show
 * after every view change, and its own listeners come after the views', so a popstate
 * reaches the views first.
 */

import {onScreenShown} from '../events.js';
import './offline.js';
import {setupPlace} from './place.js';
import {setupPlan} from './plan.js';
import {setupViews} from './views.js';

function init(root) {
	if (!root || root.dataset.mapReady === '1') {
		return;
	}
	root.dataset.mapReady = '1';
	const slug = root.dataset.mapEvent || '';
	const plan = setupPlan(root, slug);
	const place = setupPlace(root, plan);
	setupViews(root, plan, () => place.sync());
	place.listen();
}

function initAll(scope) {
	scope.querySelectorAll('[data-map-root]').forEach(init);
}

initAll(document);
onScreenShown(initAll);
