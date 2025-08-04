import { useBlockProps, InnerBlocks, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export default function Edit({ attributes, setAttributes }) {
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

            <div {...useBlockProps({ className: 'wp-block-query-meilisearch' })}>
                <InnerBlocks
                    template={[
                        ['core/query', {}, [
                            ['core/post-template', { templateLock: false }, [
                                ['core/post-title'],
                                ['core/post-excerpt'],
                            ]],
                            ['core/query-pagination', { templateLock: false }],
                            ['core/query-no-results', { templateLock: false }],
                        ]],
                    ]}
                />
            </div>
        </>
    );
}
