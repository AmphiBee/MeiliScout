import {
	MARK_CLOSE,
	MARK_OPEN,
	Memory,
	allUrl,
	announce,
	buildSearches,
	normalize,
	readAnswers,
	step,
	suggestionFromDocument,
	titleParts,
} from '../client';

const plan = {
	version: 1,
	sources: [
		{
			key: 'posts',
			type: 'posts',
			search: {
				indexUid: 'p',
				filter: 'post_type IN ["post"]',
				limit: 5,
			},
		},
		{
			key: 'topics',
			type: 'terms',
			search: {
				indexUid: 't',
				filter: 'taxonomy IN ["category"]',
				limit: 3,
			},
		},
	],
};

const labels = { post: 'Article', category: 'Category' };

test( 'a query is trimmed and its spaces collapsed', () => {
	expect( normalize( '  hello \n  world ' ) ).toBe( 'hello world' );
	expect( normalize( null ) ).toBe( '' );
} );

test( "one search per source, with the plan's own fields and a private highlight", () => {
	const searches = buildSearches( plan, 'web' );

	expect( searches ).toHaveLength( 2 );
	expect( searches[ 0 ] ).toEqual( {
		indexUid: 'p',
		filter: 'post_type IN ["post"]',
		limit: 5,
		q: 'web',
		highlightPreTag: MARK_OPEN,
		highlightPostTag: MARK_CLOSE,
	} );
	expect( searches[ 1 ].indexUid ).toBe( 't' );
} );

describe( 'titleParts', () => {
	test( 'cuts a title on its matches', () => {
		expect(
			titleParts( `Refonte ${ MARK_OPEN }web${ MARK_CLOSE } du site` )
		).toEqual( [
			{ key: 0, text: 'Refonte ', match: false },
			{ key: 1, text: 'web', match: true },
			{ key: 2, text: ' du site', match: false },
		] );
	} );

	test( 'a title without a match is one part', () => {
		expect( titleParts( 'Plain' ) ).toEqual( [
			{ key: 0, text: 'Plain', match: false },
		] );
	} );

	test( 'entities are decoded, and markup stays text', () => {
		const parts = titleParts(
			`L&#8217;${ MARK_OPEN }<b>x</b>${ MARK_CLOSE } &amp; co`
		);

		expect( parts.map( ( p ) => p.text ) ).toEqual( [
			'L’',
			'<b>x</b>',
			' & co',
		] );
		expect( parts[ 1 ].match ).toBe( true );
	} );

	test( 'nothing in, nothing out', () => {
		expect( titleParts( '' ) ).toEqual( [] );
		expect( titleParts( undefined ) ).toEqual( [] );
	} );
} );

describe( 'suggestionFromDocument', () => {
	test( 'a post', () => {
		const hit = suggestionFromDocument(
			plan.sources[ 0 ],
			{
				ID: 12,
				post_title: 'A &amp; B',
				_formatted: {
					post_title: `${ MARK_OPEN }A${ MARK_CLOSE } &amp; B`,
				},
				url: 'https://x.test/a/',
				post_type: 'post',
				thumbnail: { url: 'https://x.test/a.jpg', alt: 'An A' },
				metas: { price: 12, tags: [ 'a', true ] },
			},
			labels,
			'f-0'
		);

		expect( hit ).toMatchObject( {
			optionId: 'f-0-12',
			id: 12,
			url: 'https://x.test/a/',
			title: 'A & B',
			meta: 'Article',
			image: 'https://x.test/a.jpg',
			imageAlt: 'An A',
			metas: { price: '12', tags: [ 'a', '1' ] },
		} );
		expect( hit.titleParts[ 0 ] ).toEqual( {
			key: 0,
			text: 'A',
			match: true,
		} );
	} );

	test( 'a term, and a post without an image', () => {
		const term = suggestionFromDocument(
			plan.sources[ 1 ],
			{
				term_id: 3,
				name: 'News',
				url: '/n/',
				taxonomy: 'category',
				count: 7,
			},
			labels,
			'f-1'
		);

		expect( term ).toMatchObject( {
			optionId: 'f-1-3',
			title: 'News',
			meta: 'Category',
			count: 7,
			image: '',
		} );
		expect( term.titleParts ).toEqual( [
			{ key: 0, text: 'News', match: false },
		] );
	} );

	test( 'an unknown type is its own label', () => {
		expect(
			suggestionFromDocument(
				plan.sources[ 0 ],
				{ ID: 1, post_title: 'x', post_type: 'event' },
				labels,
				'f'
			).meta
		).toBe( 'event' );
	} );
} );

test( 'answers become groups, in the order the keyboard walks them', () => {
	const { groups, options, total } = readAnswers(
		plan,
		[
			{
				hits: [
					{ ID: 1, post_title: 'One', post_type: 'post', url: '/1' },
					{ ID: 2, post_title: 'Two', post_type: 'post', url: '/2' },
				],
			},
			{ hits: [] },
		],
		labels,
		'f'
	);

	expect( groups.map( ( g ) => [ g.key, g.hasHits ] ) ).toEqual( [
		[ 'posts', true ],
		[ 'topics', false ],
	] );
	expect( options ).toEqual( [
		{ id: 'f-0-1', url: '/1' },
		{ id: 'f-0-2', url: '/2' },
	] );
	expect( total ).toBe( 2 );
	// A source that did not answer shows nothing
	expect( readAnswers( plan, [], labels, 'f' ).total ).toBe( 0 );
} );

test( 'all results keeps the results page and adds the query', () => {
	expect( allUrl( 'https://x.test/', 's', 'a b' ) ).toBe(
		'https://x.test/?s=a+b'
	);
	expect( allUrl( '/search/?post_type=post', 'q', 'web' ) ).toBe(
		'/search/?post_type=post&q=web'
	);
} );

test( 'the announcement takes the plural form of the count', () => {
	const forms = {
		zero: 'No result %d',
		one: '%d result',
		many: '%d results',
	};

	expect( [ 0, 1, 4 ].map( ( n ) => announce( forms, n ) ) ).toEqual( [
		'No result 0',
		'1 result',
		'4 results',
	] );
} );

test( 'the arrows walk the options and the way back to the field', () => {
	expect( step( -1, 1, 3 ) ).toBe( 0 );
	expect( step( 2, 1, 3 ) ).toBe( -1 );
	expect( step( -1, -1, 3 ) ).toBe( 2 );
	expect( step( 0, -1, 3 ) ).toBe( -1 );
	expect( step( -1, 1, 0 ) ).toBe( -1 );
} );

test( 'the memory forgets the least recently read answer', () => {
	const memory = new Memory( 2 );
	memory.set( 'a', 1 );
	memory.set( 'b', 2 );
	memory.get( 'a' );
	memory.set( 'c', 3 );

	expect( memory.get( 'b' ) ).toBeUndefined();
	expect( memory.get( 'a' ) ).toBe( 1 );
	expect( memory.get( 'c' ) ).toBe( 3 );
} );
