/**
 * The meiliscout/suggest block in the editor: a preview of the field, and
 * its sources and options in the block's panel.
 */
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	CheckboxControl,
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { useSuggestConfig } from './config';

/**
 * A list of names with one more or one less.
 *
 * @param {string[]} list
 * @param {string}   name
 * @param {boolean}  on
 * @return {string[]} The new list.
 */
const toggled = ( list, name, on ) =>
	on ? [ ...list, name ] : list.filter( ( item ) => item !== name );

export default function SuggestEdit( { attributes, setAttributes } ) {
	const config = useSuggestConfig();
	const {
		postTypes,
		taxonomies,
		postsLimit,
		termsLimit,
		label,
		placeholder,
		minChars,
		recent,
		resultsUrl,
		transport,
	} = attributes;

	// A new block suggests the first indexed post type
	useEffect( () => {
		if (
			config?.postTypes?.length &&
			! postTypes.length &&
			! taxonomies.length
		) {
			setAttributes( { postTypes: [ config.postTypes[ 0 ].name ] } );
		}
	}, [ config ] ); // eslint-disable-line react-hooks/exhaustive-deps

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Suggestions', 'meiliscout' ) }>
					{ config?.error && (
						<p>
							{ __(
								'The indexed content could not be read.',
								'meiliscout'
							) }
						</p>
					) }
					<fieldset style={ { margin: '0 0 16px' } }>
						<legend style={ { fontWeight: 600, marginBottom: 8 } }>
							{ __( 'Posts', 'meiliscout' ) }
						</legend>
						{ ( config?.postTypes || [] ).map( ( type ) => (
							<CheckboxControl
								key={ type.name }
								__nextHasNoMarginBottom
								label={ type.label }
								checked={ postTypes.includes( type.name ) }
								onChange={ ( on ) =>
									setAttributes( {
										postTypes: toggled(
											postTypes,
											type.name,
											on
										),
									} )
								}
							/>
						) ) }
					</fieldset>
					{ postTypes.length > 0 && (
						<RangeControl
							__nextHasNoMarginBottom
							label={ __( 'Posts shown', 'meiliscout' ) }
							value={ postsLimit }
							min={ 1 }
							max={ 12 }
							onChange={ ( value ) =>
								setAttributes( { postsLimit: value } )
							}
						/>
					) }
					<fieldset style={ { margin: '16px 0' } }>
						<legend style={ { fontWeight: 600, marginBottom: 8 } }>
							{ __( 'Terms', 'meiliscout' ) }
						</legend>
						{ ( config?.taxonomies || [] ).map( ( taxonomy ) => (
							<CheckboxControl
								key={ taxonomy.name }
								__nextHasNoMarginBottom
								label={ taxonomy.label }
								checked={ taxonomies.includes( taxonomy.name ) }
								onChange={ ( on ) =>
									setAttributes( {
										taxonomies: toggled(
											taxonomies,
											taxonomy.name,
											on
										),
									} )
								}
							/>
						) ) }
					</fieldset>
					{ taxonomies.length > 0 && (
						<RangeControl
							__nextHasNoMarginBottom
							label={ __( 'Terms shown', 'meiliscout' ) }
							value={ termsLimit }
							min={ 1 }
							max={ 8 }
							onChange={ ( value ) =>
								setAttributes( { termsLimit: value } )
							}
						/>
					) }
				</PanelBody>
				<PanelBody
					title={ __( 'Field', 'meiliscout' ) }
					initialOpen={ false }
				>
					<TextControl
						__nextHasNoMarginBottom
						label={ __(
							'Label (for screen readers)',
							'meiliscout'
						) }
						value={ label }
						onChange={ ( value ) =>
							setAttributes( { label: value } )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Placeholder', 'meiliscout' ) }
						value={ placeholder }
						onChange={ ( value ) =>
							setAttributes( { placeholder: value } )
						}
					/>
					<RangeControl
						__nextHasNoMarginBottom
						label={ __(
							'Characters before suggesting',
							'meiliscout'
						) }
						value={ minChars }
						min={ 1 }
						max={ 5 }
						onChange={ ( value ) =>
							setAttributes( { minChars: value } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Recent searches', 'meiliscout' ) }
						help={ __(
							'Kept in the visitor’s browser only.',
							'meiliscout'
						) }
						checked={ recent }
						onChange={ ( value ) =>
							setAttributes( { recent: value } )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Results page', 'meiliscout' ) }
						help={ __(
							'The site’s search page when left empty.',
							'meiliscout'
						) }
						type="url"
						value={ resultsUrl }
						onChange={ ( value ) =>
							setAttributes( { resultsUrl: value } )
						}
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Search through', 'meiliscout' ) }
						help={ __(
							'WordPress is slower, and for a Meilisearch that browsers cannot reach.',
							'meiliscout'
						) }
						value={ transport }
						options={ [
							{
								value: 'direct',
								label: __(
									'Meilisearch, directly',
									'meiliscout'
								),
							},
							{
								value: 'rest',
								label: __( 'WordPress', 'meiliscout' ),
							},
						] }
						onChange={ ( value ) =>
							setAttributes( { transport: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				<div className="meiliscout-suggest">
					<div className="meiliscout-suggest__form">
						<input
							className="meiliscout-suggest__input"
							type="search"
							disabled
							placeholder={
								placeholder || __( 'Search…', 'meiliscout' )
							}
						/>
						<button
							className="meiliscout-suggest__submit"
							type="button"
							disabled
						>
							<svg
								aria-hidden="true"
								focusable="false"
								width="18"
								height="18"
								viewBox="0 0 24 24"
							>
								<path
									fill="none"
									stroke="currentColor"
									strokeWidth="2"
									strokeLinecap="round"
									d="M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zm9 16-4.2-4.2"
								/>
							</svg>
						</button>
					</div>
				</div>
			</div>
		</>
	);
}
