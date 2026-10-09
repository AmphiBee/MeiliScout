/**
 * The meiliscout/listing-part block in the editor: a preview of its part.
 */
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

const PREVIEWS = {
	search: () => (
		<div className="meiliscout-search">
			<span className="meiliscout-search__label">
				{ __( 'Search', 'meiliscout' ) }
			</span>
			<input
				className="meiliscout-search__input"
				type="search"
				disabled
			/>
		</div>
	),
	sort: () => (
		<label className="meiliscout-sort">
			<span className="meiliscout-sort__label">
				{ __( 'Sort by', 'meiliscout' ) }
			</span>
			<select className="meiliscout-sort__select" disabled>
				<option>{ __( 'Newest first', 'meiliscout' ) }</option>
			</select>
		</label>
	),
	total: () => (
		<p className="meiliscout-listing__total">
			{ __( '42 results', 'meiliscout' ) }
		</p>
	),
	active: () => (
		<ul className="meiliscout-active">
			<li className="meiliscout-active__item">
				<span className="meiliscout-active__remove">
					{ __( 'A filter', 'meiliscout' ) } ×
				</span>
			</li>
		</ul>
	),
	apply: () => (
		<span className="meiliscout-listing__apply">
			{ __( 'Apply', 'meiliscout' ) }
		</span>
	),
	reset: () => (
		<span className="meiliscout-listing__reset">
			{ __( 'Reset', 'meiliscout' ) }
		</span>
	),
};

export default function PartEdit( { attributes } ) {
	const Preview = PREVIEWS[ attributes.part ] || PREVIEWS.search;

	return (
		<div
			{ ...useBlockProps( {
				className:
					'meiliscout-part meiliscout-part--' + attributes.part,
			} ) }
		>
			<Preview />
		</div>
	);
}
