import { __ } from '@wordpress/i18n';

const FacetPreview = ({ type, attribute, label }) => {
    const renderCheckboxPreview = () => (
        <div className="facet-checkbox-items">
            <div className="facet-checkbox-wrapper">
                <div className="facet-checkbox-container">
                    <div className="facet-checkbox-group">
                        <input type="checkbox" className="facet-checkbox" checked readOnly />
                        <svg className="facet-checkbox-icon" viewBox="0 0 14 14" fill="none">
                            <path className="check-path" d="M3 8L6 11L11 3.5"></path>
                            <path className="indeterminate-path" d="M3 7H11"></path>
                        </svg>
                    </div>
                </div>
                <label className="facet-checkbox-label">Example {attribute}</label>
            </div>
        </div>
    );

    const renderRadioPreview = () => (
        <div className="facet-radio-items">
            <div className="facet-radio-wrapper">
                <div className="facet-radio-input-container">
                    <input type="radio" className="facet-radio-input" checked readOnly />
                </div>
                <div className="facet-radio-content">
                    <label className="facet-radio-label">Example {attribute}</label>
                    <span className="facet-radio-count">(5)</span>
                </div>
            </div>
        </div>
    );

    const renderSelectPreview = () => (
        <div className="facet-select-wrapper">
            <select className="facet-select-input">
                <option>Example {attribute}</option>
            </select>
            <svg className="facet-select-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                <path fillRule="evenodd" d="M4.22 6.22a.75.75 0 0 1 1.06 0L8 8.94l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.28a.75.75 0 0 1 0-1.06Z" clipRule="evenodd" />
            </svg>
        </div>
    );

    const renderRangePreview = () => (
        <div className="facet-range-wrapper">
            <div className="facet-range-inputs">
                <div className="facet-range-input-group">
                    <label className="facet-range-label">Min</label>
                    <input type="number" className="facet-range-input" placeholder="0" />
                </div>
                <div className="facet-range-separator">to</div>
                <div className="facet-range-input-group">
                    <label className="facet-range-label">Max</label>
                    <input type="number" className="facet-range-input" placeholder="100" />
                </div>
            </div>
        </div>
    );

    const renderButtonPreview = () => (
        <div className="facet-button-wrapper">
            <div className="facet-button-container">
                <div className="facet-button-list">
                    <span className="facet-button-item">
                        <span>Example {attribute}</span>
                        <button type="button" className="facet-button-remove">
                            <span className="sr-only">Remove filter for Example</span>
                            <svg className="facet-button-icon" stroke="currentColor" fill="none" viewBox="0 0 8 8">
                                <path strokeLinecap="round" strokeWidth="1.5" d="M1 1l6 6m0-6L1 7"></path>
                            </svg>
                        </button>
                    </span>
                </div>
            </div>
        </div>
    );

    const renderPreview = () => {
        switch (type) {
            case 'checkbox':
                return renderCheckboxPreview();
            case 'radio':
                return renderRadioPreview();
            case 'select':
                return renderSelectPreview();
            case 'range':
                return renderRangePreview();
            case 'button':
                return renderButtonPreview();
            default:
                return <div>Unknown facet type</div>;
        }
    };

    return (
        <div className="meiliscout-facet">
            {label && <label className="facet-label">{label}</label>}
            <div className="facet-content">
                {renderPreview()}
            </div>
        </div>
    );
};

export default FacetPreview;
