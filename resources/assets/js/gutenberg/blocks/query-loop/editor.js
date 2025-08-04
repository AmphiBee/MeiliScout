import { useBlockProps } from '@wordpress/block-editor';
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

wp.blocks.registerBlockType('meiliscout/query-loop-search', {
    edit: ({ attributes, setAttributes }) => {
        const { enableUrlParams, queryId } = attributes;

        return (
            <>
                <InspectorControls>
                    <PanelBody title={__('MeiliSearch Settings', 'meiliscout')} initialOpen={true}>
                        <TextControl
                            label={__('Query ID', 'meiliscout')}
                            value={queryId}
                            onChange={(value) => setAttributes({ queryId: value })}
                        />
                        <ToggleControl
                            label={__('Enable URL Parameters', 'meiliscout')}
                            checked={enableUrlParams}
                            onChange={(value) => setAttributes({ enableUrlParams: !!value })}
                        />
                    </PanelBody>
                </InspectorControls>
                <div {...useBlockProps()}>
                    <p>{__('This block will render a MeiliSearch-powered query loop on the front-end.', 'meiliscout')}</p>
                </div>
            </>
        );
    }
});
