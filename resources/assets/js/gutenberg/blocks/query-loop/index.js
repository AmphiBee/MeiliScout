import { registerBlockType } from '@wordpress/blocks';
import Edit from './edit';
import save from './save';

registerBlockType('meiliscout/query-loop-search', {
    edit: Edit,
    save,
});
