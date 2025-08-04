import { BaseFacet } from '../BaseFacet';

export class SelectFacet extends BaseFacet {
    render(values, currentFilters) {
        if (!this.contentElement) return;

        const currentValue = this.getCurrentValue(currentFilters);
        this.contentElement.innerHTML = `
            <div class="facet-select-wrapper">
                <select 
                    id="facet-${this.attribute}"
                    name="${this.attribute}"
                    class="facet-select-input"
                >
                    <option value="">Select an option</option>
                    ${Object.entries(values).map(([value, count]) => {
                        const hasResults = this.hasResults(count);
                        return `
                            <option 
                                value="${value}"
                                ${currentValue === value ? 'selected' : ''}
                                ${!hasResults ? 'disabled' : ''}
                                class="${!hasResults ? 'no-results' : ''}"
                            >
                                ${value} (${count})
                            </option>
                        `;
                    }).join('')}
                </select>
                <svg class="facet-select-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M4.22 6.22a.75.75 0 0 1 1.06 0L8 8.94l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                </svg>
            </div>
        `;

        // Add event listeners
        this.contentElement.querySelector('select').addEventListener('change', (e) => {
            this.handleChange(e.target.value, true);
        });
    }

    handleChange(value, checked) {
        this.onChange(this.attribute, value, checked, true);
    }
} 