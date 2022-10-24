describe( 'Vector Search Client', () => {

	function getFakeMw( fakeConfig, fakeApiInstance ) {
		return {
			config: {
				get: ( key ) => { return fakeConfig[ key ]; },
				set: jest.fn()
			},
			Api: jest.fn().mockImplementation( () => {
				return fakeApiInstance;
			} )
		};
	}

	// TODO: figure out a nicer way to inject and assert results
	const mockApiResults = [ {
		id: 'Q2497232',
		title: 'Q2497232',
		pageid: 2410715,
		display: {
			label: {
				value: 'Brasilianische Akademie der Wissenschaften',
				language: 'de'
			},
			description: {
				value: 'academy of sciences in Brazil',
				language: 'en'
			}
		},
		repository: 'wikidata',
		url: '//www.wikidata.org/wiki/Q2497232',
		concepturi: 'http://www.wikidata.org/entity/Q2497232',
		label: 'Brasilianische Akademie der Wissenschaften',
		description: 'academy of sciences in Brazil',
		match: {
			type: 'alias',
			language: 'de',
			text: 'ABC'
		},
		aliases: [
			'ABC'
		]
	} ];

	it( 'test construction and fetchByTitle behavior', async () => {
		const fakeApiInstance = {
			get: jest.fn().mockResolvedValue( {
				search: mockApiResults
			} ),
			abort: jest.fn()
		};
		const userLanguage = 'de';
		global.mw = getFakeMw(
			{
				skin: 'vector-2022',
				wgUserLanguage: userLanguage
			},
			fakeApiInstance
		);
		require( '../../resources/wikibase.vector.searchClient.js' );
		expect( global.mw.config.set.mock.calls[ 0 ][ 0 ] ).toBe( 'wgVectorSearchClient' );
		const vectorSearchClient = global.mw.config.set.mock.calls[ 0 ][ 1 ];

		const exampleSearchString = 'abc';
		const vectorLimit = 10;

		const apiController = vectorSearchClient.fetchByTitle(
			exampleSearchString,
			'/w/rest.php', // should be ignored by us
			vectorLimit,
			true
		);
		expect( fakeApiInstance.get ).toHaveBeenCalledWith( {
			action: 'wbsearchentities',
			search: exampleSearchString,
			limit: vectorLimit,
			language: userLanguage,
			uselang: userLanguage,
			type: 'item',
			format: 'json',
			errorformat: 'plaintext'
		} );

		const actualTransformedResult = await apiController.fetch;
		expect( actualTransformedResult ).toStrictEqual( {
			query: exampleSearchString,
			results: [
				{
					label: 'Brasilianische Akademie der Wissenschaften',
					description: 'academy of sciences in Brazil',
					language: {
						label: 'de',
						description: 'en',
						match: 'de'
					},
					match: 'ABC',
					url: '//www.wikidata.org/wiki/Q2497232',
					value: 'Q2497232'
				}
			]
		} );
	} );
} );
