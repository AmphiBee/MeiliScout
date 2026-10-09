/**
 * The cases tests/Unit/Listings/PlanCasesTest.php runs too: the client counts
 * the facets with the searches the server sends.
 */
import { counts, read, results } from '../plan';
import fixture from '../../../tests/fixtures/listings/plan-cases.json';

const { template, cases } = fixture;

describe( 'plan cases', () => {
	test.each( cases.map( ( c ) => [ c.name, c ] ) )( '%s', ( name, c ) => {
		expect( counts( template, c.state ) ).toEqual( c.searches );
		expect( results( template, c.state ) ).toEqual( c.results );
	} );
} );

describe( 'read', () => {
	test( 'each field is read from the search that counts it, the total from the first', () => {
		const searches = cases.find(
			( c ) => c.searches.length === 2
		).searches;
		const answers = [
			{
				estimatedTotalHits: 12,
				facetDistribution: { [ searches[ 0 ].facets[ 0 ] ]: { 3: 4 } },
			},
			{
				facetDistribution: { [ searches[ 1 ].facets[ 0 ] ]: { 7: 2 } },
				facetStats: {
					[ searches[ 1 ].facets[ 0 ] ]: { min: 1, max: 9 },
				},
			},
		];
		const counted = read( searches, answers );

		expect( counted.total ).toBe( 12 );
		expect( counted.distributions[ searches[ 0 ].facets[ 0 ] ] ).toEqual( {
			3: 4,
		} );
		expect( counted.distributions[ searches[ 1 ].facets[ 0 ] ] ).toEqual( {
			7: 2,
		} );
		expect( counted.stats[ searches[ 1 ].facets[ 0 ] ] ).toEqual( {
			min: 1,
			max: 9,
		} );
		expect( counted.distributions[ searches[ 0 ].facets[ 1 ] ] ).toEqual(
			{}
		);
	} );
} );
