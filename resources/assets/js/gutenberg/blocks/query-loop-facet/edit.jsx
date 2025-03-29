import { __ } from '@wordpress/i18n';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, SelectControl, TextControl, ToggleControl } from '@wordpress/components';
import FacetPreview from './components/FacetPreview';

const Edit = ({ attributes, setAttributes }) => {
    const { facetType, facetAttribute, label, filterType, targetQueryId, showEmptyValues } = attributes;
    const blockProps = useBlockProps();

    return (
        <>
            <InspectorControls>
                <PanelBody title={__('Facet Settings', 'meiliscout')}>
                    <TextControl
                        label={__('Target Query ID', 'meiliscout')}
                        value={targetQueryId}
                        onChange={(value) => setAttributes({ targetQueryId: value })}
                        help={__('Enter the ID of the Query Loop block to filter', 'meiliscout')}
                    />
                    <SelectControl
                        label={__('Facet Type', 'meiliscout')}
                        value={facetType}
                        options={[
                            { label: __('Checkbox', 'meiliscout'), value: 'checkbox' },
                            { label: __('Radio', 'meiliscout'), value: 'radio' },
                            { label: __('Select', 'meiliscout'), value: 'select' },
                            { label: __('Button', 'meiliscout'), value: 'button' },
                            { label: __('Range', 'meiliscout'), value: 'range' },
                        ]}
                        onChange={(value) => setAttributes({ facetType: value })}
                    />
                    <TextControl
                        label={__('Facet Attribute', 'meiliscout')}
                        value={facetAttribute}
                        onChange={(value) => setAttributes({ facetAttribute: value })}
                        help={__('Enter the taxonomy or meta field to filter by', 'meiliscout')}
                    />
                    <SelectControl
                        label={__('Filter Type', 'meiliscout')}
                        value={filterType}
                        options={[
                            { label: __('Taxonomy', 'meiliscout'), value: 'taxonomy' },
                            { label: __('Meta', 'meiliscout'), value: 'meta' },
                        ]}
                        onChange={(value) => setAttributes({ filterType: value })}
                    />
                    <TextControl
                        label={__('Label', 'meiliscout')}
                        value={label}
                        onChange={(value) => setAttributes({ label: value })}
                        help={__('Enter a label for this facet', 'meiliscout')}
                    />
                    <ToggleControl
                        label={__('Show Empty Values', 'meiliscout')}
                        checked={showEmptyValues}
                        onChange={(value) => setAttributes({ showEmptyValues: value })}
                        help={__('Show facet values that have no results', 'meiliscout')}
                    />
                </PanelBody>
            </InspectorControls>

            <div {...blockProps}>
                {(!facetAttribute || !filterType) ? (
                    <div className="facet-warning">
                        {!facetAttribute && (
                            <p>{__('Please specify a facet attribute in the settings.', 'meiliscout')}</p>
                        )}
                        {!filterType && (
                            <p>{__('Please specify a filter type (taxonomy or meta) in the settings.', 'meiliscout')}</p>
                        )}
                    </div>
                ) : (
                    <FacetPreview
                        type={facetType}
                        attribute={facetAttribute}
                        label={label}
                    />
                )}
            </div>
        </>
    );
};

export default Edit;
