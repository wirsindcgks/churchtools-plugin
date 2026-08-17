import { registerBlockType } from '@wordpress/blocks';
import { Fragment } from '@wordpress/element';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	CheckboxControl,
	SelectControl,
	RangeControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __, sprintf } from '@wordpress/i18n';
import metadata from './block.json';

// Localized by EventListBlock::localizeCalendars() from the calendars already
// fetched into the plugin settings — the editor has no ChurchTools access of its
// own. Empty when nothing's been fetched yet (see the fallback hint below).
const knownCalendars = window.ctpBlockCalendars || [];

registerBlockType(metadata.name, {
	edit: ({ attributes, setAttributes }) => {
		const {
			calendarIds,
			layout,
			limit,
			columns,
			click,
			filter,
			search,
			monthDividers,
			eventfinder,
			months,
			paging,
		} = attributes;
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
							min={0}
							label={__('Maximale Anzahl Events (0 = unbegrenzt)', 'churchtools-plugin')}
							help={
								layout === 'upcoming'
									? __('Anzahl der Termine inklusive Hero-Kachel.', 'churchtools-plugin')
									: __(
											'Nur als Obergrenze pro Nachlade-Schritt. Wie viel angezeigt wird, bestimmt der Zeitraum.',
											'churchtools-plugin'
										)
							}
							value={limit}
							onChange={(value) => setAttributes({ limit: Math.max(0, parseInt(value, 10) || 0) })}
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
						{layout !== 'upcoming' && (
							<Fragment>
								<ToggleControl
									label={__('Eventfinder anzeigen', 'churchtools-plugin')}
									help={__(
										'„Du suchst …“-Buttons für Kalender/Zeitraum plus Suche — ersetzt Kalenderfilter und Suchleiste unten.',
										'churchtools-plugin'
									)}
									checked={eventfinder}
									onChange={(value) => setAttributes({ eventfinder: value })}
								/>
								{!eventfinder && (
									<Fragment>
										<ToggleControl
											label={__('Kalenderfilter anzeigen', 'churchtools-plugin')}
											checked={filter}
											onChange={(value) => setAttributes({ filter: value })}
										/>
										<ToggleControl
											label={__('Suchleiste anzeigen', 'churchtools-plugin')}
											checked={search}
											onChange={(value) => setAttributes({ search: value })}
										/>
									</Fragment>
								)}
								<ToggleControl
									label={__('Termine nach Monat gruppieren', 'churchtools-plugin')}
									checked={monthDividers}
									onChange={(value) => setAttributes({ monthDividers: value })}
								/>
								<ToggleControl
									label={__('Nachladen-Button anzeigen', 'churchtools-plugin')}
									help={__(
										'Lädt jeweils den nächsten Zeitraum nach, ohne die Seite neu zu laden.',
										'churchtools-plugin'
									)}
									checked={paging}
									onChange={(value) => setAttributes({ paging: value })}
								/>
								<TextControl
									type="number"
									min={0}
									max={24}
									label={__('Zeitraum pro Seite in Monaten (0 = Standard)', 'churchtools-plugin')}
									help={__(
										'Überschreibt die globale Einstellung im Plugin-Tab „Design“ nur für diesen Block.',
										'churchtools-plugin'
									)}
									value={months}
									onChange={(value) =>
										setAttributes({ months: Math.min(24, Math.max(0, parseInt(value, 10) || 0)) })
									}
								/>
							</Fragment>
						)}
					</PanelBody>
				</InspectorControls>
				<ServerSideRender block={metadata.name} attributes={attributes} />
			</div>
		);
	},
	save: () => null,
});
