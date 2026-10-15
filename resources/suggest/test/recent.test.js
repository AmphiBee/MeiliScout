import { MAX_RECENT, add, clear, load } from '../recent';

const memoryStorage = () => {
	const data = new Map();
	return {
		getItem: ( k ) => ( data.has( k ) ? data.get( k ) : null ),
		setItem: ( k, v ) => data.set( k, v ),
		removeItem: ( k ) => data.delete( k ),
	};
};

test( 'the last search comes first, and is kept once whatever its case', () => {
	const storage = memoryStorage();
	add( storage, 'site', 'web' );
	add( storage, 'site', 'seo' );

	expect( add( storage, 'site', 'Web' ) ).toEqual( [ 'Web', 'seo' ] );
	expect( load( storage, 'site' ) ).toEqual( [ 'Web', 'seo' ] );
} );

test( 'the oldest search is dropped past the limit', () => {
	const storage = memoryStorage();
	for ( let i = 0; i < MAX_RECENT + 2; i++ ) {
		add( storage, 'site', `q${ i }` );
	}

	expect( load( storage, 'site' ) ).toHaveLength( MAX_RECENT );
	expect( load( storage, 'site' )[ 0 ] ).toBe( `q${ MAX_RECENT + 1 }` );
} );

test( 'each field has its own list, which can be forgotten', () => {
	const storage = memoryStorage();
	add( storage, 'a', 'one' );
	add( storage, 'b', 'two' );
	clear( storage, 'a' );

	expect( load( storage, 'a' ) ).toEqual( [] );
	expect( load( storage, 'b' ) ).toEqual( [ 'two' ] );
} );

test( 'broken or missing storage never throws', () => {
	const broken = {
		getItem: () => {
			throw new Error( 'blocked' );
		},
		setItem: () => {
			throw new Error( 'full' );
		},
		removeItem: () => {
			throw new Error( 'blocked' );
		},
	};

	expect( load( null, 'site' ) ).toEqual( [] );
	expect( load( broken, 'site' ) ).toEqual( [] );
	expect( add( broken, 'site', 'web' ) ).toEqual( [ 'web' ] );
	expect( () => clear( broken, 'site' ) ).not.toThrow();
	expect( load( { getItem: () => '{"not":"a list"}' }, 'site' ) ).toEqual(
		[]
	);
	expect( load( { getItem: () => 'not json' }, 'site' ) ).toEqual( [] );
} );
