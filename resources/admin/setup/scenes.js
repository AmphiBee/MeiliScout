import { useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { Icon } from '../components';
import { number } from '../format';
import { useCountUp, useReducedMotion, useTyping } from './hooks';

/* The picture beside each step of the setup assistant. Pure decoration:
   every scene is hidden from assistive technology, the step says the same in words. */

const DEMO = [
	{
		query: 'redesign',
		hits: [
			[ 'Redesign of a family practice', 'Redesign · 2026' ],
			[ 'A new site for the town library', 'Redesign · 2025' ],
			[ 'Redesign and faster pages', 'Redesign · 2025' ],
		],
	},
	{
		query: 'shopify',
		hits: [
			[ 'From Shopify to WooCommerce', 'E-commerce · 2026' ],
			[ 'A shop for a tea house', 'E-commerce · 2025' ],
			[ 'Shopify theme, built to last', 'Web app · 2024' ],
		],
	},
	{
		query: 'accessibility',
		hits: [
			[ 'An accessible booking form', 'Web app · 2026' ],
			[ 'Contrast and keyboard, fixed', 'Redesign · 2025' ],
			[ 'Accessibility audit, month one', 'Showcase · 2024' ],
		],
	},
];

const PHRASES = DEMO.map( ( demo ) => demo.query );

/**
 * A search that types itself, and answers.
 */
export const SearchScene = () => {
	const { text, index, done } = useTyping( PHRASES );
	const demo = DEMO[ index ];
	const shown = text.length >= 3;

	return (
		<div className="ms-scene ms-scene--search" aria-hidden="true">
			<div className="ms-glass ms-search">
				<div className="ms-search__field">
					<Icon name="search" size={ 20 } strokeWidth={ 2.4 } />
					<span>
						{ text }
						<i className="ms-caret" />
					</span>
				</div>
				<div className="ms-search__meta">
					{ shown
						? sprintf(
								/* translators: 1: a number of results, 2: milliseconds */
								__( '%1$s results · %2$s ms', 'meiliscout' ),
								done ? 70 - index * 19 : '…',
								done ? 8 + index * 2 : '…'
						  )
						: ' ' }
				</div>
				<ul className="ms-search__hits">
					{ demo.hits.map( ( [ title, meta ], i ) => (
						<li
							key={ index + title }
							className={ shown ? 'is-in' : '' }
							style={ { '--i': i } }
						>
							<strong>{ title }</strong>
							<span>{ meta }</span>
						</li>
					) ) }
				</ul>
			</div>
			<div className="ms-chip-float ms-chip-float--a">WP_Query</div>
			<div className="ms-chip-float ms-chip-float--b">Facets</div>
			<div className="ms-chip-float ms-chip-float--c">Real time</div>
		</div>
	);
};

/**
 * WordPress and Meilisearch, and the wire between them.
 *
 * @param {Object} props
 * @param {string} props.status idle, testing, ok or error.
 */
export const PlugScene = ( { status } ) => (
	<div
		className={ 'ms-scene ms-scene--plug is-' + status }
		aria-hidden="true"
	>
		<div className="ms-node ms-node--wp">
			<span className="ms-node__logo">W</span>
			<span className="ms-node__name">WordPress</span>
		</div>
		<div className="ms-wire">
			<svg viewBox="0 0 200 40" preserveAspectRatio="none">
				<path d="M0 20 H200" className="ms-wire__line" />
				<path d="M0 20 H200" className="ms-wire__flow" />
			</svg>
			<span className="ms-wire__badge">
				{ status === 'ok' && (
					<Icon name="check" size={ 18 } strokeWidth={ 3 } />
				) }
				{ status === 'error' && (
					<Icon name="close" size={ 18 } strokeWidth={ 3 } />
				) }
				{ status === 'testing' && (
					<Icon name="refresh" size={ 18 } strokeWidth={ 2.6 } />
				) }
			</span>
		</div>
		<div className="ms-node ms-node--meili">
			<span className="ms-node__logo">
				<svg
					viewBox="0 0 24 24"
					fill="none"
					stroke="currentColor"
					strokeWidth="2.4"
					strokeLinecap="round"
				>
					<circle cx="10.5" cy="10.5" r="6" />
					<path d="M15 15l5 5" />
				</svg>
			</span>
			<span className="ms-node__name">Meilisearch</span>
		</div>
	</div>
);

/**
 * What will be indexed, as documents piling up.
 *
 * @param {Object} props
 * @param {number} props.count Documents to index.
 * @param {Array}  props.types The chosen types: { label, count }.
 */
export const DocsScene = ( { count, types } ) => {
	const shown = useCountUp( count );

	return (
		<div className="ms-scene ms-scene--docs" aria-hidden="true">
			<div className="ms-docs">
				{ types.slice( 0, 5 ).map( ( type, i ) => (
					<div
						key={ type.name }
						className="ms-glass ms-doc"
						style={ { '--i': i } }
					>
						<span className="ms-doc__lines">
							<i />
							<i />
							<i />
						</span>
						<strong>{ type.label }</strong>
						<em>{ number( type.count ) }</em>
					</div>
				) ) }
				{ types.length === 0 && (
					<div className="ms-glass ms-doc ms-doc--empty">
						<span className="ms-doc__lines">
							<i />
							<i />
							<i />
						</span>
						<strong>{ __( 'Choose a type', 'meiliscout' ) }</strong>
					</div>
				) }
			</div>
			<div className="ms-bignum">
				<strong>{ number( shown ) }</strong>
				<span>{ __( 'documents to index', 'meiliscout' ) }</span>
			</div>
		</div>
	);
};

const ROWS = [
	{
		id: 'search',
		label: () => __( 'Site search', 'meiliscout' ),
		path: '/?s=redesign',
	},
	{
		id: 'archives',
		label: () => __( 'Archives', 'meiliscout' ),
		path: '/category/projects/',
	},
	{
		id: 'rest_search',
		label: () => __( 'REST searches', 'meiliscout' ),
		path: '/wp-json/wp/v2/posts?search=…',
	},
];

/**
 * Who answers each kind of query, as it changes with the switches.
 *
 * @param {Object} props
 * @param {Object} props.served Which kinds of queries Meilisearch serves.
 */
export const QueryScene = ( { served } ) => (
	<div className="ms-scene ms-scene--queries" aria-hidden="true">
		<div className="ms-glass ms-queries">
			{ ROWS.map( ( row ) => (
				<div
					key={ row.id }
					className={
						'ms-queries__row' +
						( served[ row.id ] ? ' is-meili' : '' )
					}
				>
					<div>
						<strong>{ row.label() }</strong>
						<code>{ row.path }</code>
					</div>
					<span className="ms-queries__who">
						<i />
						{ served[ row.id ] ? 'Meilisearch' : 'MySQL' }
					</span>
				</div>
			) ) }
			<p className="ms-queries__note">
				{ __(
					'Never a different result: what Meilisearch cannot answer exactly stays on MySQL.',
					'meiliscout'
				) }
			</p>
		</div>
	</div>
);

/**
 * A listing, lit when the module is on.
 *
 * @param {Object}  props
 * @param {boolean} props.on
 */
export const ListingScene = ( { on } ) => (
	<div
		className={ 'ms-scene ms-scene--listing' + ( on ? ' is-on' : '' ) }
		aria-hidden="true"
	>
		<div className="ms-mock">
			<div className="ms-mock__side">
				<div className="ms-mock__field">
					<Icon name="search" size={ 13 } />
					<span>Search</span>
				</div>
				<h6>Type</h6>
				<p className="is-on">
					<i />
					Redesign<em>29</em>
				</p>
				<p>
					<i />
					Web app<em>11</em>
				</p>
				<p>
					<i />
					E-commerce<em>38</em>
				</p>
				<h6>Year</h6>
				<div className="ms-mock__pills">
					<u>2026</u>
					<u className="is-on">✓ 2025</u>
					<u>2024</u>
				</div>
			</div>
			<div className="ms-mock__main">
				<div className="ms-mock__bar">
					<b>70 results</b>
					<u>Newest first</u>
				</div>
				<div className="ms-mock__chips">
					<u>Redesign ×</u>
					<u>2025 ×</u>
				</div>
				<div className="ms-mock__cards">
					{ [ 0, 1, 2, 3, 4, 5 ].map( ( i ) => (
						<div key={ i } style={ { '--i': i } }>
							<i />
							<i />
							<i />
						</div>
					) ) }
				</div>
			</div>
		</div>
		<span className="ms-scene__tag">
			{ on
				? __( 'Listings on', 'meiliscout' )
				: __( 'Listings off', 'meiliscout' ) }
		</span>
	</div>
);

/**
 * The indexation: documents flowing into the index, a ring that fills.
 *
 * @param {Object}  props
 * @param {number}  props.percent 0 to 100.
 * @param {boolean} props.running Whether documents are flowing.
 * @param {boolean} props.done
 */
export const IndexScene = ( { percent, running, done } ) => {
	const shown = useCountUp( percent, 500 );
	const radius = 92;
	const length = 2 * Math.PI * radius;

	return (
		<div
			className={
				'ms-scene ms-scene--index' +
				( running ? ' is-running' : '' ) +
				( done ? ' is-done' : '' )
			}
			aria-hidden="true"
		>
			<div className="ms-ring">
				<svg viewBox="0 0 220 220">
					<circle
						className="ms-ring__track"
						cx="110"
						cy="110"
						r={ radius }
					/>
					<circle
						className="ms-ring__value"
						cx="110"
						cy="110"
						r={ radius }
						strokeDasharray={ length }
						strokeDashoffset={ length * ( 1 - shown / 100 ) }
					/>
				</svg>
				<div className="ms-ring__center">
					{ done ? (
						<Icon name="check" size={ 64 } strokeWidth={ 2.6 } />
					) : (
						<>
							<strong>{ shown }</strong>
							<span>%</span>
						</>
					) }
				</div>
				{ [ 0, 1, 2, 3, 4, 5, 6, 7 ].map( ( i ) => (
					<span
						key={ i }
						className="ms-particle"
						style={ {
							'--a': i * 45 + 'deg',
							'--d': i * 0.28 + 's',
						} }
					/>
				) ) }
			</div>
		</div>
	);
};

/**
 * The finish: a ring that closes on a check, and confetti.
 */
export const DoneScene = () => (
	<div className="ms-scene ms-scene--done" aria-hidden="true">
		<div className="ms-badge">
			<svg viewBox="0 0 220 220">
				<circle className="ms-badge__ring" cx="110" cy="110" r="92" />
			</svg>
			<Icon name="check" size={ 84 } strokeWidth={ 2.8 } />
		</div>
	</div>
);

const COLORS = [
	'#ff5caa',
	'#d6247f',
	'#ffd166',
	'#7c5cff',
	'#38e1b4',
	'#ffffff',
];

/**
 * Confetti falling across the screen once, then gone.
 */
export const Confetti = () => {
	const canvas = useRef();
	const reduced = useReducedMotion();

	useEffect( () => {
		if ( reduced || ! canvas.current ) {
			return undefined;
		}

		const element = canvas.current;
		const context = element.getContext( '2d' );
		const ratio = window.devicePixelRatio || 1;
		const resize = () => {
			element.width = window.innerWidth * ratio;
			element.height = window.innerHeight * ratio;
		};
		resize();

		const pieces = Array.from( { length: 150 }, () => ( {
			x: Math.random() * element.width,
			y: -Math.random() * element.height * 0.6,
			w: ( 6 + Math.random() * 8 ) * ratio,
			h: ( 8 + Math.random() * 10 ) * ratio,
			vx: ( Math.random() - 0.5 ) * 3 * ratio,
			vy: ( 2 + Math.random() * 4 ) * ratio,
			angle: Math.random() * Math.PI,
			spin: ( Math.random() - 0.5 ) * 0.25,
			color: COLORS[ Math.floor( Math.random() * COLORS.length ) ],
		} ) );

		let frame;
		const start = performance.now();

		const draw = ( now ) => {
			context.clearRect( 0, 0, element.width, element.height );
			const fade = Math.max( 0, 1 - ( now - start - 3200 ) / 1400 );
			context.globalAlpha = fade;

			for ( const piece of pieces ) {
				piece.x += piece.vx;
				piece.y += piece.vy;
				piece.angle += piece.spin;
				context.save();
				context.translate( piece.x, piece.y );
				context.rotate( piece.angle );
				context.fillStyle = piece.color;
				context.fillRect(
					-piece.w / 2,
					-piece.h / 2,
					piece.w,
					piece.h
				);
				context.restore();
			}

			if ( fade > 0 ) {
				frame = window.requestAnimationFrame( draw );
			} else {
				context.clearRect( 0, 0, element.width, element.height );
			}
		};
		frame = window.requestAnimationFrame( draw );
		window.addEventListener( 'resize', resize );

		return () => {
			window.cancelAnimationFrame( frame );
			window.removeEventListener( 'resize', resize );
		};
	}, [ reduced ] );

	return <canvas ref={ canvas } className="ms-confetti" aria-hidden="true" />;
};
