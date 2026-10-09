/**
 * The cases tests/Unit/Listings/CardCasesTest.php runs too: the client makes
 * the cards the server makes.
 */
import { formatDate, hitFromDocument, pageItems, pageLinks } from '../hits';
import fixture from '../../../tests/fixtures/listings/card-cases.json';

describe( 'dates', () => {
	test.each( fixture.dates.map( ( c ) => [ c.format, c ] ) )(
		'%s',
		( format, c ) => {
			expect( formatDate( { ...fixture.names, format }, c.date ) ).toBe(
				c.label
			);
		}
	);
} );

describe( 'cards', () => {
	test.each( fixture.cards.map( ( c ) => [ c.document.ID, c ] ) )(
		'document %i',
		( id, c ) => {
			expect(
				hitFromDocument(
					c.document,
					[ 'color', 'price' ],
					fixture.names
				)
			).toEqual( c.hit );
		}
	);
} );

describe( 'pagination', () => {
	test.each(
		fixture.pagination.map( ( c ) => [ `${ c.page } of ${ c.pages }`, c ] )
	)( '%s', ( name, c ) => {
		expect( pageItems( c.page, c.pages ) ).toEqual( c.items );
	} );

	test( 'links: the current page and the dots have none', () => {
		const links = pageLinks(
			pageItems( 5, 10 ),
			( page ) => `/page/${ page }/`,
			{ previous: 'Previous', next: 'Next' }
		);

		expect( links.map( ( link ) => link.label ) ).toEqual( [
			'Previous',
			'1',
			'…',
			'3',
			'4',
			'5',
			'6',
			'7',
			'…',
			'10',
			'Next',
		] );
		expect( links[ 5 ] ).toMatchObject( { url: null, current: 'page' } );
		expect( links[ 2 ] ).toMatchObject( { url: null, hidden: 'true' } );
		expect( links[ 0 ].url ).toBe( '/page/4/' );
		expect( new Set( links.map( ( link ) => link.key ) ).size ).toBe(
			links.length
		);
	} );
} );
