import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { post, errorMessage } from '../api';
import { Banner, Pill } from '../components';
import { number } from '../format';
import { href } from '../routes';

const DEBOUNCE = 250;

const toggledIn = ( list, value, on ) =>
	on ? [ ...list, value ] : list.filter( ( item ) => item !== value );

const Facet = ( { name, legend, values, selected, onToggle } ) => (
	<fieldset>
		<legend>{ legend }</legend>
		{ values.map( ( value ) => (
			<label
				className="ms-facet"
				key={ value.value }
				htmlFor={ `ms-facet-${ name }-${ value.value }` }
			>
				<input
					id={ `ms-facet-${ name }-${ value.value }` }
					type="checkbox"
					checked={ selected.includes( value.value ) }
					onChange={ ( event ) =>
						onToggle( value.value, event.target.checked )
					}
				/>
				<span className="ms-facet__label">{ value.label }</span>
				<span className="ms-facet__count">
					{ number( value.count ) }
				</span>
			</label>
		) ) }
	</fieldset>
);

/**
 * Keeps the facet values selected, even when the results no longer hold them.
 *
 * @param {Array} values   Values of the facet in the results.
 * @param {Array} selected Values selected.
 * @return {Array} The values to list.
 */
const withSelected = ( values, selected ) => [
	...values,
	...selected
		.filter(
			( value ) => ! values.some( ( item ) => item.value === value )
		)
		.map( ( value ) => ( { value, label: value, count: 0 } ) ),
];

const Hit = ( { hit } ) => (
	<article className="ms-card ms-hit">
		<div className="ms-hit__head">
			<Pill tone="info">{ hit.type_label }</Pill>
			{ /* Escaped on the server, <mark> around the matches */ }
			<h3 dangerouslySetInnerHTML={ { __html: hit.title } } />
			<span className="ms-hit__id">
				{ sprintf(
					/* translators: %d: a post ID */
					__( 'ID %d', 'meiliscout' ),
					hit.id
				) }
			</span>
		</div>
		{ hit.excerpt && (
			<p
				className="ms-hit__excerpt"
				dangerouslySetInnerHTML={ { __html: hit.excerpt } }
			/>
		) }
		<div className="ms-hit__foot">
			{ hit.terms.map( ( term ) => (
				<Pill tone="outline" key={ term }>
					{ term }
				</Pill>
			) ) }
			<span className="ms-hit__links">
				{ hit.url && (
					<a href={ hit.url } target="_blank" rel="noreferrer">
						{ __( 'View', 'meiliscout' ) }
					</a>
				) }
				{ hit.edit_url && (
					<a href={ hit.edit_url }>{ __( 'Edit', 'meiliscout' ) }</a>
				) }
			</span>
		</div>
	</article>
);

const SearchPreview = () => {
	const [ query, setQuery ] = useState( '' );
	const [ postTypes, setPostTypes ] = useState( [] );
	const [ terms, setTerms ] = useState( {} );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ loading, setLoading ] = useState( true );
	const request = useRef( 0 );

	useEffect( () => {
		const id = ++request.current;
		setLoading( true );

		const timer = setTimeout( () => {
			post( '/search', { q: query, post_types: postTypes, terms } )
				.then( ( response ) => {
					// An older search answering late is dropped
					if ( id === request.current ) {
						setResult( response );
						setError( null );
					}
				} )
				.catch(
					( reason ) =>
						id === request.current &&
						setError( errorMessage( reason ) )
				)
				.finally( () => id === request.current && setLoading( false ) );
		}, DEBOUNCE );

		return () => clearTimeout( timer );
	}, [ query, postTypes, terms ] );

	const toggleTerm = ( taxonomy, slug, on ) =>
		setTerms( {
			...terms,
			[ taxonomy ]: toggledIn( terms[ taxonomy ] ?? [], slug, on ),
		} );

	return (
		<div className="ms-wrap ms-page" style={ { gap: 20 } }>
			<div className="ms-search-bar">
				<label htmlFor="ms-search" className="ms-visually-hidden">
					{ __( 'Search', 'meiliscout' ) }
				</label>
				<input
					id="ms-search"
					type="search"
					className="ms-input ms-input--large"
					value={ query }
					onChange={ ( event ) => setQuery( event.target.value ) }
					placeholder={ __(
						'Search the indexed content',
						'meiliscout'
					) }
					// eslint-disable-next-line jsx-a11y/no-autofocus
					autoFocus
				/>
				<span className="ms-hint" aria-live="polite">
					{ result &&
						sprintf(
							/* translators: 1: number of results, 2: milliseconds */
							_n(
								'%1$s result in %2$s ms',
								'%1$s results in %2$s ms',
								result.total,
								'meiliscout'
							),
							number( result.total ),
							number( result.processing_time_ms )
						) }
				</span>
			</div>

			{ error && <Banner tone="error" icon="alert" title={ error } /> }

			{ result && (
				<div className="ms-search-layout" aria-busy={ loading }>
					<aside
						className="ms-facets"
						aria-label={ __( 'Filters', 'meiliscout' ) }
					>
						<Facet
							name="post_type"
							legend={ __( 'Post type', 'meiliscout' ) }
							values={ withSelected(
								result.facets.post_type,
								postTypes
							) }
							selected={ postTypes }
							onToggle={ ( value, on ) =>
								setPostTypes(
									toggledIn( postTypes, value, on )
								)
							}
						/>
						{ result.facets.taxonomies.map( ( group ) => (
							<Facet
								key={ group.taxonomy }
								name={ group.taxonomy }
								legend={ group.label }
								values={ withSelected(
									group.values,
									terms[ group.taxonomy ] ?? []
								) }
								selected={ terms[ group.taxonomy ] ?? [] }
								onToggle={ ( value, on ) =>
									toggleTerm( group.taxonomy, value, on )
								}
							/>
						) ) }
					</aside>

					<div className="ms-results">
						{ result.hits.map( ( hit ) => (
							<Hit hit={ hit } key={ hit.id } />
						) ) }
						{ result.hits.length === 0 && (
							<p className="ms-empty ms-empty--dashed">
								{ __( 'No results.', 'meiliscout' ) }{ ' ' }
								<a href={ href( 'content', 'types' ) }>
									{ __(
										'Check that the post type is indexed.',
										'meiliscout'
									) }
								</a>
							</p>
						) }
						<details className="ms-details">
							<summary>
								{ __(
									'Request sent to Meilisearch',
									'meiliscout'
								) }
							</summary>
							<pre>
								{ JSON.stringify( result.request, null, 2 ) }
							</pre>
						</details>
					</div>
				</div>
			) }
		</div>
	);
};

export default SearchPreview;
