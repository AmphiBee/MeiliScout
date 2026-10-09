/**
 * The cases tests/Unit/Listings/UrlCasesTest.php runs too: the client writes
 * the URLs UrlCodec writes.
 */
import { parse, queryString, url, sanitizeTitle, round } from '../codec';
import fixture from '../../../tests/fixtures/listings/url-cases.json';

const { template, base, cases } = fixture;

describe( 'url cases', () => {
	test.each( cases.map( ( c ) => [ c.name, c ] ) )( '%s', ( name, c ) => {
		const page = c.page || 1;
		const state = parse( template, c.query, page );

		expect( state ).toEqual( c.state );
		expect( queryString( template, state ) ).toBe( c.canonical );
		expect( url( template, state, base ) ).toBe( c.url );
		// The canonical form is a fixed point
		expect( parse( template, c.canonical, page ) ).toEqual( c.state );
	} );
} );

describe( 'sanitizeTitle', () => {
	test.each( [
		[ 'Été', 'ete' ],
		[ 'Cœur Æther', 'coeur-aether' ],
		[ 'straße', 'strasse' ],
		[ 'при', '%d0%bf%d1%80%d0%b8' ],
		[ 'й', '%d0%b9' ],
		[ '%D0%BF', '%d0%bf' ],
		[ '100% <b>pur</b>', '100-pur' ],
		[ '  a.b  c—d ', 'a-b-c-d' ],
	] )( '%s', ( title, slug ) => {
		expect( sanitizeTitle( title ) ).toBe( slug );
	} );
} );

describe( 'round', () => {
	test.each( [
		[ 10.555, 2, 10.56 ],
		[ -10.555, 2, -10.56 ],
		[ 1.005, 2, 1.01 ],
		[ 0.285, 2, 0.29 ],
		[ 2.5, 0, 3 ],
		[ -2.5, 0, -3 ],
		[ -0.001, 2, 0 ],
		[ 1e-7, 2, 0 ],
	] )( '%f to %i decimals', ( value, decimals, rounded ) => {
		expect( round( value, decimals ) ).toBe( rounded );
	} );
} );
