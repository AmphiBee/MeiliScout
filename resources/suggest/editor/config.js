/**
 * What the editor offers (GET meiliscout/v1/suggest/editor): the indexed post
 * types and taxonomies, and the suggest fields declared in PHP. Fetched once.
 */
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';

let pending = null;

export const useSuggestConfig = () => {
	const [ config, setConfig ] = useState( null );

	useEffect( () => {
		pending ??= apiFetch( { path: '/meiliscout/v1/suggest/editor' } );
		let live = true;
		pending.then(
			( value ) => live && setConfig( value ),
			() => live && setConfig( { error: true } )
		);
		return () => {
			live = false;
		};
	}, [] );

	return config;
};
