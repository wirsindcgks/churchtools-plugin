/**
 * Calendar filter, timeframe shortcuts and search for the list/grid layouts,
 * plus the "load more" paging that appends the next time window.
 *
 * Every one of them answers twice: first in the browser, over the items already
 * in the DOM, so the list reacts instantly and keeps working even when the
 * request below fails or is slow — then from the server, which is the only side
 * that can see past the month window the page loaded. All of it goes over one
 * public read-only endpoint that needs no nonce, because the shortcode's
 * server-rendered output has to survive full-page caching and an embedded nonce
 * would go stale inside it (see EventsEndpoint).
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
		// Once the server has answered (see refreshFromServer()), the list *is*
		// the answer and re-filtering it here could only ever take away from it.
		// That is not hypothetical: the timeframe bounds are computed from the
		// browser's clock here and from the site's clock there, so for a visitor
		// in another timezone this pass would hide events at the edges of a
		// range the server deliberately included.
		var serverAnswered = container.classList.contains('ctp-events--searching');

		var items = container.querySelectorAll('[data-ctp-calendar]');
		var visibleCount = 0;

		items.forEach(function (item) {
			var matchesCalendar = calendarId === '' || item.getAttribute('data-ctp-calendar') === calendarId;
			var matchesSearch = query === '' || (item.getAttribute('data-ctp-search') || '').indexOf(query) !== -1;
			var matchesRange = matchesTimeframe(item.getAttribute('data-ctp-start'), timeframe);
			var visible = serverAnswered || (matchesCalendar && matchesSearch && matchesRange);

			item.hidden = !visible;
			if (visible) {
				visibleCount += 1;
			}
		});

		updateMonthDividers(container);
		updateEmptyMessage(container);
	}

	/**
	 * "Keine Termine gefunden" — but only once that is actually established.
	 *
	 * The client-side pass above can empty the list for a question the server
	 * is about to answer with events: pick a topic whose next date lies beyond
	 * the loaded window and nothing in the DOM matches it, so for the second or
	 * so until the answer arrives the visitor is told there is nothing — the
	 * very dead end the topic list was trimmed to avoid. While a request is in
	 * flight the message therefore stays down; an empty list is only reported
	 * when nobody is still looking.
	 */
	function updateEmptyMessage(container) {
		var message = container.querySelector('.ctp-events__toolbar-empty');
		if (!message) {
			return;
		}

		var visibleCount = 0;
		container.querySelectorAll('[data-ctp-calendar]').forEach(function (item) {
			if (!item.hidden) {
				visibleCount += 1;
			}
		});

		message.hidden = visibleCount !== 0 || pendingRequests.has(container);
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
			refreshFromServer(container);
		}
	});

	/*
	 * Every toolbar question is two-stage. The client-side pass above answers
	 * instantly and without a request (so it still works under full-page
	 * caching) — but it can only ever answer "of what you can already see",
	 * and the DOM holds just the month window the page loaded. So each change
	 * also asks the server the same question against the whole synced horizon
	 * and swaps the answer in.
	 *
	 * This started out as search-only, which left the calendar filter and the
	 * eventfinder's timeframe buttons answering from the loaded window alone:
	 * "Diesen Monat" on a list capped at twelve events showed whichever of the
	 * month's events happened to be among those twelve, and the rest appeared
	 * only after a click on "Weitere Termine laden". They now travel the same
	 * path as the search term — see the endpoint for which combinations come
	 * back complete and which keep a cursor.
	 *
	 * Original markup is stashed on the first such request and restored once
	 * the toolbar is back in its neutral state, so clearing always returns to
	 * the exact paged list the visitor had — including anything they had
	 * already loaded via "Weitere Termine", and that button's own cursor.
	 */
	var searchTimers = new WeakMap();
	var stashedLists = new WeakMap();
	var requestTokens = new WeakMap();
	// Only ever read by updateEmptyMessage() above — declared here with the
	// rest of the per-container state rather than next to its reader, since it
	// is this section that writes it.
	var pendingRequests = new WeakMap();

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

		// Typing fires per keystroke, so this one is debounced; a button or
		// dropdown change is a single deliberate act and goes straight out.
		clearTimeout(searchTimers.get(container));
		searchTimers.set(container, setTimeout(function () {
			refreshFromServer(container);
		}, 300));
	});

	/**
	 * The instance's endpoint configuration, rendered by
	 * EventListRenderer::resultsConfig() onto the toolbar element — whichever
	 * of the two toolbars it is, and whichever controls it happens to contain.
	 * Read by attribute rather than by element so an override is free to put it
	 * elsewhere in the container.
	 */
	function resultsConfig(container) {
		var host = container.querySelector('[data-ctp-toolbar-config]');
		if (!host) {
			return null;
		}

		try {
			var config = JSON.parse(host.getAttribute('data-ctp-toolbar-config') || '{}');

			return config.endpoint ? config : null;
		} catch (error) {
			return null;
		}
	}

	/**
	 * Asks the server the toolbar's current question in full. A neutral toolbar
	 * (no search term worth sending, "Alle", "Jederzeit") asks nothing and puts
	 * the stashed paged list back instead.
	 */
	function refreshFromServer(container) {
		var config = resultsConfig(container);
		var list = container.querySelector('.ctp-events__list');
		// A pending keystroke debounce would only re-ask what is being asked
		// right now — clicking a finder button mid-typing must not cost two
		// requests.
		clearTimeout(searchTimers.get(container));

		if (!config || !list) {
			return;
		}

		var searchInput = container.querySelector('.ctp-events__search-input');
		var query = searchInput ? searchInput.value.trim() : '';
		var calendarId = activeCalendarId(container);
		var timeframe = activeTimeframe(container);
		// Below the minimum length the term is not sent at all (the endpoint
		// rejects it), but a calendar or timeframe next to it still is.
		var search = query.length >= (config.min || 2) ? query : '';

		if (search === '' && calendarId === '' && timeframe === '') {
			restoreStashedList(container, list);

			return;
		}

		if (!stashedLists.has(container)) {
			stashedLists.set(container, {
				html: list.innerHTML,
				paging: pagingState(container),
			});
		}

		var params = new URLSearchParams({
			action: config.action,
			search: search,
			calendar: calendarId || '',
			timeframe: timeframe,
			layout: config.layout,
			columns: config.columns,
			click: config.click,
			// Normalized rather than passed through: a page served from a
			// full-page cache can still carry a configuration from before
			// these three were part of it, and an "undefined" in the query
			// string reads as a *set* flag on the other side.
			month_dividers: config.month_dividers ? 1 : 0,
			months: config.months || 0,
			limit: config.limit || 0,
			calendars: (config.calendars || []).join(','),
		});

		var token = (requestTokens.get(container) || 0) + 1;
		requestTokens.set(container, token);
		// The instant pass has already run at this point (both callers filter
		// first, then ask), so the message may be showing for a question that
		// is still open — take it back down before the browser paints.
		pendingRequests.set(container, token);
		updateEmptyMessage(container);

		fetch(config.endpoint + '?' + params.toString(), { credentials: 'same-origin' })
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				// The toolbar may have moved on while this was in flight — a
				// stale response must not overwrite a newer state.
				if (requestTokens.get(container) !== token) {
					return;
				}

				clearPending(container, token);

				if (!payload || !payload.success || !payload.data) {
					dropOutlook(list);
					updateEmptyMessage(container);

					return;
				}

				list.innerHTML = payload.data.html;
				setResultsMode(container, true);
				// A complete answer (next_page: null) retires the button; a
				// calendar-filtered "Jederzeit" keeps paging, and the cursor it
				// comes back with has to carry the filter into every further
				// click.
				setPagingState(container, {
					page: payload.data.next_page,
					offset: payload.data.next_offset || 0,
					calendar: calendarId || '',
				});
				applyToolbarState(container);
			})
			.catch(function () {
				// Leave the client-side filtered list in place: it is a valid,
				// if narrower, answer to the same question — including, now
				// that nobody is still looking, an empty one.
				clearPending(container, token);
				dropOutlook(list);
				updateEmptyMessage(container);
			});
	}

	/**
	 * Nimmt die Überleitung eines Ausblicks aus der Liste („In diesem Monat
	 * stehen keine Termine mehr an.", siehe partials/timeframe-outlook.php).
	 *
	 * Sie gehört zu genau der Antwort, mit der sie kam. Eine gelungene Antwort
	 * ersetzt sie ohnehin mit `list.innerHTML`; hier geht es um die beiden
	 * Wege, auf denen *keine* Antwort ankommt und die vorige Liste stehen
	 * bleibt — dann stünde der Satz über den Terminen einer Frage, die gar
	 * nicht mehr gestellt ist. Kacheln, die nicht mehr passen, sind dort das
	 * kleinere Übel; ein Satz, der etwas Falsches *behauptet*, ist keins.
	 *
	 * Deshalb erst hier und nicht schon vor der Anfrage: Der Block hält im
	 * Grid über `grid-column: 1 / -1` alle Spalten offen. Nimmt man ihn weg,
	 * solange die Antwort noch unterwegs ist, klappt `auto-fit` die leeren
	 * Spuren ein und die einzelne verbliebene Kachel springt für die Dauer der
	 * Anfrage auf die volle Breite — ein sichtbares Zucken bei jedem Wechsel,
	 * gegen einen Satz, der nur im Fehlerfall überhaupt stehen bleibt.
	 */
	function dropOutlook(list) {
		var outlook = list.querySelector('.ctp-events__outlook');

		if (outlook) {
			outlook.parentNode.removeChild(outlook);
		}
	}

	/**
	 * Clears the in-flight marker, but only if it is still this request's — a
	 * response that lost the race must not report the newer one as settled.
	 */
	function clearPending(container, token) {
		if (pendingRequests.get(container) === token) {
			pendingRequests.delete(container);
		}
	}

	function restoreStashedList(container, list) {
		var stashed = stashedLists.get(container);

		if (stashed) {
			list.innerHTML = stashed.html;
			setPagingState(container, stashed.paging);
			stashedLists.delete(container);
		}
		// Any answer still in flight would land on the restored list — bump the
		// token so it is discarded like any other stale one, and stop waiting
		// on it.
		requestTokens.set(container, (requestTokens.get(container) || 0) + 1);
		pendingRequests.delete(container);
		setResultsMode(container, false);
		applyToolbarState(container);
	}

	/**
	 * Marks that the list is showing a server-side answer rather than the paged
	 * window. Purely a styling hook for themes — which of the two is on screen
	 * is otherwise invisible from CSS.
	 */
	function setResultsMode(container, active) {
		container.classList.toggle('ctp-events--searching', active);
	}

	/**
	 * The load-more button's cursor, as the stash needs to remember it: null
	 * when the instance has no button at all (paging off, or a list that fit
	 * into one page).
	 */
	function pagingState(container) {
		var button = container.querySelector('.ctp-events__load-more');
		if (!button) {
			return null;
		}

		var config = readPagingConfig(button);

		return config
			? {
				page: config.page,
				offset: config.offset || 0,
				calendar: config.calendar || '',
				hidden: !!(button.parentElement && button.parentElement.hidden),
			}
			: null;
	}

	/**
	 * Points the load-more button at a new cursor, or retires it when there is
	 * nothing beyond the current list. Hidden rather than removed, so restoring
	 * a stashed list brings the button back with it.
	 */
	function setPagingState(container, state) {
		var button = container.querySelector('.ctp-events__load-more');
		var more = container.querySelector('.ctp-events__more');
		if (!button || !more) {
			return;
		}

		var config = readPagingConfig(button);
		if (!config) {
			return;
		}

		var exhausted = !state || typeof state.page !== 'number';
		more.hidden = exhausted || !!state.hidden;
		if (exhausted) {
			return;
		}

		config.page = state.page;
		config.offset = state.offset || 0;
		config.calendar = state.calendar || '';
		button.setAttribute('data-ctp-paging', JSON.stringify(config));
		button.disabled = false;
		button.removeAttribute('aria-busy');
	}

	function readPagingConfig(button) {
		try {
			return JSON.parse(button.getAttribute('data-ctp-paging') || '{}');
		} catch (error) {
			return null;
		}
	}

	/**
	 * Eventfinder calendar/timeframe buttons (partials/eventfinder.php): each
	 * `.ctp-events__finder-group` is a mutually-exclusive toggle group (native
	 * `<select>` semantics without a `<select>`, so the eventfinder buttons can
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
		refreshFromServer(container);
	});

	/**
	 * The remaining click targets inside a .ctp-events container:
	 *
	 * - "Weitere Termine laden" (partials/load-more.php) — see loadNextPage().
	 * - "Popup" click behavior: the detail markup for every card is already in
	 *   the page (see the <template class="ctp-events__detail-template">
	 *   embedded per card by EventListRenderer::withCalendarMeta()/templates),
	 *   so clicking a trigger just clones it into the shared <dialog> and calls
	 *   showModal(), no fetch involved. Der Auslöser ist dabei ein Verweis auf
	 *   die Terminseite und kein Knopf - er trägt `data-ctp-modal`, und erst
	 *   dieser Handler macht aus dem Verweis den Dialog (siehe ClickTrigger).
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

		if (trigger && trigger.getAttribute('data-ctp-modal') === '1') {
			/*
			 * Der Auslöser ist auch im Popup-Betrieb ein Verweis auf die
			 * Terminseite (siehe Frontend\ClickTrigger) - nur dieser Zweig
			 * macht daraus den Dialog. Modifier-Klicks bleiben dabei dem
			 * Browser überlassen: Cmd/Strg/Shift/Alt heißt „in neuem Tab",
			 * „in neuem Fenster", „Ziel speichern", und das soll die Adresse
			 * öffnen und nicht ein Fenster, das im neuen Tab gar nicht steht.
			 */
			if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) {
				return;
			}

			event.preventDefault();
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
		var config = readPagingConfig(button);

		if (!list || !config || typeof config.page !== 'number') {
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
			// Written into the config by refreshFromServer() when a calendar
			// filter is active: without it the next page would come back
			// unfiltered and append events the visitor has filtered away.
			calendar: config.calendar || '',
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

				// A null next_page means the server found nothing beyond this
				// window — the button has done its job and would otherwise sit
				// there as a control that can only ever no-op. setPagingState()
				// hides it rather than removing it, so a later filter change
				// that *does* have further pages can put it back to work.
				setPagingState(container, {
					page: payload.data.next_page,
					offset: payload.data.next_offset || 0,
					calendar: config.calendar || '',
				});

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

	/*
	 * Ein Lazyload-Plugin (WP Rocket & Co.) ersetzt beim Ausliefern das src
	 * jedes <img> durch einen Platzhalter und merkt sich die echte Adresse in
	 * data-src/data-lazy-src. Die Bilder im <template> jeder Kachel erwischt es
	 * dabei mit - seinen Beobachter bekommt dieser Klon hier aber nie zu sehen,
	 * das Popup blieb deshalb ohne Bild (auf der Live-Seite blieb ein 0x1 Pixel
	 * grosses Platzhalter-SVG stehen). Beim Klonen also selbst zuruecksetzen,
	 * unabhaengig davon, ob das Plugin die Ausnahme-Kennzeichen im Markup
	 * beachtet hat (siehe partials/event-detail-content.php).
	 */
	function restoreLazyImages(fragment) {
		var images = fragment.querySelectorAll('img');

		Array.prototype.forEach.call(images, function (img) {
			var src = img.getAttribute('data-src') || img.getAttribute('data-lazy-src');
			var srcset = img.getAttribute('data-srcset') || img.getAttribute('data-lazy-srcset');

			if (src) {
				img.setAttribute('src', src);
			}

			if (srcset) {
				img.setAttribute('srcset', srcset);
			}

			img.classList.remove('lazy', 'lazyload', 'lazyloading');
		});
	}

	function openDetailModal(trigger) {
		var unit = trigger.closest('.ctp-events__item, .ctp-events__cell, .ctp-events__hero');
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

		var content = template.content.cloneNode(true);
		restoreLazyImages(content);
		body.appendChild(content);
		dialog.showModal();
	}

	/*
	 * Blaetterknopf und Randverlauf der Knopfleisten (partials/eventfinder.php).
	 *
	 * Auf schmalen Schirmen liegen Themen und Zeitraeume in je einer
	 * einzeiligen Leiste, die seitlich geschoben wird - zwoelf Knoepfe brachen
	 * dort sonst auf sechs Zeilen und der Eventfinder war 65 % des Bildschirms
	 * hoch, bevor ein Termin zu sehen war.
	 *
	 * Dass sich die Reihe schieben laesst, sagt der Verlauf am rechten Rand; er
	 * steht in CSS und damit auch ohne Skript. Der Knopf kommt hier dazu: Er ist
	 * der Weg nach rechts fuer alle, die nicht wischen (Maus, Tastatur), und
	 * steht im Markup mit `hidden` - ohne JavaScript waere er ein Bedienelement,
	 * das nichts tut, und das ist schlimmer als keines.
	 */
	/**
	 * Wie viele Zeilen eine Knopfreihe belegt, und wie viele Knoepfe in der
	 * letzten stehen. Beides ist nur nach dem Layout zu beantworten - deshalb
	 * entscheidet das hier und nicht eine Medienabfrage.
	 */
	/*
	 * Wie viele Knoepfe die letzte Zeile mindestens tragen soll, bevor das
	 * Verengen aufhoert. Zwei waren die erste Fassung und sahen immer noch
	 * angehaengt aus; drei lesen sich als angefangene Zeile (Nutzerentscheidung
	 * 2026-09-02).
	 */
	var ZIEL_LETZTE_ZEILE = 3;

	function finderRows(group) {
		var tops = [];

		Array.prototype.forEach.call(group.querySelectorAll('.ctp-events__finder-btn'), function (button) {
			var top = Math.round(button.getBoundingClientRect().top);

			if (tops.indexOf(top) === -1) {
				tops.push(top);
			}
		});

		return tops;
	}

	function finderLastRowCount(group) {
		var buttons = Array.prototype.slice.call(group.querySelectorAll('.ctp-events__finder-btn'));

		if (buttons.length === 0) {
			return 0;
		}

		var lastTop = Math.round(buttons[buttons.length - 1].getBoundingClientRect().top);

		return buttons.filter(function (button) {
			return Math.round(button.getBoundingClientRect().top) === lastTop;
		}).length;
	}

	/*
	 * Entscheidet je Leiste zwischen Umbruch und Schiebemodus - und raeumt im
	 * Umbruch die haesslichste Variante weg.
	 *
	 * Ab drei Zeilen wird geschoben. Eine feste Fensterbreite als Schwelle ging
	 * daran vorbei: Mit vier Kategorien bricht auf einem Handy gar nichts um,
	 * mit zwanzig auf einem breiten Schirm sehr wohl. Gemessen wird deshalb das
	 * Ergebnis, nicht seine Ursache.
	 *
	 * Bleiben es zwei Zeilen und in der zweiten steht ein *einzelner* Knopf,
	 * wird die Reihe schrittweise schmaler gemacht, bis mindestens zwei
	 * nebeneinander stehen. Ein einzelner Knopf unter einer vollen Zeile sieht
	 * angehaengt aus (Nutzerbefund 2026-09-02); zwei oder drei lesen sich als
	 * angefangene Zeile. Die Untergrenze von 70 % verhindert, dass aus dem
	 * Aufraeumen eine schmale Saeule wird, und entstuende dabei eine dritte
	 * Zeile, wird der letzte Schritt zurueckgenommen.
	 */
	function layoutFinderStrips() {
		var strips = document.querySelectorAll('.ctp-events__finder-strip');

		Array.prototype.forEach.call(strips, function (strip) {
			var group = strip.querySelector('.ctp-events__finder-group');

			if (!group) {
				return;
			}

			// Erst in den Umbruch zuruecksetzen, sonst misst der naechste Lauf
			// den Schiebemodus des vorigen (dort gibt es immer genau eine Zeile).
			strip.classList.remove('ctp-events__finder-strip--scroll');
			group.style.maxWidth = '';

			// Reihen, die immer einzeilig bleiben sollen, stellen keine Frage -
			// sie schieben grundsaetzlich (siehe partials/eventfinder.php).
			if (strip.classList.contains('ctp-events__finder-strip--nowrap')) {
				strip.classList.add('ctp-events__finder-strip--scroll');

				return;
			}

			if (finderRows(group).length >= 3) {
				strip.classList.add('ctp-events__finder-strip--scroll');

				return;
			}

			if (finderRows(group).length !== 2 || finderLastRowCount(group) >= ZIEL_LETZTE_ZEILE) {
				return;
			}

			// Das beste Zwischenergebnis mitfuehren: Wird das Ziel nie erreicht,
			// ist eine letzte Zeile mit zwei Knoepfen immer noch besser als eine
			// mit einem - nur die volle Breite zurueckzunehmen waere Aufgeben.
			var bestesMass = '';
			var bestesErgebnis = finderLastRowCount(group);

			for (var prozent = 96; prozent >= 70; prozent -= 4) {
				group.style.maxWidth = prozent + '%';

				if (finderRows(group).length > 2) {
					break;
				}

				var letzte = finderLastRowCount(group);

				if (letzte >= ZIEL_LETZTE_ZEILE) {
					return;
				}

				if (letzte > bestesErgebnis) {
					bestesErgebnis = letzte;
					bestesMass = prozent + '%';
				}
			}

			group.style.maxWidth = bestesMass;
		});
	}

	function updateFinderScrollButtons() {
		var strips = document.querySelectorAll('.ctp-events__finder-strip');


		Array.prototype.forEach.call(strips, function (strip) {
			var group = strip.querySelector('.ctp-events__finder-group');
			var next = strip.querySelector('.ctp-events__finder-scroll--next');
			var prev = strip.querySelector('.ctp-events__finder-scroll--prev');

			if (!group || !next || !prev) {
				return;
			}

			/*
			 * Der Lauscher haengt direkt an der Leiste und wird einmal gesetzt.
			 * Zuerst stand er delegiert am Dokument in der Erfassungsphase -
			 * fuer scroll, das nicht aufsteigt, ist das der uebliche Weg und
			 * waere auch richtig gewesen. Direkt am Element ist es schlicht
			 * kuerzer und braucht die Erklaerung nicht.
			 */
			if (!group.dataset.ctpScrollWatched) {
				group.dataset.ctpScrollWatched = '1';
				group.addEventListener('scroll', updateFinderScrollButtons, { passive: true });
			}

			// Ein Pixel Toleranz: Bei gebrochenen Breiten liegt scrollLeft an
			// den Anschlaegen regelmaessig knapp daneben, und ein Knopf bliebe
			// sonst dort stehen, wo es nicht weitergeht.
			var amEnde = group.scrollWidth - group.clientWidth - group.scrollLeft <= 1;
			var amAnfang = group.scrollLeft <= 1;

			next.hidden = amEnde;
			prev.hidden = amAnfang;

			// Dieselben Zustaende steuern die Verlaeufe an den Raendern. Die
			// stehen in CSS und damit auch ohne Skript - hier werden sie nur
			// dort weggenommen, wo wirklich nichts mehr kommt.
			strip.classList.toggle('ctp-events__finder-strip--end', amEnde);
			strip.classList.toggle('ctp-events__finder-strip--start', amAnfang);
		});
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest('.ctp-events__finder-scroll');

		if (!button) {
			return;
		}

		var group = button.parentElement.querySelector('.ctp-events__finder-group');

		if (!group) {
			return;
		}

		// Vier Fuenftel statt der vollen Breite, damit der zuletzt sichtbare
		// Knopf nach dem Blaettern noch angeschnitten stehen bleibt - so bleibt
		// erkennbar, dass man sich innerhalb einer Leiste bewegt hat.
		var weite = Math.round(group.clientWidth * 0.8);

		if (button.classList.contains('ctp-events__finder-scroll--prev')) {
			weite = -weite;
		}

		group.scrollBy({ left: weite, behavior: 'smooth' });
	});

	window.addEventListener('resize', function () {
		layoutFinderStrips();
		updateFinderScrollButtons();
	});

	/*
	 * Dreimal gerechnet, und das ist kein Uebermass: Beim ersten Lauf steht das
	 * Skript im Fuss und die Schrift des Themes ist noch nicht geladen - die
	 * Knoepfe sind dann schmaler als spaeter, die Leiste laeuft noch nicht ueber,
	 * und der Blaetterknopf bliebe fuer immer verborgen, weil kein Ereignis
	 * mehr kommt. Genau so ist es beim Nachmessen aufgefallen.
	 */
	function refreshFinderLayout() {
		layoutFinderStrips();
		updateFinderScrollButtons();
	}

	/*
	 * Beobachtet die Breite der Leisten selbst statt nur die des Fensters. Der
	 * Eventfinder steht nicht immer ueber die volle Seite: In einer Spalte, in
	 * einem Seitenbaukasten-Raster oder neben einer ein- und ausklappbaren
	 * Seitenleiste aendert sich seine Breite, ohne dass das Fenster sich
	 * bewegt - `resize` bliebe dann aus, und die Reihe behielte eine
	 * Aufteilung, die zu ihrer alten Breite gehoerte.
	 *
	 * Der Beobachter meldet auch die Aenderung, die die Routine selbst
	 * ausloest (sie setzt max-width). Deshalb der Wachhund: Waehrend die
	 * Routine laeuft, wird ihre eigene Rueckmeldung verworfen - sonst riefen
	 * sich die beiden gegenseitig endlos auf.
	 */
	var layoutLaeuft = false;

	function beobachteLeisten() {
		if (typeof ResizeObserver !== 'function') {
			return;
		}

		var beobachter = new ResizeObserver(function () {
			if (layoutLaeuft) {
				return;
			}

			layoutLaeuft = true;
			refreshFinderLayout();
			// Erst im naechsten Durchlauf freigeben, damit die eigenen
			// Aenderungen dieser Runde nicht als neue Anlaesse zurueckkommen.
			setTimeout(function () {
				layoutLaeuft = false;
			}, 0);
		});

		Array.prototype.forEach.call(document.querySelectorAll('.ctp-events__finder-strip'), function (strip) {
			beobachter.observe(strip);
		});
	}

	refreshFinderLayout();
	beobachteLeisten();
	window.addEventListener('load', refreshFinderLayout);

	if (document.fonts && document.fonts.ready) {
		document.fonts.ready.then(refreshFinderLayout);
	}
})();
