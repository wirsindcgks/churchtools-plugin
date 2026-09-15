/**
 * Das Feld „Einzelne Gruppen" im WPBakery-Element „ChurchTools Gruppen"
 * (WpBakeryIntegration::renderGroupPicker()).
 *
 * WPBakery fuegt das Bearbeitungsfenster erst beim Oeffnen ins Dokument ein,
 * und womoeglich mehrmals - die Ereignisse haengen deshalb am Dokument und
 * suchen sich ihr Feld selbst, statt beim Laden nach Feldern zu suchen.
 *
 * Gespeichert wird allein das versteckte Feld `.wpb_vc_param_value`: eine
 * kommagetrennte Liste von Gruppen-IDs in der Reihenfolge der Liste
 * „Ausgewaehlt". Die Haken darunter sind nur eine Ansicht dieser Liste.
 */
(function () {
	'use strict';

	function pickerOf(node) {
		return node && node.closest ? node.closest('.ctp-wpb-picker') : null;
	}

	function readIds(picker) {
		var input = picker.querySelector('input.wpb_vc_param_value');

		return (input.value || '')
			.split(',')
			.map(function (part) {
				return parseInt(part, 10);
			})
			.filter(function (id, index, all) {
				return id > 0 && all.indexOf(id) === index;
			});
	}

	function nameFor(picker, id) {
		var box = picker.querySelector('.ctp-wpb-picker__option input[value="' + id + '"]');

		if (box) {
			return { name: box.getAttribute('data-name') || String(id), missing: false };
		}

		return { name: (picker.getAttribute('data-missing-label') || '#%d').replace('%d', String(id)), missing: true };
	}

	function button(action, label, text) {
		var el = document.createElement('button');
		el.type = 'button';
		el.className = 'button-link';
		el.setAttribute('data-action', action);
		el.setAttribute('aria-label', label);
		el.textContent = text;

		return el;
	}

	function write(picker, ids) {
		var input = picker.querySelector('input.wpb_vc_param_value');
		var list = picker.querySelector('.ctp-wpb-picker__order');
		var empty = picker.querySelector('.ctp-wpb-picker__empty');

		input.value = ids.join(',');

		list.textContent = '';
		ids.forEach(function (id) {
			var info = nameFor(picker, id);
			var item = document.createElement('li');
			var name = document.createElement('span');

			item.className = 'ctp-wpb-picker__item' + (info.missing ? ' ctp-wpb-picker__item--missing' : '');
			item.setAttribute('data-id', String(id));
			name.className = 'ctp-wpb-picker__name';
			name.textContent = info.name;
			item.appendChild(name);
			item.appendChild(button('up', picker.getAttribute('data-up-label') || '', '↑'));
			item.appendChild(button('down', picker.getAttribute('data-down-label') || '', '↓'));
			item.appendChild(button('remove', picker.getAttribute('data-remove-label') || '', '×'));
			list.appendChild(item);
		});

		if (empty) {
			empty.hidden = ids.length > 0;
		}

		// Eine Gruppe kann auf zwei Homepages stehen - beide Haken folgen der Liste.
		picker.querySelectorAll('.ctp-wpb-picker__option input').forEach(function (box) {
			box.checked = ids.indexOf(parseInt(box.value, 10)) !== -1;
		});

		// Fuer WPBakerys Abhaengigkeiten und die Vorschau im Frontend-Editor.
		input.dispatchEvent(new Event('change', { bubbles: true }));
	}

	document.addEventListener('change', function (event) {
		var box = event.target;

		if (!box.matches || !box.matches('.ctp-wpb-picker__option input')) {
			return;
		}

		var picker = pickerOf(box);
		var id = parseInt(box.value, 10);
		var ids = readIds(picker).filter(function (existing) {
			return existing !== id;
		});

		// Ein neuer Haken haengt die Gruppe hinten an.
		if (box.checked) {
			ids.push(id);
		}

		write(picker, ids);
	});

	document.addEventListener('click', function (event) {
		var target = event.target.closest ? event.target.closest('.ctp-wpb-picker__item [data-action]') : null;

		if (!target) {
			return;
		}

		event.preventDefault();

		var picker = pickerOf(target);
		var id = parseInt(target.closest('.ctp-wpb-picker__item').getAttribute('data-id'), 10);
		var ids = readIds(picker);
		var index = ids.indexOf(id);
		var action = target.getAttribute('data-action');

		if (index === -1) {
			return;
		}

		if (action === 'remove') {
			ids.splice(index, 1);
		} else {
			var swap = action === 'up' ? index - 1 : index + 1;

			if (swap < 0 || swap >= ids.length) {
				return;
			}

			ids[index] = ids[swap];
			ids[swap] = id;
		}

		write(picker, ids);
	});

	document.addEventListener('input', function (event) {
		var filter = event.target;

		if (!filter.matches || !filter.matches('.ctp-wpb-picker__filter')) {
			return;
		}

		var picker = pickerOf(filter);
		var needle = filter.value.trim().toLocaleLowerCase('de');

		picker.querySelectorAll('.ctp-wpb-picker__homepage').forEach(function (section) {
			var visible = 0;

			section.querySelectorAll('.ctp-wpb-picker__option').forEach(function (option) {
				var match = needle === '' || (option.textContent || '').toLocaleLowerCase('de').indexOf(needle) !== -1;
				option.hidden = !match;
				visible += match ? 1 : 0;
			});

			section.hidden = visible === 0;
		});
	});
})();
