import { BaseFacet } from '../BaseFacet';

export class CheckboxFacet extends BaseFacet {
    render(values, currentFilters) {
        if (!this.contentElement) return;

        const currentValues = this.getCurrentValue(currentFilters) || [];
        this.contentElement.innerHTML = `
            <div class="facet-checkbox-items">
                ${Object.entries(values).map(([value, count]) => {
                    const hasResults = this.hasResults(count);
                    const isChecked = Array.isArray(currentValues) ? currentValues.includes(value) : currentValues === value;
                    const classes = [
                        'facet-checkbox-wrapper',
                        !hasResults ? 'no-results' : ''
                    ].filter(Boolean).join(' ');

                    return `
                        <div class="${classes}" ${!hasResults ? 'aria-disabled="true"' : ''}>
                            <div class="facet-checkbox-container">
                                <div class="facet-checkbox-group">
                                    <input 
                                        id="facet-${this.attribute}-${value}"
                                        name="${this.attribute}"
                                        type="checkbox"
                                        value="${value}"
                                        class="facet-checkbox"
                                        ${isChecked ? 'checked' : ''}
                                        ${!hasResults ? 'disabled' : ''}
                                    >
                                    <svg class="facet-checkbox-icon" viewBox="0 0 14 14" fill="none">
                                        <path class="check-path" d="M3 8L6 11L11 3.5"></path>
                                        <path class="indeterminate-path" d="M3 7H11"></path>
                                    </svg>
                                </div>
                            </div>
                            <label for="facet-${this.attribute}-${value}" class="facet-checkbox-label">
                                ${value} (${count})
                            </label>
                        </div>
                    `;
                }).join('')}
            </div>
        `;

        // Add event listeners
        this.contentElement.querySelectorAll('input[type="checkbox"]').forEach(input => {
            input.addEventListener('change', (e) => {
                this.handleChange(e.target.value, e.target.checked);
            });
        });
    }

    handleChange(value, checked) {
        this.onChange(this.attribute, value, checked, false);
    }
} 