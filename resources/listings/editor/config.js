/**
 * What the editor offers (GET meiliscout/v1/listings/editor): the indexed
 * post types, the taxonomies, the indexed meta keys. Fetched once.
 */
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';

let pending = null;

export const useEditorConfig = () => {
	const [ config, setConfig ] = useState( null );

	useEffect( () => {
		pending ??= apiFetch( { path: '/meiliscout/v1/listings/editor' } );
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
