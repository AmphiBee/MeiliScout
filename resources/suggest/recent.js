/**
 * The visitor's recent searches, kept in their own browser (localStorage),
 * one list per field. Nothing leaves the browser: no server keeps them.
 * Storage may be absent or full (a private window, blocked site data): every
 * call then does nothing and the field works without recents.
 */

/** How many searches a field remembers. */
export const MAX_RECENT = 5;

const PREFIX = 'meiliscout-suggest-recent:';

/**
 * @param {string} field The field's id.
 * @return {string} The storage key.
 */
export const storageKey = ( field ) => PREFIX + field;

/**
 * The recent searches, the last one first.
 *
 * @param {Storage|null} storage
 * @param {string}       field
 * @return {string[]} The searches.
 */
export const load = ( storage, field ) => {
	try {
		const list = JSON.parse(
			storage?.getItem( storageKey( field ) ) ?? '[]'
		);
		return Array.isArray( list )
			? list
					.filter( ( q ) => typeof q === 'string' && q !== '' )
					.slice( 0, MAX_RECENT )
			: [];
	} catch ( e ) {
		return [];
	}
};

/**
 * Remembers a search: first in the list, once, the oldest dropped.
 *
 * @param {Storage|null} storage
 * @param {string}       field
 * @param {string}       query   A normalized query.
 * @return {string[]} The searches.
 */
export const add = ( storage, field, query ) => {
	const folded = query.toLocaleLowerCase();
	const list = [
		query,
		...load( storage, field ).filter(
			( q ) => q.toLocaleLowerCase() !== folded
		),
	].slice( 0, MAX_RECENT );

	try {
		storage?.setItem( storageKey( field ), JSON.stringify( list ) );
	} catch ( e ) {
		// Full or blocked: the list lives for this page only
	}

	return list;
};

/**
 * Forgets every recent search of a field.
 *
 * @param {Storage|null} storage
 * @param {string}       field
 */
export const clear = ( storage, field ) => {
	try {
		storage?.removeItem( storageKey( field ) );
	} catch ( e ) {
		// Nothing to forget
	}
};

/**
 * The browser's storage, or null where it cannot be reached.
 *
 * @return {Storage|null} localStorage.
 */
export const browserStorage = () => {
	try {
		return window.localStorage;
	} catch ( e ) {
		return null;
	}
};
