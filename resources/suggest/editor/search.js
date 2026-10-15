/**
 * The core Search block, turned into a suggest field declared in PHP: a
 * setting in its panel, saved as its `meiliscoutSuggest` attribute (the field's id).
 */
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl } from '@wordpress/components';
import { createHigherOrderComponent } from '@wordpress/compose';
import { addFilter } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';

import { useSuggestConfig } from './config';

addFilter(
	'blocks.registerBlockType',
	'meiliscout/search-suggest',
	( settings, name ) =>
		name === 'core/search'
			? {
					...settings,
					attributes: {
						...settings.attributes,
						meiliscoutSuggest: { type: 'string', default: '' },
					},
			  }
			: settings
);

const withSuggestControl = createHigherOrderComponent(
	( BlockEdit ) =>
		function SearchSuggestEdit( props ) {
			const config = useSuggestConfig();

			if ( props.name !== 'core/search' || ! config?.suggests?.length ) {
				return <BlockEdit { ...props } />;
			}

			return (
				<>
					<BlockEdit { ...props } />
					<InspectorControls>
						<PanelBody
							title={ __(
								'Suggestions (MeiliScout)',
								'meiliscout'
							) }
							initialOpen={ false }
						>
							<SelectControl
								__nextHasNoMarginBottom
								label={ __(
									'Suggest as the visitor types',
									'meiliscout'
								) }
								help={ __(
									'A suggest field declared with meiliscout_register_suggest().',
									'meiliscout'
								) }
								value={
									props.attributes.meiliscoutSuggest || ''
								}
								options={ [
									{
										value: '',
										label: __( 'None', 'meiliscout' ),
									},
									...config.suggests.map( ( id ) => ( {
										value: id,
										label: id,
									} ) ),
								] }
								onChange={ ( value ) =>
									props.setAttributes( {
										meiliscoutSuggest: value,
									} )
								}
							/>
						</PanelBody>
					</InspectorControls>
				</>
			);
		},
	'withSuggestControl'
);

addFilter(
	'editor.BlockEdit',
	'meiliscout/search-suggest-control',
	withSuggestControl
);
