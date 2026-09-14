import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, RangeControl, RadioControl, CheckboxControl, Notice } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __, sprintf } from '@wordpress/i18n';
import metadata from './block.json';

// Localized by GroupListBlock::localizeHomepages() - nur die angehakten
// Homepages und die Gruppen darauf, denn nur für die liegen Daten vor.
const knownHomepages = window.ctpBlockGroupHomepages || [];
const knownGroups = window.ctpBlockGroups || [];

const parseIds = (value) =>
	String(value || '')
		.split(',')
		.map((part) => parseInt(part, 10))
		.filter((id) => id > 0);

registerBlockType(metadata.name, {
	edit: ({ attributes, setAttributes }) => {
		const { source, homepage, groups, layout, columns } = attributes;
		const blockProps = useBlockProps();
		const selectedIds = parseIds(groups);
		const groupsById = new Map(knownGroups.map((group) => [group.id, group]));

		// Eine gespeicherte, inzwischen abgewählte Homepage bleibt sichtbar
		// ausgewählt, statt still auf „keine“ zu springen.
		const homepageOptions = [
			{ label: __('— Homepage wählen —', 'churchtools-plugin'), value: '' },
			...knownHomepages.map((entry) => ({ label: entry.name, value: String(entry.id) })),
		];
		if (homepage !== '' && !homepageOptions.some((option) => option.value === homepage)) {
			homepageOptions.push({ label: sprintf(__('%s (nicht aktiv)', 'churchtools-plugin'), homepage), value: homepage });
		}

		// Die Reihenfolge der Auswahl ist die Reihenfolge auf der Seite: Ein
		// Haken hängt die Gruppe hinten an.
		const toggleGroup = (id, checked) => {
			const next = checked ? [...selectedIds.filter((existing) => existing !== id), id] : selectedIds.filter((existing) => existing !== id);
			setAttributes({ groups: next.join(',') });
		};

		// Gewählte Gruppen, die es nicht mehr gibt (Homepage abgewählt, in
		// ChurchTools nicht mehr öffentlich), bleiben mit Hinweis stehen statt
		// still aus der Auswahl zu fallen - auf der Website fehlen sie ohnehin.
		const missingIds = selectedIds.filter((id) => !groupsById.has(id));

		return (
			<div {...blockProps}>
				<InspectorControls>
					<PanelBody title={__('Auswahl', 'churchtools-plugin')}>
						<RadioControl
							label={__('Welche Gruppen?', 'churchtools-plugin')}
							selected={source}
							options={[
								{ label: __('Alle Gruppen einer Homepage', 'churchtools-plugin'), value: 'homepage' },
								{ label: __('Einzelne Gruppen', 'churchtools-plugin'), value: 'groups' },
							]}
							onChange={(value) => setAttributes({ source: value })}
						/>
						{source === 'homepage' && (
							<>
								{knownHomepages.length === 0 && (
									<p>
										{__(
											'Keine Homepage aktiv. Unter „ChurchTools → Gruppen“ zuerst eine Homepage laden und anhaken.',
											'churchtools-plugin'
										)}
									</p>
								)}
								<SelectControl
									label={__('Gruppen-Homepage', 'churchtools-plugin')}
									value={homepage}
									options={homepageOptions}
									onChange={(value) => setAttributes({ homepage: value })}
								/>
							</>
						)}
						{source === 'groups' && (
							<>
								<p className="components-base-control__help">
									{__(
										'Zur Auswahl stehen die Gruppen der angehakten Homepages. Die Reihenfolge auf der Seite folgt der Reihenfolge der Haken.',
										'churchtools-plugin'
									)}
								</p>
								{missingIds.length > 0 && (
									<Notice status="warning" isDismissible={false}>
										{sprintf(
											__('Nicht mehr verfügbar: %s. Diese Gruppen stehen auf keiner angehakten Homepage mehr und erscheinen nicht.', 'churchtools-plugin'),
											missingIds.map((id) => `#${id}`).join(', ')
										)}
									</Notice>
								)}
								{knownGroups.length === 0 && (
									<p>{__('Noch keine Gruppen abgeglichen.', 'churchtools-plugin')}</p>
								)}
								{knownGroups.map((group) => {
									const position = selectedIds.indexOf(group.id);
									return (
										<CheckboxControl
											key={group.id}
											label={position >= 0 ? `${position + 1}. ${group.name}` : group.name}
											help={group.homepages}
											checked={position >= 0}
											onChange={(checked) => toggleGroup(group.id, checked)}
										/>
									);
								})}
								{missingIds.map((id) => (
									<CheckboxControl
										key={`missing-${id}`}
										label={sprintf(__('#%d (nicht mehr verfügbar)', 'churchtools-plugin'), id)}
										checked
										onChange={() => toggleGroup(id, false)}
									/>
								))}
							</>
						)}
					</PanelBody>
					<PanelBody title={__('Darstellung', 'churchtools-plugin')}>
						<SelectControl
							label={__('Ansicht', 'churchtools-plugin')}
							value={layout}
							options={[
								{ label: __('Raster', 'churchtools-plugin'), value: 'grid' },
								{ label: __('Hervorgehoben', 'churchtools-plugin'), value: 'featured' },
							]}
							help={__('„Hervorgehoben“ zeigt je Gruppe eine große Kachel mit dem ganzen Text – gedacht für wenige Gruppen.', 'churchtools-plugin')}
							onChange={(value) => setAttributes({ layout: value })}
						/>
						{layout === 'grid' && (
							<RangeControl
								label={__('Spalten', 'churchtools-plugin')}
								help={__(
									'Höchstens so viele, wie in den Inhaltsbereich passen – je Kachel mindestens 240px. Für mehr Spalten den Block auf „Weite Breite“ stellen.',
									'churchtools-plugin'
								)}
								value={columns}
								onChange={(value) => setAttributes({ columns: value })}
								min={2}
								max={6}
							/>
						)}
					</PanelBody>
				</InspectorControls>
				<ServerSideRender block={metadata.name} attributes={attributes} />
			</div>
		);
	},
	save: () => null,
});
