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
	 * Reads the active calendar filter, whichever UI produced it — the plain
	 * <select> (partials/toolbar.php) or an active eventfinder button
	 * (partials/eventfinder.php, mutually exclusive with the select, see
	 * EventListRenderer::render()).
	 */
	function activeCalendarId(container) {
		var select = container.querySelector('.ctp-events__filter');
		if (select) {
			return select.value;
		}

		var activeButton = container.querySelector('[data-ctp-finder-calendar].ctp-events__finder-btn--active');

		return activeButton ? activeButton.getAttribute('data-ctp-finder-calendar') : '';
	}

	/**
	 * Reads the active eventfinder timeframe button ("week"/"weekend"/"month"),
	 * or '' for "Jederzeit"/no eventfinder at all.
	 */
	function activeTimeframe(container) {
		var activeButton = container.querySelector('[data-ctp-finder-timeframe].ctp-events__finder-btn--active');

		return activeButton ? activeButton.getAttribute('data-ctp-finder-timeframe') : '';
	}

	/**
	 * Parses a "Y-m-d" date-only string (see EventFormatter::dateKey()) into a
	 * local-midnight Date. Deliberately not `new Date('Y-m-d')` — per the ES5
	 * spec, a date-only ISO string parses as UTC midnight, which can shift the
	 * date by a day once compared against a local "today" west of UTC.
	 */
	function parseDateKey(value) {
		var parts = (value || '').split('-');
		if (parts.length !== 3) {
			return null;
		}

		return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
	}

	function startOfToday() {
		var today = new Date();
		today.setHours(0, 0, 0, 0);

		return today;
	}

	/**
	 * "week"/"weekend"/"month" as [from, to] Date ranges (inclusive, local
	 * midnight), anchored on today rather than the calendar week/month's own
	 * start — events are already upcoming-only (EventQueryCache::findUpcoming()),
	 * so a range reaching back to Monday or the 1st would just match nothing
	 * extra there.
	 */
	function timeframeRange(timeframe) {
		var today = startOfToday();
		var day = today.getDay();
		var monday = new Date(today);
		monday.setDate(today.getDate() + (day === 0 ? -6 : 1 - day));
		var sunday = new Date(monday);
		sunday.setDate(monday.getDate() + 6);

		if (timeframe === 'week') {
			return { from: today, to: sunday };
		}

		if (timeframe === 'weekend') {
			var saturday = new Date(monday);
			saturday.setDate(monday.getDate() + 5);

			return { from: saturday < today ? today : saturday, to: sunday };
		}

		if (timeframe === 'month') {
			var monthEnd = new Date(today.getFullYear(), today.getMonth() + 1, 0);

			return { from: today, to: monthEnd };
		}

		return null;
	}

	function matchesTimeframe(dateKey, timeframe) {
		if (timeframe === '') {
			return true;
		}

		var date = parseDateKey(dateKey);
		var range = timeframeRange(timeframe);
		if (!date || !range) {
			return true;
		}

		return date >= range.from && date <= range.to;
	}

	/**
	 * Re-applies the calendar filter, timeframe (eventfinder only) and search
	 * box together — a single pass so an item hidden by one stays hidden,
	 * instead of independent handlers fighting over the same `hidden`
	 * attribute. Also updates month-divider visibility (a divider whose whole
	 * month is now empty must disappear too, see updateMonthDividers()) and
	 * the "nothing found" message.
	 */
	function applyToolbarState(container) {
		var searchInput = container.querySelector('.ctp-events__search-input');
		var calendarId = activeCalendarId(container);
		var timeframe = activeTimeframe(container);
		var query = searchInput ? searchInput.value.trim().toLowerCase() : '';

		var items = container.querySelectorAll('[data-ctp-calendar]');
		var visibleCount = 0;

		items.forEach(function (item) {
			var matchesCalendar = calendarId === '' || item.getAttribute('data-ctp-calendar') === calendarId;
			var matchesSearch = query === '' || (item.getAttribute('data-ctp-search') || '').indexOf(query) !== -1;
			var matchesRange = matchesTimeframe(item.getAttribute('data-ctp-start'), timeframe);
			var visible = matchesCalendar && matchesSearch && matchesRange;

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
	 * Eventfinder calendar/timeframe buttons (partials/eventfinder.php): each
	 * `.ctp-events__finder-group` is a mutually-exclusive toggle group (native
	 * `<select>` semantics without a `<select>`, so "Du suchst …" buttons can
	 * carry a label instead of an option), scoped per group so the calendar
	 * group and the timeframe group don't clear each other.
	 */
	document.addEventListener('click', function (event) {
		var button = event.target.closest('.ctp-events__finder-btn');
		if (!button) {
			return;
		}

		var group = button.closest('.ctp-events__finder-group');
		var container = button.closest('.ctp-events');
		if (!group || !container) {
			return;
		}

		group.querySelectorAll('.ctp-events__finder-btn').forEach(function (groupButton) {
			groupButton.classList.remove('ctp-events__finder-btn--active');
			groupButton.setAttribute('aria-pressed', 'false');
		});
		button.classList.add('ctp-events__finder-btn--active');
		button.setAttribute('aria-pressed', 'true');

		applyToolbarState(container);
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
