/**
 * Design tab: drag&drop reordering of the tile element list (including
 * admin-inserted spacer/divider separators), plus a live preview that
 * updates instantly (no AJAX/reload) as the admin drags rows, adds/removes a
 * separator, or toggles the corner-style radios. Mirrors
 * CardDesign::cssVariables()/isValidOrder()/renderSeparators() in JS —
 * duplicated on purpose, since this only ever drives the client-side
 * preview, never persisted data (the actual save still goes through the
 * hidden input + PHP sanitizer on form submit).
 */
(function () {
	'use strict';

	var ELEMENT_KEYS = ['media', 'calendar', 'title', 'subtitle', 'excerpt', 'meta'];
	var SEPARATOR_TYPES = ['spacer', 'divider'];
	var labels = window.ctpDesignLabels || { divider: 'Trennlinie', spacer: 'Abstand', remove: 'Entfernen' };

	var list = document.getElementById('ctp-design-order');
	var hiddenInput = document.getElementById('ctp-design-order-input');
	var preview = document.getElementById('ctp-design-preview');
	var previewContent = document.getElementById('ctp-design-preview-content');
	var cornerInputs = document.querySelectorAll('input[name="ctp_settings[corner_style]"]');
	var addDividerButton = document.getElementById('ctp-design-add-divider');
	var addSpacerButton = document.getElementById('ctp-design-add-spacer');

	if (!list || !hiddenInput) {
		return;
	}

	var dragged = null;
	var separatorCounter = 0;

	function isSeparator(key) {
		return SEPARATOR_TYPES.some(function (type) {
			return key.indexOf(type + '-') === 0;
		});
	}

	function separatorType(key) {
		return key.split('-')[0];
	}

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

	// Delegated: the remove button exists on separator <li>s rendered by PHP
	// on load and on ones this script appends via addSeparator() below.
	list.addEventListener('click', function (event) {
		var removeButton = event.target.closest('.ctp-order-item__remove');
		if (!removeButton) {
			return;
		}

		var item = removeButton.closest('li[draggable]');
		if (item) {
			item.remove();
			syncOrderInput();
			updatePreview();
		}
	});

	function addSeparator(type) {
		separatorCounter += 1;
		var key = type + '-' + Date.now().toString(36) + separatorCounter;

		var item = document.createElement('li');
		item.setAttribute('draggable', 'true');
		item.setAttribute('data-key', key);
		item.className = 'ctp-order-item ctp-order-item--separator';

		var handle = document.createElement('span');
		handle.className = 'dashicons dashicons-menu';
		handle.setAttribute('aria-hidden', 'true');
		item.appendChild(handle);
		item.appendChild(document.createTextNode(labels[type] || type));

		var removeButton = document.createElement('button');
		removeButton.type = 'button';
		removeButton.className = 'ctp-order-item__remove';
		removeButton.setAttribute('aria-label', labels.remove);
		removeButton.innerHTML = '&times;';
		item.appendChild(removeButton);

		list.appendChild(item);
		syncOrderInput();
		updatePreview();
	}

	if (addDividerButton) {
		addDividerButton.addEventListener('click', function () {
			addSeparator('divider');
		});
	}

	if (addSpacerButton) {
		addSpacerButton.addEventListener('click', function () {
			addSeparator('spacer');
		});
	}

	function syncOrderInput() {
		var keys = Array.prototype.map.call(list.querySelectorAll('li[data-key]'), function (item) {
			return item.getAttribute('data-key');
		});
		hiddenInput.value = keys.join(',');
	}

	function currentOrder() {
		var value = hiddenInput.value.split(',').filter(Boolean);
		var fixedKeys = value.filter(function (key) {
			return !isSeparator(key);
		});
		var isValidPermutation = fixedKeys.length === ELEMENT_KEYS.length
			&& fixedKeys.slice().sort().join(',') === ELEMENT_KEYS.slice().sort().join(',');

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

		var contentPositions = order
			.filter(function (key) {
				return key !== 'media';
			})
			.map(function (key) {
				return position[key];
			});

		preview.style.setProperty('--ctp-order-media', position.media);
		preview.style.setProperty('--ctp-order-content', Math.min.apply(null, contentPositions));
		preview.style.setProperty('--ctp-order-calendar', position.calendar);
		preview.style.setProperty('--ctp-order-title', position.title);
		preview.style.setProperty('--ctp-order-subtitle', position.subtitle);
		preview.style.setProperty('--ctp-order-excerpt', position.excerpt);
		preview.style.setProperty('--ctp-order-meta', position.meta);

		if (currentCornerStyle() === 'square') {
			preview.style.setProperty('--ctp-radius', '0px');
		} else {
			preview.style.removeProperty('--ctp-radius');
		}

		renderPreviewSeparators(order, position);
	}

	/**
	 * Separators have no dedicated markup in the preview card (unlike the six
	 * fixed elements, which are already there and just get re-ordered via CSS
	 * vars above) — mirrors CardDesign::renderSeparators() by rebuilding them
	 * as real DOM nodes with their order baked into an inline style.
	 */
	function renderPreviewSeparators(order, position) {
		if (!previewContent) {
			return;
		}

		Array.prototype.forEach.call(
			previewContent.querySelectorAll('.ctp-events__divider, .ctp-events__spacer'),
			function (node) {
				node.remove();
			}
		);

		order.filter(isSeparator).forEach(function (key) {
			var type = separatorType(key);
			var node = document.createElement(type === 'divider' ? 'hr' : 'span');
			node.className = type === 'divider' ? 'ctp-events__divider' : 'ctp-events__spacer';
			node.style.order = position[key];
			if (type === 'spacer') {
				node.setAttribute('aria-hidden', 'true');
			}
			previewContent.appendChild(node);
		});
	}

	cornerInputs.forEach(function (input) {
		input.addEventListener('change', updatePreview);
	});
})();
