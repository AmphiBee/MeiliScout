/**
 * The meiliscout/listing block in the editor: the listing's settings, its
 * facets and parts, and the core's Post Template and pagination.
 */
import apiFetch from '@wordpress/api-fetch';
import {
	InnerBlocks,
	InspectorControls,
	useBlockProps,
	useInnerBlocksProps,
} from '@wordpress/block-editor';
import { getBlockType } from '@wordpress/blocks';
import {
	Button,
	CheckboxControl,
	Notice,
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { useEditorConfig } from './config';

const ORDERBY = [
	{ value: 'date', label: __( 'Date', 'meiliscout' ) },
	{ value: 'modified', label: __( 'Last modified', 'meiliscout' ) },
	{ value: 'title', label: __( 'Title', 'meiliscout' ) },
	{ value: 'menu_order', label: __( 'Order', 'meiliscout' ) },
	{ value: 'comment_count', label: __( 'Comments', 'meiliscout' ) },
	{ value: 'meta_value_num', label: __( 'A number field', 'meiliscout' ) },
	{ value: 'meta_value', label: __( 'A text field', 'meiliscout' ) },
];

const TEMPLATE = [
	[
		'core/columns',
		{},
		[
			[
				'core/column',
				{ width: '28%' },
				[
					[ 'meiliscout/listing-part', { part: 'search' } ],
					[ 'meiliscout/facet', {} ],
					[ 'meiliscout/listing-part', { part: 'apply' } ],
				],
			],
			[
				'core/column',
				{ width: '72%' },
				[
					[ 'meiliscout/listing-part', { part: 'total' } ],
					[ 'meiliscout/listing-part', { part: 'active' } ],
					[
						'core/post-template',
						{ layout: { type: 'grid', columnCount: 2 } },
						[
							[ 'core/post-title', { isLink: true } ],
							[ 'core/post-excerpt' ],
						],
					],
					[
						'core/query-pagination',
						{},
						[
							[ 'core/query-pagination-previous' ],
							[ 'core/query-pagination-numbers' ],
							[ 'core/query-pagination-next' ],
						],
					],
				],
			],
		],
	],
];

const randomId = () => Math.random().toString( 36 ).slice( 2, 10 );

/**
 * Every block under a block, depth first.
 *
 * @param {Object[]} blocks
 * @return {Object[]} The blocks.
 */
const descendants = ( blocks ) =>
	blocks.flatMap( ( block ) => [
		block,
		...descendants( block.innerBlocks ),
	] );

/**
 * Whether the router can keep a block's page in place (design §10): the
 * blocks inside the Post Template must allow client navigation.
 *
 * @param {string} name
 * @return {boolean} Whether it can.
 */
const allowsClientNavigation = ( name ) => {
	const interactivity = getBlockType( name )?.supports?.interactivity;
	return interactivity === true || Boolean( interactivity?.clientNavigation );
};

const SortsPanel = ( { attributes, setAttributes, metaKeys } ) => {
	const sorts = attributes.sorts.length
		? attributes.sorts
		: [
				{
					key: 'date',
					label: __( 'Newest first', 'meiliscout' ),
					orderby: 'date',
					order: 'DESC',
				},
		  ];
	const update = ( index, changes ) =>
		setAttributes( {
			sorts: sorts.map( ( sort, i ) =>
				i === index ? { ...sort, ...changes } : sort
			),
		} );

	return (
		<PanelBody title={ __( 'Sorts', 'meiliscout' ) } initialOpen={ false }>
			{ sorts.map( ( sort, index ) => (
				<div key={ index } className="meiliscout-editor-sort">
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Label', 'meiliscout' ) }
						value={ sort.label }
						onChange={ ( label ) => update( index, { label } ) }
					/>
					<TextControl
						__nextHasNoMarginBottom
						label={ __( 'Key in the URL', 'meiliscout' ) }
						value={ sort.key }
						onChange={ ( key ) => update( index, { key } ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Order by', 'meiliscout' ) }
						value={ sort.orderby }
						options={ ORDERBY }
						onChange={ ( orderby ) => update( index, { orderby } ) }
					/>
					{ sort.orderby.startsWith( 'meta_value' ) && (
						<SelectControl
							__nextHasNoMarginBottom
							label={ __( 'Field', 'meiliscout' ) }
							value={ sort.metaKey || '' }
							options={ [
								{ value: '', label: '—' },
								...metaKeys.map( ( key ) => ( {
									value: key,
									label: key,
								} ) ),
							] }
							onChange={ ( metaKey ) =>
								update( index, { metaKey } )
							}
						/>
					) }
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Direction', 'meiliscout' ) }
						value={ sort.order }
						options={ [
							{
								value: 'DESC',
								label: __( 'Descending', 'meiliscout' ),
							},
							{
								value: 'ASC',
								label: __( 'Ascending', 'meiliscout' ),
							},
						] }
						onChange={ ( order ) => update( index, { order } ) }
					/>
					<Button
						variant="link"
						isDestructive
						onClick={ () =>
							setAttributes( {
								sorts: sorts.filter( ( s, i ) => i !== index ),
							} )
						}
					>
						{ __( 'Remove this sort', 'meiliscout' ) }
					</Button>
				</div>
			) ) }
			<Button
				variant="secondary"
				onClick={ () =>
					setAttributes( {
						sorts: [
							...sorts,
							{
								key: 'title',
								label: __( 'Title', 'meiliscout' ),
								orderby: 'title',
								order: 'ASC',
							},
						],
					} )
				}
			>
				{ __( 'Add a sort', 'meiliscout' ) }
			</Button>
			{ sorts.length > 1 && (
				<SelectControl
					__nextHasNoMarginBottom
					label={ __( 'Default sort', 'meiliscout' ) }
					value={ attributes.defaultSort || sorts[ 0 ].key }
					options={ sorts.map( ( sort ) => ( {
						value: sort.key,
						label: sort.label,
					} ) ) }
					onChange={ ( defaultSort ) =>
						setAttributes( { defaultSort } )
					}
				/>
			) }
		</PanelBody>
	);
};

export default function ListingEdit( { attributes, setAttributes, clientId } ) {
	const config = useEditorConfig();
	const [ errors, setErrors ] = useState( [] );
	const { __unstableMarkNextChangeAsNotPersistent: notPersistent } =
		useDispatch( 'core/block-editor' );

	const { inner, duplicated } = useSelect(
		( select ) => {
			const editor = select( 'core/block-editor' );
			const listings = editor
				.getClientIdsWithDescendants()
				.map( ( id ) => editor.getBlock( id ) )
				.filter(
					( block ) =>
						block?.name === 'meiliscout/listing' &&
						block.attributes.listingId === attributes.listingId
				);
			return {
				inner: descendants(
					editor.getBlock( clientId )?.innerBlocks || []
				),
				// A copy keeps its original's id: the first one keeps it
				duplicated:
					listings.length > 1 && listings[ 0 ].clientId !== clientId,
			};
		},
		[ clientId, attributes.listingId ]
	);

	// An id of its own, once: the listing's id in its URLs' endpoints
	useEffect( () => {
		if ( ! attributes.listingId || duplicated ) {
			notPersistent();
			setAttributes( {
				listingId: randomId(),
				queryId: Math.floor( Math.random() * 1000000 ),
			} );
		}
	}, [ attributes.listingId, duplicated ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// The query the core's Post Template previews with
	useEffect( () => {
		const query = {
			...attributes.query,
			perPage: attributes.perPage,
			postType: attributes.postTypes[ 0 ] || 'post',
			inherit: false,
		};
		if ( JSON.stringify( query ) !== JSON.stringify( attributes.query ) ) {
			notPersistent();
			setAttributes( { query } );
		}
	}, [ attributes.perPage, attributes.postTypes ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const facets = useMemo(
		() =>
			inner
				.filter( ( block ) => block.name === 'meiliscout/facet' )
				.map( ( block ) => block.attributes ),
		[ inner ]
	);

	// What the server would refuse
	const signature = JSON.stringify( [
		attributes.postTypes,
		attributes.perPage,
		attributes.sorts,
		attributes.defaultSort,
		facets,
	] );
	useEffect( () => {
		const timer = setTimeout( () => {
			apiFetch( {
				path: '/meiliscout/v1/listings/validate',
				method: 'POST',
				data: { attributes, facets },
			} ).then(
				( result ) => setErrors( result.errors || [] ),
				() => setErrors( [] )
			);
		}, 400 );
		return () => clearTimeout( timer );
	}, [ signature ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const unsafe = useMemo( () => {
		const names = new Set();
		inner
			.filter( ( block ) => block.name === 'core/post-template' )
			.forEach( ( template ) =>
				descendants( template.innerBlocks ).forEach( ( block ) => {
					if ( ! allowsClientNavigation( block.name ) ) {
						names.add(
							getBlockType( block.name )?.title || block.name
						);
					}
				} )
			);
		return [ ...names ];
	}, [ inner ] );

	const blockProps = useBlockProps( { className: 'meiliscout-listing' } );
	const innerBlocksProps = useInnerBlocksProps( blockProps, {
		template: TEMPLATE,
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Listing', 'meiliscout' ) }>
					{ config?.unavailable && (
						<Notice status="warning" isDismissible={ false }>
							{ config.unavailable === 'disabled'
								? __(
										'Front listings are off: turn them on in MeiliScout › Settings › Listings.',
										'meiliscout'
								  )
								: __(
										'Front listings need a more recent WordPress.',
										'meiliscout'
								  ) }
						</Notice>
					) }
					<fieldset>
						<legend>{ __( 'Content types', 'meiliscout' ) }</legend>
						{ ( config?.postTypes || [] ).map( ( type ) => (
							<CheckboxControl
								__nextHasNoMarginBottom
								key={ type.name }
								label={ type.label }
								checked={ attributes.postTypes.includes(
									type.name
								) }
								onChange={ ( on ) =>
									setAttributes( {
										postTypes: on
											? [
													...attributes.postTypes,
													type.name,
											  ]
											: attributes.postTypes.filter(
													( name ) =>
														name !== type.name
											  ),
									} )
								}
							/>
						) ) }
					</fieldset>
					<RangeControl
						__nextHasNoMarginBottom
						label={ __( 'Items per page', 'meiliscout' ) }
						value={ attributes.perPage }
						min={ 1 }
						max={ 100 }
						onChange={ ( perPage ) => setAttributes( { perPage } ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Filters apply', 'meiliscout' ) }
						value={ attributes.apply }
						options={ [
							{
								value: 'instant',
								label: __( 'At once', 'meiliscout' ),
							},
							{
								value: 'button',
								label: __(
									'With a button, counts shown before',
									'meiliscout'
								),
							},
						] }
						onChange={ ( apply ) => setAttributes( { apply } ) }
					/>
					<SelectControl
						__nextHasNoMarginBottom
						label={ __( 'Results change', 'meiliscout' ) }
						value={ attributes.transport }
						options={ [
							{
								value: 'fragment',
								label: __( 'In place', 'meiliscout' ),
							},
							{
								value: 'page',
								label: __(
									'By loading the page',
									'meiliscout'
								),
							},
						] }
						onChange={ ( transport ) =>
							setAttributes( { transport } )
						}
					/>
				</PanelBody>
				<SortsPanel
					attributes={ attributes }
					setAttributes={ setAttributes }
					metaKeys={ config?.metaKeys || [] }
				/>
			</InspectorControls>
			<div { ...innerBlocksProps }>
				{ errors.length > 0 && (
					<Notice status="error" isDismissible={ false }>
						<p>
							{ __(
								'This listing cannot be served:',
								'meiliscout'
							) }
						</p>
						<ul>
							{ errors.map( ( error ) => (
								<li key={ error }>{ error }</li>
							) ) }
						</ul>
					</Notice>
				) }
				{ unsafe.length > 0 && (
					<Notice status="warning" isDismissible={ false }>
						{ sprintf(
							/* translators: %s: names of blocks */
							__(
								'These blocks of the Post Template do not allow results to change in place, the page will be loaded instead: %s.',
								'meiliscout'
							),
							unsafe.join( ', ' )
						) }
					</Notice>
				) }
				{ innerBlocksProps.children }
			</div>
		</>
	);
}

export const save = () => <InnerBlocks.Content />;
