import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { post } from '../api';
import { Switch } from '../components';

const FIELD_LABELS = {
	post_title: __( 'Title', 'meiliscout' ),
	'terms.name': __( 'Terms', 'meiliscout' ),
	post_excerpt: __( 'Excerpt', 'meiliscout' ),
	content_text: __( 'Content, without markup', 'meiliscout' ),
	post_content: __( 'Content, raw HTML', 'meiliscout' ),
	post_name: __( 'Slug', 'meiliscout' ),
};

// Always shown; the others appear once weighed, or added
const BASE = [ 'post_title', 'terms.name', 'post_excerpt', 'content_text' ];

const PRESETS = {
	balanced: {
		label: __( 'Balanced', 'meiliscout' ),
		weights: {
			post_title: 4,
			'terms.name': 2,
			post_excerpt: 1.5,
			content_text: 1,
		},
	},
	title: {
		label: __( 'Title first', 'meiliscout' ),
		weights: {
			post_title: 8,
			'terms.name': 3,
			post_excerpt: 2,
			content_text: 1,
		},
	},
	content: {
		label: __( 'Content heavy', 'meiliscout' ),
		weights: {
			post_title: 3,
			'terms.name': 2,
			post_excerpt: 2,
			content_text: 4,
		},
	},
};

const labelOf = ( field, names ) => {
	const key = field.replace( /^metas\./, '' );

	return (
		FIELD_LABELS[ field ] ??
		names[ key ] ??
		sprintf(
			/* translators: %s: a meta key */
			__( 'Field %s', 'meiliscout' ),
			key
		)
	);
};

/**
 * What the top results of a search are with the weights as they are now, and what ranked each.
 *
 * @param {Object} props
 * @param {string} props.type    The post type tested.
 * @param {Object} props.profile Its weights, saved or not.
 * @param {Object} props.names   Names of custom fields by meta key.
 * @return {Element} The panel.
 */
const LiveTest = ( { type, profile, names } ) => {
	const [ query, setQuery ] = useState( '' );
	const [ hits, setHits ] = useState( null );
	const timer = useRef();
	const typed = query.trim();
	const key = JSON.stringify( [ type, typed, profile ] );

	useEffect( () => {
		clearTimeout( timer.current );
		if ( typed.length < 2 ) {
			setHits( null );
			return;
		}
		timer.current = setTimeout( () => {
			post( '/content/weights/preview', {
				type,
				q: typed,
				weights: profile,
			} )
				.then( ( response ) => setHits( response.hits ) )
				.catch( () => setHits( [] ) );
		}, 350 );

		return () => clearTimeout( timer.current );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ key ] );

	return (
		<div className="ms-weights__test">
			<label htmlFor="ms-weights-test" className="ms-field__label">
				{ __( 'Try a search', 'meiliscout' ) }
			</label>
			<input
				id="ms-weights-test"
				className="ms-input"
				type="search"
				value={ query }
				placeholder={ __(
					'Type a search to see the ranking…',
					'meiliscout'
				) }
				onChange={ ( event ) => setQuery( event.target.value ) }
			/>
			{ hits !== null && hits.length === 0 && (
				<p className="ms-inline-note">
					{ __( 'No result with these weights.', 'meiliscout' ) }
				</p>
			) }
			{ hits !== null && hits.length > 0 && (
				<ol className="ms-weights__hits">
					{ hits.map( ( hit, index ) => (
						<li key={ hit.id }>
							<span className="ms-weights__rank">
								{ index + 1 }
							</span>
							<span className="ms-weights__hit">
								<strong>{ hit.title }</strong>
								<small>
									{ sprintf(
										/* translators: 1: field names, 2: a weight */
										__(
											'Found in %1$s (weight %2$s)',
											'meiliscout'
										),
										hit.fields
											.map( ( field ) =>
												labelOf( field, names )
											)
											.join( ', ' ),
										hit.weight
									) }
								</small>
							</span>
						</li>
					) ) }
				</ol>
			) }
			<p className="ms-inline-note">
				{ __(
					'A field added to the index counts after the next indexation.',
					'meiliscout'
				) }
			</p>
		</div>
	);
};

/**
 * Weights of the fields a search looks into, per post type: a switch and a
 * slider from 0 to 10 for each, presets, and a live test.
 *
 * @param {Object}   props
 * @param {string[]} props.types     Post types indexed.
 * @param {Object}   props.labels    Post type labels by name.
 * @param {Object}   props.weights   Weights by post type, then field.
 * @param {Object}   props.defaults  What a type is searched with by default.
 * @param {string[]} props.available Fields that can be weighed.
 * @param {Object}   props.names     Names of custom fields by meta key.
 * @param {Function} props.onChange  Called with the new weights.
 * @return {Element} The section's body.
 */
