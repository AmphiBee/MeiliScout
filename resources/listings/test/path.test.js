/**
 * The cases tests/Unit/Listings/PathCasesTest.php runs too: facets in the
 * path read and written as the server does, and the links of their values.
 */
import { linkTarget, parseUrl, url } from '../codec';
import fixture from '../../../tests/fixtures/listings/path-cases.json';
import urlFixture from '../../../tests/fixtures/listings/url-cases.json';

const { paths, seo, base, cases, links } = fixture;
const template = {
	...urlFixture.template,
	seo,
	facets: urlFixture.template.facets.map( ( facet ) => ( {
		...facet,
		path: paths[ facet.key ] ?? null,
	} ) ),
};

describe( 'path cases', () => {
	const read = cases.filter( ( c ) => c.state !== null );
	const others = cases.filter( ( c ) => c.state === null );

	test.each( read.map( ( c ) => [ c.name, c ] ) )( '%s', ( name, c ) => {
		const state = parseUrl( template, c.path, c.query, base );

		expect( state ).toEqual( c.state );
		expect( url( template, state, base ) ).toBe( c.url );

		// The canonical form is a fixed point
		const canonical = new URL( c.url );
		expect(
			parseUrl(
				template,
				canonical.pathname,
				canonical.search.replace( /^\?/, '' ),
				base
			)
		).toEqual( c.state );
	} );

	test.each( others.map( ( c ) => [ c.name, c ] ) )(
		'%s: not the listing',
		( name, c ) => {
			expect( parseUrl( template, c.path, c.query, base ) ).toBeNull();
		}
	);
} );

describe( 'links', () => {
	test.each( links.map( ( c ) => [ c.name, c ] ) )( '%s', ( name, c ) => {
		const target = linkTarget(
			template,
			c.state,
			c.facet,
			c.value,
			c.count
		);
		expect( target === null ? null : url( template, target, base ) ).toBe(
			c.url
		);
	} );
} );
