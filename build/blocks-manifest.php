<?php
// This file is generated. Do not modify it manually.
return array(
	'query-loop' => array(
		'apiVersion' => 2,
		'name' => 'meiliscout/query-loop-search',
		'title' => 'MeiliSearch Query Loop',
		'category' => 'query',
		'icon' => 'search',
		'description' => 'Affiche une boucle de requête avec MeiliSearch et facettes.',
		'keywords' => array(
			'meilisearch',
			'search',
			'query'
		),
		'attributes' => array(
			'queryId' => array(
				'type' => 'string',
				'default' => ''
			),
			'enableUrlParams' => array(
				'type' => 'boolean',
				'default' => false
			),
			'query' => array(
				'type' => 'object',
				'default' => array(
					'perPage' => 10,
					'pages' => 0,
					'offset' => 0,
					'postType' => 'post',
					'order' => 'desc',
					'orderBy' => 'date',
					'use_meilisearch' => true,
					'facets' => array(
						
					)
				)
			)
		),
		'supports' => array(
			'align' => true,
			'html' => false,
			'customClassName' => false
		),
		'editorScript' => 'file:./index.js',
		'render' => 'file:./render.php'
	),
	'query-loop-facet' => array(
		'$schema' => 'https://schemas.wp.org/trunk/block.json',
		'apiVersion' => 3,
		'name' => 'meiliscout/query-loop-facet',
		'version' => '0.1.0',
		'title' => 'Query Loop Facet',
		'category' => 'text',
		'icon' => 'smiley',
		'description' => 'Add faceted search filters to your Query Loop block.',
		'example' => array(
			
		),
		'supports' => array(
			'html' => false,
			'inserter' => true
		),
		'attributes' => array(
			'targetQueryId' => array(
				'type' => 'string',
				'default' => ''
			),
			'facetType' => array(
				'type' => 'string',
				'default' => 'checkbox'
			),
			'facetAttribute' => array(
				'type' => 'string',
				'default' => ''
			),
			'filterType' => array(
				'type' => 'string',
				'default' => 'taxonomy',
				'enum' => array(
					'taxonomy',
					'meta'
				)
			),
			'label' => array(
				'type' => 'string',
				'default' => ''
			),
			'showEmptyValues' => array(
				'type' => 'boolean',
				'default' => false
			)
		),
		'textdomain' => 'meiliscout',
		'editorScript' => 'file:./index.jsx',
		'render' => 'file:./render.php'
	)
);
