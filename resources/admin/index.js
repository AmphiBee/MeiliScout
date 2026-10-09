import { createRoot, useEffect, useState } from '@wordpress/element';
import domReady from '@wordpress/dom-ready';
import { __, sprintf } from '@wordpress/i18n';

import './admin.css';
import { Icon, Pill, useResource } from './components';
import { href } from './routes';
import Overview from './screens/Overview';
import Content from './screens/Content';
import Indexation from './screens/Indexation';
import SearchPreview from './screens/SearchPreview';
import Settings from './screens/Settings';
import Listings from './screens/Listings';
import SeoRules from './screens/SeoRules';

const boot = window.meiliscoutAdmin ?? {};

const SCREENS = [
	{ path: '', label: __( 'Overview', 'meiliscout' ), Screen: Overview },
	{ path: 'content', label: __( 'Content', 'meiliscout' ), Screen: Content },
	{
		path: 'indexation',
		label: __( 'Indexation', 'meiliscout' ),
		Screen: Indexation,
	},
	{
		path: 'search',
		label: __( 'Search preview', 'meiliscout' ),
		Screen: SearchPreview,
	},
	// While the listings module runs
	boot.listings && {
		path: 'listings',
		label: __( 'Listings', 'meiliscout' ),
		Screen: Listings,
	},
	boot.listings && {
		path: 'seo-rules',
		label: __( 'SEO rules', 'meiliscout' ),
		Screen: SeoRules,
	},
	{
		path: 'settings',
		label: __( 'Settings', 'meiliscout' ),
		Screen: Settings,
	},
].filter( Boolean );

/**
 * The screen in the URL's hash: #/content, #/settings#connection…
 *
 * @return {Object} { path, anchor }
 */
const readHash = () => {
	const [ path = '', anchor = '' ] = window.location.hash
		.replace( /^#\/?/, '' )
		.split( '#' );

	return { path, anchor };
};

const useRoute = () => {
	const [ route, setRoute ] = useState( readHash );

	useEffect( () => {
		const onChange = () => setRoute( readHash() );
		window.addEventListener( 'hashchange', onChange );

		return () => window.removeEventListener( 'hashchange', onChange );
	}, [] );

	return route;
};

const ConnectionPill = ( { connection } ) => {
	if ( ! connection ) {
		return null;
	}
	if ( ! connection.configured ) {
		return (
			<Pill tone="warning">{ __( 'Not configured', 'meiliscout' ) }</Pill>
		);
	}

	return connection.reachable ? (
		<Pill tone="success">{ __( 'Connected', 'meiliscout' ) }</Pill>
	) : (
		<Pill tone="error">{ __( 'Unreachable', 'meiliscout' ) }</Pill>
	);
};

const Header = ( { connection, current } ) => (
	<header className="ms-header">
		<div className="ms-wrap ms-header__inner">
			<div className="ms-header__top">
				<div className="ms-brand">
					<span className="ms-brand__logo">
						<Icon
							name="search"
							size={ 18 }
							strokeWidth={ 2.4 }
							stroke="#FF5CAA"
						/>
					</span>
					<h1 className="ms-brand__name">MeiliScout</h1>
					{ connection?.host && (
						<>
							<span className="ms-brand__sep" aria-hidden="true">
								/
							</span>
							<span className="ms-brand__host">
								{ connection.host.replace(
									/^https?:\/\//,
									''
								) }
							</span>
						</>
					) }
					<ConnectionPill connection={ connection } />
				</div>
				<div className="ms-header__meta">
					{ connection?.version && (
						<span>
							{ sprintf(
								/* translators: %s: the Meilisearch version */
								__( 'Meilisearch v%s', 'meiliscout' ),
								connection.version
							) }
						</span>
					) }
					<a href={ boot.docsUrl } target="_blank" rel="noreferrer">
						{ __( 'Documentation', 'meiliscout' ) }
					</a>
				</div>
			</div>
			<nav
				className="ms-tabs"
				aria-label={ __( 'MeiliScout sections', 'meiliscout' ) }
			>
				{ SCREENS.map( ( screen ) => (
					<a
						key={ screen.path }
						className="ms-tab"
						href={ href( screen.path ) }
						aria-current={
							screen.path === current ? 'page' : undefined
						}
					>
						{ screen.label }
					</a>
				) ) }
			</nav>
		</div>
	</header>
);

const App = () => {
	const route = useRoute();
	const overview = useResource( '/overview' );
	const connection = overview.data?.connection;

	// Nothing works before the connection is set: start there
	const path =
		connection && ! connection.configured && route.path === ''
			? 'settings'
			: route.path;
	const screen =
		SCREENS.find( ( candidate ) => candidate.path === path ) ??
		SCREENS[ 0 ];

	useEffect( () => {
		document.title = screen.label + ' ‹ MeiliScout';
		window.scrollTo( 0, 0 );
	}, [ screen ] );

	// Scrolls to #/content#fields once the section is there: the screen may still be loading
	useEffect( () => {
		if ( ! route.anchor ) {
			return;
		}
		let frame;
		let tries = 0;
		const scroll = () => {
			const section = document.getElementById( route.anchor );
			if ( section ) {
				section.scrollIntoView( { block: 'start' } );
			} else if ( tries++ < 120 ) {
				frame = window.requestAnimationFrame( scroll );
			}
		};
		frame = window.requestAnimationFrame( scroll );

		return () => window.cancelAnimationFrame( frame );
	}, [ route ] );

	const { Screen } = screen;

	return (
		<>
			<Header connection={ connection } current={ screen.path } />
			<Screen
				overview={ overview }
				refreshOverview={ overview.reload }
				anchor={ route.anchor }
			/>
		</>
	);
};

domReady( () => {
	const root = document.getElementById( 'meiliscout-admin' );

	if ( root ) {
		createRoot( root ).render( <App /> );
	}
} );
