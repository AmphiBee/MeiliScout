/**
 * The listings' blocks (design §10) and the suggest block: registered from
 * their block.json, in blocks/.
 */
import { registerBlockType } from '@wordpress/blocks';

import listing from '../../../blocks/listing/block.json';
import facet from '../../../blocks/facet/block.json';
import part from '../../../blocks/part/block.json';
import ListingEdit, { save as saveListing } from './listing';
import FacetEdit from './facet';
import PartEdit from './part';
import suggest from '../../../blocks/suggest/block.json';
import SuggestEdit from '../../suggest/editor/suggest';
import '../../suggest/editor/search';

registerBlockType( listing, {
	edit: ListingEdit,
	save: saveListing,
	icon: 'filter',
} );
registerBlockType( facet, { edit: FacetEdit, icon: 'yes-alt' } );
registerBlockType( part, { edit: PartEdit, icon: 'admin-generic' } );
registerBlockType( suggest, {
	edit: SuggestEdit,
	icon: 'search',
	save: () => null,
} );
