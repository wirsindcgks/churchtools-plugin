import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, RangeControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __, sprintf } from '@wordpress/i18n';
import metadata from './block.json';

// Localized by GroupListBlock::localizeHomepages() - nur die angehakten
// Homepages, denn nur für die liegen Gruppen vor.
const knownHomepages = window.ctpBlockGroupHomepages || [];

registerBlockType(metadata.name, {
	edit: ({ attributes, setAttributes }) => {
		const { homepage, columns } = attributes;
		const blockProps = useBlockProps();

		// Eine gespeicherte, inzwischen abgewählte Homepage bleibt sichtbar
		// ausgewählt, statt still auf „keine“ zu springen.
		const options = [
			{ label: __('— Homepage wählen —', 'churchtools-plugin'), value: '' },
			...knownHomepages.map((entry) => ({ label: entry.name, value: String(entry.id) })),
		];
		if (homepage !== '' && !options.some((option) => option.value === homepage)) {
			options.push({ label: sprintf(__('%s (nicht aktiv)', 'churchtools-plugin'), homepage), value: homepage });
		}

		return (
			<div {...blockProps}>
				<InspectorControls>
					<PanelBody title={__('Einstellungen', 'churchtools-plugin')}>
						{knownHomepages.length === 0 && (
							<p>
								{__(
									'Keine Homepage aktiv. Im Plugin-Reiter „Gruppen“ zuerst eine Homepage laden und anhaken.',
									'churchtools-plugin'
								)}
							</p>
						)}
						<SelectControl
							label={__('Gruppen-Homepage', 'churchtools-plugin')}
							value={homepage}
							options={options}
							onChange={(value) => setAttributes({ homepage: value })}
						/>
						<RangeControl
							label={__('Spalten', 'churchtools-plugin')}
							value={columns}
							onChange={(value) => setAttributes({ columns: value })}
							min={2}
							max={6}
						/>
					</PanelBody>
				</InspectorControls>
				<ServerSideRender block={metadata.name} attributes={attributes} />
			</div>
		);
	},
	save: () => null,
});
