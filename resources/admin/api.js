import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

const NAMESPACE = '/meiliscout/v1';

export const get = ( path, query = {} ) =>
	apiFetch( { path: addQueryArgs( NAMESPACE + path, query ) } );

export const post = ( path, data = {} ) =>
	apiFetch( { path: NAMESPACE + path, method: 'POST', data } );

/**
 * The message of an error apiFetch threw.
 *
 * @param {Object} error
 * @return {string} The message to show.
 */
export const errorMessage = ( error ) =>
	error?.message || error?.code || String( error );
