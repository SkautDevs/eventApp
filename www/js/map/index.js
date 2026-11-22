/**
 * Místa: each [data-map-root] is wired once (data-map-ready), at evaluation for the
 * document's own screen and on screen:shown for one the loader inserts. The offline note
 * subscribes first, as it did when the shell held it.
 */

import {onScreenShown} from '../events.js';
import './offline.js';
import {setupPlan} from './plan.js';
import {setupViews} from './views.js';

function init(root) {
	if (!root || root.dataset.mapReady === '1') {
		return;
	}
	root.dataset.mapReady = '1';
	const slug = root.dataset.mapEvent || '';
	setupViews(root, setupPlan(root, slug));
}

function initAll(scope) {
	scope.querySelectorAll('[data-map-root]').forEach(init);
}

initAll(document);
onScreenShown(initAll);
