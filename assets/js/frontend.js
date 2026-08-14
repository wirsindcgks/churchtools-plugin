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
})();
