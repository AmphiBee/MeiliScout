/**
 * The URL of a screen, and of a section in it: #/content#fields.
 *
 * @param {string} path
 * @param {string} anchor
 * @return {string} The hash URL.
 */
export const href = ( path, anchor = '' ) =>
	'#/' + path + ( anchor ? '#' + anchor : '' );
