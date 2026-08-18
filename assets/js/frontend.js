/**
 * Client-side calendar filter + search for the list/grid layouts, plus the
 * "load more" paging that appends the next time window. The filter/search parts
 * run entirely in the browser (no re-fetch) so they keep working under full-page
 * caching, which the shortcode's server-rendered output has to support; paging
 * is the one place that talks to the server, over a public read-only endpoint
 * that needs no nonce for exactly that reason (see EventsEndpoint).
 *
 * Event delegation on `document` means all of it works for every [ctp_events]
 * instance on the page — including cards appended after load — without having to
 * (re-)bind listeners per instance.
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

	/*
	 * Search is two-stage. Typing filters what is already in the DOM instantly
	 * (no request, works under full-page caching) — but the DOM only holds the
	 * month window the page loaded, so a match further out would never show up.
	 * A debounced request therefore also asks the server for every match across
	 * the whole synced horizon and swaps those in.
	 *
	 * Original markup is stashed on first search and restored when the box is
	 * cleared, so clearing always returns to the exact paged list the visitor
	 * had — including anything they had already loaded via "Weitere Termine".
	 */
	var searchTimers = new WeakMap();
	var stashedLists = new WeakMap();

	document.addEventListener('input', function (event) {
		var input = event.target;

		if (!input.classList || !input.classList.contains('ctp-events__search-input')) {
			return;
		}

		var container = input.closest('.ctp-events');
		if (!container) {
			return;
		}

		applyToolbarState(container);

		var config;
		try {
			config = JSON.parse(input.getAttribute('data-ctp-search-config') || '{}');
		} catch (error) {
			return;
		}

		if (!config.endpoint) {
			return;
		}

		clearTimeout(searchTimers.get(input));
		searchTimers.set(input, setTimeout(function () {
			runServerSearch(container, input, config);
		}, 300));
	});

	function runServerSearch(container, input, config) {
		var list = container.querySelector('.ctp-events__list');
		var query = input.value.trim();
		if (!list) {
			return;
		}

		if (query.length < (config.min || 2)) {
			restoreStashedList(container, list);

			return;
		}

		if (!stashedLists.has(container)) {
			stashedLists.set(container, list.innerHTML);
		}

		var params = new URLSearchParams({
			action: config.action,
			search: query,
			layout: config.layout,
			columns: config.columns,
			click: config.click,
			calendars: (config.calendars || []).join(','),
		});

		fetch(config.endpoint + '?' + params.toString(), { credentials: 'same-origin' })
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				// The box may have been typed in further (or cleared) while this
				// request was in flight — a stale response must not overwrite a
				// newer state.
				if (input.value.trim() !== query) {
					return;
				}
				if (!payload || !payload.success || !payload.data) {
					return;
				}

				list.innerHTML = payload.data.html;
				setSearchMode(container, true);
				applyToolbarState(container);
			})
			.catch(function () {
				// Leave the client-side filtered list in place: it is a valid,
				// if narrower, answer to the same query.
			});
	}

	function restoreStashedList(container, list) {
		if (stashedLists.has(container)) {
			list.innerHTML = stashedLists.get(container);
			stashedLists.delete(container);
		}
		setSearchMode(container, false);
		applyToolbarState(container);
	}

	/**
	 * While server results are shown, the load-more button would page the
	 * *window*, not the search — appending unrelated events under a list of
	 * matches. Hidden rather than removed so clearing the box brings it back.
	 */
	function setSearchMode(container, active) {
		container.classList.toggle('ctp-events--searching', active);

		var more = container.querySelector('.ctp-events__more');
		if (more) {
			more.hidden = active;
		}
	}

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
	 * The remaining click targets inside a .ctp-events container:
	 *
	 * - "Weitere Termine laden" (partials/load-more.php) — see loadNextPage().
	 * - "Popup" click behavior: the detail markup for every card is already in
	 *   the page (see the <template class="ctp-events__detail-template">
	 *   embedded per card by EventListRenderer::withCalendarMeta()/templates),
	 *   so clicking a trigger just clones it into the shared <dialog> and calls
	 *   showModal(), no fetch involved.
	 * - Closing that dialog, via its button or a click on the backdrop.
	 *
	 * All scoped per .ctp-events container (like the filter above) so multiple
	 * shortcode instances on one page don't interfere.
	 */
	document.addEventListener('click', function (event) {
		var loadMore = event.target.closest('.ctp-events__load-more');

		if (loadMore) {
			loadNextPage(loadMore);

			return;
		}

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

	/**
	 * Fetches the next time window and appends its items to the list. The
	 * button carries the whole instance configuration (calendars, layout,
	 * click behavior, ...) in a JSON data attribute, so the appended markup
	 * is rendered by the same server-side code path as the first page — the
	 * only thing that changes between clicks is the page index, which gets
	 * written back into the attribute from the server's response rather than
	 * counted up here (the server may skip over months without any events).
	 */
	function loadNextPage(button) {
		var container = button.closest('.ctp-events');
		var list = container ? container.querySelector('.ctp-events__list') : null;
		var config;

		try {
			config = JSON.parse(button.getAttribute('data-ctp-paging') || '{}');
		} catch (error) {
			return;
		}

		if (!list || typeof config.page !== 'number') {
			return;
		}

		var errorMessage = container.querySelector('.ctp-events__more-error');
		if (errorMessage) {
			errorMessage.hidden = true;
		}

		button.disabled = true;
		button.setAttribute('aria-busy', 'true');

		// The month the list currently ends in, so the server can continue the
		// divider sequence instead of repeating a heading — matters when a
		// "limit" cap splits a single month across two steps.
		var dividers = list.querySelectorAll('.ctp-events__month-divider');
		var lastMonth = dividers.length ? dividers[dividers.length - 1].getAttribute('data-ctp-month') : '';

		var params = new URLSearchParams({
			action: config.action,
			page: config.page,
			offset: config.offset || 0,
			layout: config.layout,
			columns: config.columns,
			click: config.click,
			month_dividers: config.month_dividers,
			months: config.months,
			limit: config.limit,
			calendars: (config.calendars || []).join(','),
			last_month: lastMonth || '',
		});

		fetch(config.endpoint + '?' + params.toString(), { credentials: 'same-origin' })
			.then(function (response) {
				if (!response.ok) {
					throw new Error('HTTP ' + response.status);
				}

				return response.json();
			})
			.then(function (payload) {
				if (!payload || !payload.success || !payload.data) {
					throw new Error('unexpected response');
				}

				list.insertAdjacentHTML('beforeend', payload.data.html);

				// null means the server found nothing beyond this window —
				// the button has done its job and would otherwise sit there
				// as a control that can only ever no-op.
				if (typeof payload.data.next_page !== 'number') {
					button.parentElement.remove();
				} else {
					config.page = payload.data.next_page;
					config.offset = payload.data.next_offset || 0;
					button.setAttribute('data-ctp-paging', JSON.stringify(config));
					button.disabled = false;
					button.removeAttribute('aria-busy');
				}

				// An active filter or search must apply to the freshly
				// appended items too, not just to what was there on load.
				applyToolbarState(container);
			})
			.catch(function () {
				button.disabled = false;
				button.removeAttribute('aria-busy');

				if (errorMessage) {
					errorMessage.hidden = false;
				}
			});
	}

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
