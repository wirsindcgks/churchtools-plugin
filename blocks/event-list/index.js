import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, CheckboxControl, SelectControl, RangeControl, TextControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __, sprintf } from '@wordpress/i18n';
import metadata from './block.json';

// Localized by EventListBlock::localizeCalendars() from the calendars already
// fetched into the plugin settings — the editor has no ChurchTools access of its
// own. Empty when nothing's been fetched yet (see the fallback hint below).
const knownCalendars = window.ctpBlockCalendars || [];

registerBlockType(metadata.name, {
	edit: ({ attributes, setAttributes }) => {
		const { calendarIds, layout, limit, columns, click } = attributes;
		const blockProps = useBlockProps();

		// Union of the known calendars and any IDs already saved on this block
		// instance — a calendar later removed from settings, or not fetched yet,
		// must still show up as checked instead of silently disappearing.
		const knownIds = knownCalendars.map((calendar) => calendar.id);
		const unknownCalendars = calendarIds
			.filter((id) => !knownIds.includes(id))
			.map((id) => ({ id, name: sprintf(__('#%d (unbekannt)', 'churchtools-plugin'), id) }));
		const calendarOptions = [...knownCalendars, ...unknownCalendars];

		const toggleCalendar = (id, checked) => {
			setAttributes({
				calendarIds: checked ? [...calendarIds, id] : calendarIds.filter((existingId) => existingId !== id),
			});
		};

		return (
			<div {...blockProps}>
				<InspectorControls>
					<PanelBody title={__('Einstellungen', 'churchtools-plugin')}>
						<p>{__('Kalender (leer = alle aktiven Kalender)', 'churchtools-plugin')}</p>
						{calendarOptions.length === 0 ? (
							<p>
								{__(
									'Keine Kalender geladen. Im Plugin-Tab „Kalender“ zuerst Kalender laden.',
									'churchtools-plugin'
								)}
							</p>
						) : (
							calendarOptions.map((calendar) => (
								<CheckboxControl
									key={calendar.id}
									label={calendar.name}
									checked={calendarIds.includes(calendar.id)}
									onChange={(checked) => toggleCalendar(calendar.id, checked)}
								/>
							))
						)}
						<SelectControl
							label={__('Ansicht', 'churchtools-plugin')}
							value={layout}
							options={[
								{ label: __('Liste', 'churchtools-plugin'), value: 'list' },
								{ label: __('Grid', 'churchtools-plugin'), value: 'grid' },
								{ label: __('Nächster Termin', 'churchtools-plugin'), value: 'upcoming' },
							]}
							onChange={(value) => setAttributes({ layout: value })}
						/>
						{layout === 'grid' && (
							<RangeControl
								label={__('Spalten', 'churchtools-plugin')}
								value={columns}
								onChange={(value) => setAttributes({ columns: value })}
								min={2}
								max={6}
							/>
						)}
						<TextControl
							type="number"
							label={__('Anzahl Events', 'churchtools-plugin')}
							value={limit}
							onChange={(value) => setAttributes({ limit: parseInt(value, 10) || 10 })}
						/>
						<SelectControl
							label={__('Klickverhalten', 'churchtools-plugin')}
							value={click}
							options={[
								{ label: __('Standard (Design-Einstellung)', 'churchtools-plugin'), value: 'default' },
								{ label: __('Keine', 'churchtools-plugin'), value: 'none' },
								{ label: __('Popup', 'churchtools-plugin'), value: 'popup' },
								{ label: __('Eigene Seite', 'churchtools-plugin'), value: 'page' },
							]}
							onChange={(value) => setAttributes({ click: value })}
						/>
					</PanelBody>
				</InspectorControls>
				<ServerSideRender block={metadata.name} attributes={attributes} />
			</div>
		);
	},
	save: () => null,
});
