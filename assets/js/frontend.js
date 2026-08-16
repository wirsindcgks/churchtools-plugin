/**
 * Client-side calendar filter + search for the list/grid layouts. Runs entirely
 * in the browser (no re-fetch) so it keeps working under full-page caching, which
 * the shortcode's server-rendered output has to support. Event delegation on
 * `document` means it works for every [ctp_events] instance on the page without
 * having to (re-)bind listeners per instance.
 */
(function () {
	'use strict';

	/**
	 * Re-applies both the calendar filter (select) and the search box (if
	 * present) together — a single pass so an item hidden by either one stays
	 * hidden, instead of two independent handlers fighting over the same
	 * `hidden` attribute. Also updates month-divider visibility (a divider
	 * whose whole month is now empty must disappear too, see
	 * updateMonthDividers()) and the "nothing found" message.
	 */
	function applyToolbarState(container) {
		var select = container.querySelector('.ctp-events__filter');
		var searchInput = container.querySelector('.ctp-events__search-input');
		var calendarId = select ? select.value : '';
		var query = searchInput ? searchInput.value.trim().toLowerCase() : '';

		var items = container.querySelectorAll('[data-ctp-calendar]');
		var visibleCount = 0;

		items.forEach(function (item) {
			var matchesCalendar = calendarId === '' || item.getAttribute('data-ctp-calendar') === calendarId;
			var matchesSearch = query === '' || (item.getAttribute('data-ctp-search') || '').indexOf(query) !== -1;
			var visible = matchesCalendar && matchesSearch;

			item.hidden = !visible;
			if (visible) {
				visibleCount += 1;
			}
		});

		updateMonthDividers(container);

		var emptyMessage = container.querySelector('.ctp-events__toolbar-empty');
		if (emptyMessage) {
			emptyMessage.hidden = visibleCount !== 0;
		}
	}

	/**
	 * Hides a month divider when every item between it and the next divider
	 * (or the end of the list) is currently hidden — otherwise an active
	 * filter/search can leave a "August 2026" heading floating above zero
	 * visible events.
	 */
	function updateMonthDividers(container) {
		var dividers = container.querySelectorAll('.ctp-events__month-divider');

		dividers.forEach(function (divider) {
			var hasVisibleItem = false;
			var sibling = divider.nextElementSibling;

			while (sibling && !sibling.classList.contains('ctp-events__month-divider')) {
				if (sibling.hasAttribute('data-ctp-calendar') && !sibling.hidden) {
					hasVisibleItem = true;
					break;
				}
				sibling = sibling.nextElementSibling;
			}

			divider.hidden = !hasVisibleItem;
		});
	}

	document.addEventListener('change', function (event) {
		var select = event.target;

		if (!select.classList || !select.classList.contains('ctp-events__filter')) {
			return;
		}

		var container = select.closest('.ctp-events');
		if (container) {
			applyToolbarState(container);
		}
	});

	document.addEventListener('input', function (event) {
		var input = event.target;

		if (!input.classList || !input.classList.contains('ctp-events__search-input')) {
			return;
		}

		var container = input.closest('.ctp-events');
		if (container) {
			applyToolbarState(container);
		}
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
