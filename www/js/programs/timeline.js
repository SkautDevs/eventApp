/** The timeline's pages, one per day: which one is up, and stepping between them. */

export function setup(ctx) {
	const {root} = ctx;

	ctx.panels = kind => Array.from(root.querySelectorAll('[data-pg-panel="' + kind + '"]'));

	ctx.activeIndex = kind => ctx.panels(kind).findIndex(panel => panel.classList.contains('is-active'));

	ctx.showPage = (kind, index) => {
		const list = ctx.panels(kind);
		if (list.length === 0) {
			return;
		}
		index = Math.min(Math.max(index, 0), list.length - 1);
		// the page transition runs the other way round when stepping backwards
		const previous = ctx.activeIndex(kind);
		if (previous >= 0 && index !== previous) {
			root.dataset.step = index < previous ? 'prev' : 'next';
		}
		list.forEach((panel, i) => {
			panel.classList.toggle('is-active', i === index);
		});

		ctx.pageKey = list[index].dataset.key;
		ctx.markChip(kind, list[index].dataset.key);
		// the ruler that is on screen is this page's, so re-check its density
		ctx.applyTickStep();
	};

	ctx.showPageByKey = (kind, key) => {
		const index = ctx.panels(kind).findIndex(panel => panel.dataset.key === key);
		if (index >= 0) {
			ctx.showPage(kind, index);
		}
	};
}
