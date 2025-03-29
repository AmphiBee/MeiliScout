import { BaseFacet } from '../BaseFacet';

export class RadioFacet extends BaseFacet {
    render(values, currentFilters) {
        if (!this.contentElement) return;

        const currentValue = this.getCurrentValue(currentFilters);
        this.contentElement.innerHTML = `
            <div class="facet-radio-items">
                ${Object.entries(values).map(([value, count]) => {
                    const hasResults = this.hasResults(count);
                    const isChecked = currentValue === value;
                    const classes = [
                        'facet-radio-wrapper',
                        !hasResults ? 'no-results' : ''
                    ].filter(Boolean).join(' ');

                    return `
                        <div class="${classes}" ${!hasResults ? 'aria-disabled="true"' : ''}>
                            <div class="facet-radio-input-container">
                                <input
                                    id="facet-${this.attribute}-${value}"
                                    name="${this.attribute}"
                                    type="radio"
                                    value="${value}"
                                    class="facet-radio-input"
                                    ${isChecked ? 'checked' : ''}
                                    ${!hasResults ? 'disabled' : ''}
                                    aria-describedby="facet-${this.attribute}-${value}-description"
                                >
                            </div>
                            <div class="facet-radio-content">
                                <label for="facet-${this.attribute}-${value}" class="facet-radio-label">
                                    ${value}
                                </label>
                                <span id="facet-${this.attribute}-${value}-description" class="facet-count">
                                    (${count})
                                </span>
                            </div>
                        </div>
                    `;
                }).join('')}
            </div>
        `;

        // Add event listeners
        this.contentElement.querySelectorAll('input[type="radio"]').forEach(input => {
            input.addEventListener('change', (e) => {
                this.handleChange(e.target.value, e.target.checked);
            });
        });
    }

    handleChange(value, checked) {
        this.onChange(this.attribute, value, checked, true);
    }
}
