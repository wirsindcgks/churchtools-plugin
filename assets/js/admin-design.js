/**
 * Design tab: drag&drop reordering of the tile element list, plus a live
 * preview that updates instantly (no AJAX/reload) as the admin drags rows or
 * toggles the corner-style radios. Mirrors CardDesign::cssVariables()'s
 * logic in JS — duplicated on purpose, since this only ever drives the
 * client-side preview, never persisted data (the actual save still goes
 * through the hidden input + PHP sanitizer on form submit).
 */
(function () {
	'use strict';

	var ELEMENT_KEYS = ['media', 'title', 'subtitle', 'meta'];

	var list = document.getElementById('ctp-design-order');
	var hiddenInput = document.getElementById('ctp-design-order-input');
	var preview = document.getElementById('ctp-design-preview');
	var cornerInputs = document.querySelectorAll('input[name="ctp_settings[corner_style]"]');

	if (!list || !hiddenInput) {
		return;
	}

	var dragged = null;

	list.addEventListener('dragstart', function (event) {
		var item = event.target.closest('li[draggable]');
		if (!item) {
			return;
		}
		dragged = item;
		event.dataTransfer.effectAllowed = 'move';
	});

	list.addEventListener('dragover', function (event) {
		var target = event.target.closest('li[draggable]');
		if (!dragged || !target || target === dragged) {
			return;
		}
		event.preventDefault();

		var rect = target.getBoundingClientRect();
		var isAfter = event.clientY - rect.top > rect.height / 2;
		list.insertBefore(dragged, isAfter ? target.nextSibling : target);
	});

	list.addEventListener('drop', function (event) {
		event.preventDefault();
	});

	list.addEventListener('dragend', function () {
		dragged = null;
		syncOrderInput();
		updatePreview();
	});

	function syncOrderInput() {
		var keys = Array.prototype.map.call(list.querySelectorAll('li[data-key]'), function (item) {
			return item.getAttribute('data-key');
		});
		hiddenInput.value = keys.join(',');
	}

	function currentOrder() {
		var value = hiddenInput.value.split(',').filter(Boolean);
		var isValidPermutation = value.length === ELEMENT_KEYS.length
			&& value.slice().sort().join(',') === ELEMENT_KEYS.slice().sort().join(',');

		return isValidPermutation ? value : ELEMENT_KEYS;
	}

	function currentCornerStyle() {
		for (var i = 0; i < cornerInputs.length; i++) {
			if (cornerInputs[i].checked) {
				return cornerInputs[i].value;
			}
		}
		return 'rounded';
	}

	function updatePreview() {
		if (!preview) {
			return;
		}

		var order = currentOrder();
		var position = {};
		order.forEach(function (key, index) {
			position[key] = index;
		});
		var contentOrder = Math.min(position.title, position.subtitle, position.meta);

		preview.style.setProperty('--ctp-order-media', position.media);
		preview.style.setProperty('--ctp-order-content', contentOrder);
		preview.style.setProperty('--ctp-order-title', position.title);
		preview.style.setProperty('--ctp-order-subtitle', position.subtitle);
		preview.style.setProperty('--ctp-order-meta', position.meta);

		if (currentCornerStyle() === 'square') {
			preview.style.setProperty('--ctp-radius', '0px');
		} else {
			preview.style.removeProperty('--ctp-radius');
		}
	}

	cornerInputs.forEach(function (input) {
		input.addEventListener('change', updatePreview);
	});
})();
