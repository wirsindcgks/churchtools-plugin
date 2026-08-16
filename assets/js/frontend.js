/**
 * Client-side calendar filter for the list/grid layouts. Runs entirely in the
 * browser (no re-fetch) so it keeps working under full-page caching, which the
 * shortcode's server-rendered output has to support. Event delegation on
 * `document` means it works for every [ctp_events] instance on the page without
 * having to (re-)bind listeners per instance.
 */
(function () {
	'use strict';

	document.addEventListener('change', function (event) {
		var select = event.target;

		if (!select.classList || !select.classList.contains('ctp-events__filter')) {
			return;
		}

		var container = select.closest('.ctp-events');
		if (!container) {
			return;
		}

		var calendarId = select.value;
		var items = container.querySelectorAll('[data-ctp-calendar]');

		items.forEach(function (item) {
			item.hidden = calendarId !== '' && item.getAttribute('data-ctp-calendar') !== calendarId;
		});
	});

	/**
	 * "Popup" click behavior: the detail markup for every card is already in the
	 * page (see the <template class="ctp-events__detail-template"> embedded per
	 * card by EventListRenderer::withCalendarMeta()/templates) — clicking a
	 * trigger just clones it into the shared <dialog> and calls showModal(),
	 * no fetch involved. Scoped per .ctp-events container (like the filter
	 * above) so multiple shortcode instances on one page don't interfere.
	 */
	document.addEventListener('click', function (event) {
		var trigger = event.target.closest('.ctp-events__card-trigger');

		if (trigger && trigger.tagName === 'BUTTON') {
			openDetailModal(trigger);

			return;
		}

		var closeButton = event.target.closest('.ctp-events__modal-close');
		if (closeButton) {
			var dialog = closeButton.closest('.ctp-events__modal');
			if (dialog) {
				dialog.close();
			}

			return;
		}

		// A click that lands on the <dialog> element itself (not on any of its
		// children) means the backdrop was clicked — the visible modal box is
		// sized to its content, so the element's own padding box never receives
		// this click, only the ::backdrop pseudo-element outside it does.
		if (event.target.classList && event.target.classList.contains('ctp-events__modal')) {
			event.target.close();
		}
	});

	function openDetailModal(trigger) {
		var unit = trigger.closest('li, .ctp-events__hero');
		var container = trigger.closest('.ctp-events');
		if (!unit || !container) {
			return;
		}

		var template = unit.querySelector('template.ctp-events__detail-template');
		var dialog = container.querySelector('.ctp-events__modal');
		var body = dialog ? dialog.querySelector('.ctp-events__modal-body') : null;
		if (!template || !dialog || !body) {
			return;
		}

		body.innerHTML = '';
		body.appendChild(template.content.cloneNode(true));
		dialog.showModal();
	}
})();
