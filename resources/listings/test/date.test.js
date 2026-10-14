/**
 * The cases tests/Unit/Listings/DateRangeCasesTest.php runs too: a range of
 * days, in the URL and the filter.
 */
import { parse, queryString } from '../codec';
import { clause } from '../plan';
import fixture from '../../../tests/fixtures/listings/date-cases.json';

const template = {
	facets: [ fixture.facet ],
	sortParam: 'orderby',
	searchParam: 'q',
	sorts: {},
	defaultSort: 'date',
};

describe( 'date range cases', () => {
	test.each( fixture.cases.map( ( c ) => [ c.name, c ] ) )(
		'%s',
		( name, c ) => {
			const state = parse( template, c.query );
			expect( state.ranges ).toEqual( c.ranges );
			expect( clause( fixture.facet, state ) ).toBe( c.clause );
			expect( queryString( template, state ) ).toBe( c.canonical );
		}
	);
} );
