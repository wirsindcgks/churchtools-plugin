import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, TextControl, SelectControl, RangeControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';

registerBlockType(metadata.name, {
	edit: ({ attributes, setAttributes }) => {
		const { calendarIds, layout, limit, columns } = attributes;
		const blockProps = useBlockProps();

		return (
			<div {...blockProps}>
				<InspectorControls>
					<PanelBody title={__('Einstellungen', 'churchtools-plugin')}>
						<TextControl
							label={__('Kalender-IDs (kommagetrennt)', 'churchtools-plugin')}
							value={calendarIds.join(',')}
							onChange={(value) =>
								setAttributes({
									calendarIds: value
										.split(',')
										.map((id) => parseInt(id.trim(), 10))
										.filter((id) => !Number.isNaN(id)),
								})
							}
						/>
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
					</PanelBody>
				</InspectorControls>
				<p>{__('ChurchTools Events (Vorschau erscheint im Frontend)', 'churchtools-plugin')}</p>
			</div>
		);
	},
	save: () => null,
});
