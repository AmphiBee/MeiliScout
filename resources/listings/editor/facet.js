/**
 * The meiliscout/facet block in the editor: where its values come from, how
 * they combine, and a preview of them.
 */
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useEntityRecords } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';

import { useEditorConfig } from './config';

const Preview = ( { attributes, taxonomy, label } ) => {
	const name = attributes.source.startsWith( 'taxonomy:' )
		? attributes.source.slice( 9 )
		: null;
	const { records } = useEntityRecords(
		'taxonomy',
		name || 'category',
		{
			per_page: attributes.limit || 6,
			hide_empty: true,
			orderby: 'count',
			order: 'desc',
		},
		{ enabled: Boolean( name ) }
	);

	if ( ! attributes.source ) {
		return (
			<p>
				{ __(
					'Choose what this facet filters on, in the block settings.',
					'meiliscout'
				) }
			</p>
		);
	}

	if ( attributes.type === 'range' ) {
		return (
			<div className="meiliscout-range">
				<label className="meiliscout-range__bound">
					<span className="meiliscout-range__label">
						{ __( 'Min', 'meiliscout' ) }
					</span>
					<input
						className="meiliscout-range__input"
						type="number"
						disabled
					/>
				</label>
				<label className="meiliscout-range__bound">
					<span className="meiliscout-range__label">
						{ __( 'Max', 'meiliscout' ) }
					</span>
					<input
						className="meiliscout-range__input"
						type="number"
						disabled
					/>
				</label>
			</div>
		);
	}

	// A field's values are only known once the listing runs: placeholders
	let options = [ 1, 2, 3 ].map( ( n ) => ( {
		id: n,
		/* translators: %d: a number */
		name: sprintf( __( 'Value %d', 'meiliscout' ), n ),
		count: '',
	} ) );
	if ( attributes.type === 'boolean' ) {
		options = [ { id: 1, name: label, count: '' } ];
	} else if ( name ) {
		options = records || [];
	}

	return (
		<ul className="meiliscout-facet__options">
			{ options.map( ( option ) => (
				<li key={ option.id } className="meiliscout-facet__option">
					<label className="meiliscout-facet__label">
						<input
							className="meiliscout-facet__input"
							type="checkbox"
							disabled
						/>{ ' ' }
						<span className="meiliscout-facet__text">
							{ option.name }
						</span>{ ' ' }
						<span className="meiliscout-facet__count">
							{ option.count }
						</span>
					</label>
				</li>
			) ) }
			{ taxonomy?.hierarchical && attributes.hierarchy === 'tree' && (
				<li className="meiliscout-facet__option" aria-hidden="true">
					…
				</li>
			) }
		</ul>
	);
};

