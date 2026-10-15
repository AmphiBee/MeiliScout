import {
	MARK_CLOSE,
	MARK_OPEN,
	Memory,
	allUrl,
	announce,
	buildSearches,
	html,
	optionFrom,
	limitAnswer,
	recentSuggestions,
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

test( 'a shorter answer keeps the first suggestions of each group and the keyboard order', () => {
	const hit = ( n ) => ( { optionId: `f-0-${ n }`, url: `/${ n }` } );
	const answer = {
		groups: [
			{ key: 'a', hits: [ hit( 1 ), hit( 2 ), hit( 3 ) ], hasHits: true },
			{ key: 'b', hits: [], hasHits: false },
		],
		options: [],
		total: 3,
	};

	const short = limitAnswer( answer, 2 );

	expect( short.groups[ 0 ].hits ).toHaveLength( 2 );
	expect( short.options.map( ( o ) => o.id ) ).toEqual( [
		'f-0-1',
		'f-0-2',
	] );
	expect( short.total ).toBe( 2 );
	// The remembered answer is left whole
	expect( answer.groups[ 0 ].hits ).toHaveLength( 3 );
} );

test( 'recent searches are suggestions leading to the results page', () => {
	expect(
		recentSuggestions( [ 'web', 'seo' ], 'f', ( q ) => `/?s=${ q }` )
	).toEqual( [
		{ optionId: 'f-r-0', title: 'web', query: 'web', url: '/?s=web' },
		{ optionId: 'f-r-1', title: 'seo', query: 'seo', url: '/?s=seo' },
	] );
} );

describe( 'JavaScript templates', () => {
	// Not named html: prettier would reformat the markup inside
	const tag = html;
	const hit = {
		optionId: 'f-0-1',
		url: '/a/',
		title: 'A <b>bold</b> & "quoted" title',
	};

	test( 'html escapes what it interpolates, not the html it is given', () => {
		const inner = tag`<i>${ 'x < y' }</i>`;
		const items = [ 'a', '<b>' ].map( ( x ) => tag`<li>${ x }</li>` );

		expect( String( tag`<a href="${ hit.url }">${ hit.title }</a>` ) ).toBe(
			'<a href="/a/">A &lt;b&gt;bold&lt;/b&gt; &amp; &quot;quoted&quot; title</a>'
		);
		expect( String( tag`<p>${ inner }</p>` ) ).toBe(
			'<p><i>x &lt; y</i></p>'
		);
		expect( String( tag`<ul>${ items }</ul>` ) ).toBe(
			'<ul><li>a</li><li>&lt;b&gt;</li></ul>'
		);
		// Plain text in a list is escaped too
		expect( String( tag`<p>${ [ '<i>' ] }</p>` ) ).toBe(
			'<p>&lt;i&gt;</p>'
		);
	} );

	test( 'the option has what the list box needs, and the active one is marked', () => {
		const make = ( active ) =>
			optionFrom(
				( h, { html: t } ) =>
					t`<div class="mine"><span>${ h.title }</span></div>`,
				hit,
				active,
				document
			);

		const option = make( 'f-0-1' );
		expect( option.id ).toBe( 'f-0-1' );
		expect( option.getAttribute( 'role' ) ).toBe( 'option' );
		expect( option.getAttribute( 'aria-selected' ) ).toBe( 'true' );
		expect( option.classList.contains( 'is-active' ) ).toBe( true );
		expect( option.classList.contains( 'mine' ) ).toBe( true );
		// The title stayed text
		expect( option.querySelector( 'b' ) ).toBeNull();
		expect( make( '' ).getAttribute( 'aria-selected' ) ).toBe( 'false' );
	} );

	test( "a link without an address gets the suggestion's, and plain text becomes a link", () => {
		expect(
			optionFrom(
				( h, { html: t } ) => t`<a>${ h.title }</a>`,
				hit,
				'',
				document
			).getAttribute( 'href' )
		).toBe( '/a/' );

		const text = optionFrom( () => 'Just text', hit, '', document );
		expect( text.tagName ).toBe( 'A' );
		expect( text.textContent ).toBe( 'Just text' );
		expect( text.getAttribute( 'href' ) ).toBe( '/a/' );
	} );

	test( 'a missing or failing template shows the title', () => {
		expect( optionFrom( undefined, hit, '', document ).textContent ).toBe(
			hit.title
		);
		expect(
			optionFrom(
				() => {
					throw new Error( 'broken' );
				},
				hit,
				'',
				document
			).textContent
		).toBe( hit.title );
	} );

	test( 'an element is used as it is', () => {
		const li = document.createElement( 'li' );
		expect( optionFrom( () => li, hit, '', document ) ).toBe( li );
	} );
} );
