import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import Edit from './edit';
import Save from './save';

// Import styles
import '../../../../css/front-facets.css';
import '../../../../css/editor-facets.css';

const BLOCK_NAME = 'meiliscout/query-loop-facet';

export const registerQueryLoopFacetBlock = () => {
    registerBlockType(BLOCK_NAME, {
        category: 'widgets',
        icon: 'filter',
        edit: Edit,
        save: Save,
    });
};