const Weights = ( {
	types,
	labels,
	weights,
	defaults,
	available,
	names,
	onChange,
} ) => {
	const [ active, setActive ] = useState( types[ 0 ] );
	const type = types.includes( active ) ? active : types[ 0 ];

	if ( ! type ) {
		return (
			<p className="ms-inline-note" style={ { margin: '16px 24px' } }>
				{ __(
					'Turn a post type on to weigh its fields.',
					'meiliscout'
				) }
			</p>
		);
	}

	const profile = weights[ type ] ?? defaults;
	const set = ( next ) => onChange( { ...weights, [ type ]: next } );
	const setWeight = ( field, value ) =>
		set( { ...profile, [ field ]: value } );
	const rows = [ ...new Set( [ ...BASE, ...Object.keys( profile ) ] ) ];
	const total = rows.reduce(
		( sum, field ) => sum + ( profile[ field ] ?? 0 ),
		0
	);
	const unused = available.filter( ( field ) => ! rows.includes( field ) );

	return (
		<>
			<div
				className="ms-weights__tabs"
				role="tablist"
				aria-label={ __( 'Post types', 'meiliscout' ) }
			>
				{ types.map( ( name ) => (
					<button
						key={ name }
						type="button"
						role="tab"
						className="ms-chip"
						aria-selected={ name === type }
						onClick={ () => setActive( name ) }
					>
						{ labels[ name ] ?? name }
					</button>
				) ) }
			</div>

			<div className="ms-weights">
				<div className="ms-weights__main">
					<div className="ms-weights__presets">
						<span>{ __( 'Presets', 'meiliscout' ) }</span>
						{ Object.entries( PRESETS ).map( ( [ id, preset ] ) => (
							<button
								key={ id }
								type="button"
								className="ms-button ms-button--small"
								onClick={ () =>
									set( { ...profile, ...preset.weights } )
								}
							>
								{ preset.label }
							</button>
						) ) }
						<button
							type="button"
							className="ms-button ms-button--small"
							onClick={ () => set( defaults ) }
						>
							{ __( 'Reset', 'meiliscout' ) }
						</button>
					</div>

					<ul className="ms-weights__rows">
						{ rows.map( ( field ) => {
							const weight = profile[ field ] ?? 0;
							const name = labelOf( field, names );

							return (
								<li
									key={ field }
									className={ weight === 0 ? 'is-off' : '' }
								>
									<Switch
										checked={ weight > 0 }
										onChange={ ( on ) =>
											setWeight( field, on ? 1 : 0 )
										}
										label={ sprintf(
											/* translators: %s: a field name */
											__( 'Search in %s', 'meiliscout' ),
											name
										) }
									/>
									<span className="ms-weights__name">
										<strong>{ name }</strong>
										<span className="ms-mono">
											{ field }
										</span>
									</span>
									<input
										type="range"
										min="0"
										max="10"
										step="0.5"
										value={ weight }
										aria-label={ sprintf(
											/* translators: %s: a field name */
											__( 'Weight of %s', 'meiliscout' ),
											name
										) }
										onChange={ ( event ) =>
											setWeight(
												field,
												Number( event.target.value )
											)
										}
									/>
									<input
										type="number"
										className="ms-input ms-weights__number"
										min="0"
										max="10"
										step="0.5"
										value={ weight }
										aria-label={ sprintf(
											/* translators: %s: a field name */
											__(
												'Weight of %s, as a number',
												'meiliscout'
											),
											name
										) }
										onChange={ ( event ) =>
											setWeight(
												field,
												Math.min(
													10,
													Math.max(
														0,
														Number(
															event.target.value
														) || 0
													)
												)
											)
										}
									/>
									<span
										className="ms-weights__share"
										aria-hidden="true"
									>
										<span
											style={ {
												width:
													total > 0
														? ( weight / total ) *
																100 +
														  '%'
														: 0,
											} }
										/>
									</span>
								</li>
							);
						} ) }
					</ul>

					{ unused.length > 0 && (
						<div className="ms-weights__add">
							<label
								htmlFor="ms-weights-add"
								className="ms-visually-hidden"
							>
								{ __( 'Weigh one more field', 'meiliscout' ) }
							</label>
							<select
								id="ms-weights-add"
								value=""
								onChange={ ( event ) =>
									event.target.value &&
									setWeight( event.target.value, 1 )
								}
							>
								<option value="">
									{ __(
										'Weigh one more field…',
										'meiliscout'
									) }
								</option>
								{ unused.map( ( field ) => (
									<option key={ field } value={ field }>
										{ labelOf( field, names ) }
									</option>
								) ) }
							</select>
						</div>
					) }
				</div>

				<LiveTest type={ type } profile={ profile } names={ names } />
			</div>
		</>
	);
};

export default Weights;