export default function FacetEdit( { attributes, setAttributes, clientId } ) {
	const config = useEditorConfig();
	const postTypes = useSelect(
		( select ) => {
			const editor = select( 'core/block-editor' );
			const listing = editor
				.getBlockParentsByBlockName( clientId, 'meiliscout/listing' )
				.pop();
			return listing
				? editor.getBlockAttributes( listing ).postTypes
				: [];
		},
		[ clientId ]
	);

	const taxonomies = ( config?.taxonomies || [] ).filter( ( taxonomy ) =>
		taxonomy.postTypes.some( ( type ) => postTypes.includes( type ) )
	);
	const taxonomy = taxonomies.find(
		( t ) => 'taxonomy:' + t.name === attributes.source
	);
	const isMeta = attributes.source.startsWith( 'meta:' );
	const isAuthor = attributes.source === 'author';
	let label = attributes.label || taxonomy?.label;
	if ( ! label ) {
		label = isMeta
			? attributes.source.slice( 5 )
			: __( 'Facet', 'meiliscout' );
		if ( isAuthor ) {
			label = __( 'Author', 'meiliscout' );
		}
	}

	const sources = [
		{ value: '', label: __( 'Choose…', 'meiliscout' ) },
		...taxonomies.map( ( t ) => ( {
			value: 'taxonomy:' + t.name,
			label: t.label,
		} ) ),
		{ value: 'author', label: __( 'Author', 'meiliscout' ) },
		...( config?.metaKeys || [] ).map( ( key ) => ( {
			value: 'meta:' + key,
			/* translators: %s: a meta key */
			label: sprintf( __( 'Field: %s', 'meiliscout' ), key ),
		} ) ),
	];

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Facet', 'meiliscout' ) }>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Filters on', 'meiliscout' ) }
						value={ attributes.source }
						options={ sources }
						onChange={ ( source ) =>
							setAttributes( {
								source,
								type:
									source.startsWith( 'taxonomy:' ) ||
									source === 'author'
										? 'list'
										: attributes.type,
								// A post has one author
								logic:
									source === 'author'
										? 'or'
										: attributes.logic,
							} )
						}
					/>
					{ isMeta && (
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Kind', 'meiliscout' ) }
							value={ attributes.type }
							options={ [
								{
									value: 'list',
									label: __( 'Values to tick', 'meiliscout' ),
								},
								{
									value: 'range',
									label: __(
										'A range of numbers',
										'meiliscout'
									),
								},
								{
									value: 'boolean',
									label: __( 'Yes or no', 'meiliscout' ),
								},
							] }
							onChange={ ( type ) => setAttributes( { type } ) }
						/>
					) }
					{ attributes.type === 'list' && ! isAuthor && (
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Several values match', 'meiliscout' ) }
							value={ attributes.logic }
							options={ [
								{
									value: 'or',
									label: __(
										'Any of them (or)',
										'meiliscout'
									),
								},
								{
									value: 'and',
									label: __(
										'All of them (and)',
										'meiliscout'
									),
								},
							] }
							onChange={ ( logic ) => setAttributes( { logic } ) }
						/>
					) }
					{ taxonomy?.hierarchical && (
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Terms', 'meiliscout' ) }
							value={ attributes.hierarchy }
							options={ [
								{
									value: 'tree',
									label: __(
										'A tree, a term counting its children',
										'meiliscout'
									),
								},
								{
									value: 'flat',
									label: __( 'A flat list', 'meiliscout' ),
								},
							] }
							onChange={ ( hierarchy ) =>
								setAttributes( { hierarchy } )
							}
						/>
					) }
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Title', 'meiliscout' ) }
						value={ attributes.label }
						placeholder={ label }
						onChange={ ( value ) =>
							setAttributes( { label: value } )
						}
					/>
					{ attributes.type === 'list' && (
						<RangeControl
							__nextHasNoMarginBottom
							label={ __(
								'Values shown before “Show more” (0: all)',
								'meiliscout'
							) }
							value={ attributes.limit }
							min={ 0 }
							max={ 50 }
							onChange={ ( limit ) => setAttributes( { limit } ) }
						/>
					) }
					{ attributes.type === 'list' && (
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Search in its values', 'meiliscout' ) }
							help={ __(
								'A field above the values narrows them as visitors type: for a long list.',
								'meiliscout'
							) }
							checked={ !! attributes.search }
							onChange={ ( search ) =>
								setAttributes( { search } )
							}
						/>
					) }
					{ attributes.type === 'range' && (
						<RangeControl
							__nextHasNoMarginBottom
							label={ __( 'Decimals', 'meiliscout' ) }
							value={ attributes.decimals }
							min={ 0 }
							max={ 6 }
							onChange={ ( decimals ) =>
								setAttributes( { decimals } )
							}
						/>
					) }
				</PanelBody>
				<PanelBody
					title={ __( 'URL', 'meiliscout' ) }
					initialOpen={ false }
				>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Name in the URL', 'meiliscout' ) }
						help={ __(
							'Changing it breaks the links already shared.',
							'meiliscout'
						) }
						value={ attributes.param }
						placeholder={ attributes.source.replace(
							/^(taxonomy|meta):/,
							''
						) }
						onChange={ ( param ) => setAttributes( { param } ) }
					/>
					{ attributes.source.startsWith( 'taxonomy:' ) &&
						attributes.type === 'list' && (
							<>
								<ToggleControl
									__nextHasNoMarginBottom
									label={ __( 'In the path', 'meiliscout' ) }
									help={ __(
										'Its values go in the path (/projects/type-redesign/) rather than a parameter: with one value, a view search engines may index.',
										'meiliscout'
									) }
									checked={ !! attributes.path }
									onChange={ ( on ) =>
										setAttributes( {
											path: on
												? attributes.param ||
												  attributes.source.replace(
														/^taxonomy:/,
														''
												  )
												: '',
										} )
									}
								/>
								{ !! attributes.path && (
									<TextControl
										__nextHasNoMarginBottom
										label={ __(
											'Prefix in the path',
											'meiliscout'
										) }
										help={ __(
											'Lowercase letters, digits and dashes: {prefix}-{value}.',
											'meiliscout'
										) }
										value={ attributes.path }
										onChange={ ( path ) =>
											setAttributes( {
												path: path
													.toLowerCase()
													.replace(
														/[^a-z0-9-]/g,
														''
													),
											} )
										}
									/>
								) }
							</>
						) }
				</PanelBody>
			</InspectorControls>
			<fieldset
				{ ...useBlockProps( {
					className:
						'meiliscout-facet meiliscout-facet--' + attributes.type,
				} ) }
			>
				<legend className="meiliscout-facet__title">{ label }</legend>
				<Preview
					attributes={ attributes }
					taxonomy={ taxonomy }
					label={ label }
				/>
			</fieldset>
		</>
	);
}
