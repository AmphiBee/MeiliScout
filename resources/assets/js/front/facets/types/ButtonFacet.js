import { BaseFacet } from '../BaseFacet';

export class ButtonFacet extends BaseFacet {
    render(values, currentFilters) {
        if (!this.contentElement) return;

        const currentValues = Array.isArray(this.getCurrentValue(currentFilters))
            ? this.getCurrentValue(currentFilters)
            : [this.getCurrentValue(currentFilters)].filter(Boolean);

        this.contentElement.innerHTML = `
            <div class="facet-button-wrapper">
                <div class="facet-button-container">
                    <div class="facet-button-list">
                        ${Object.entries(values).map(([value, count]) => {
                            const hasResults = this.hasResults(count);
                            const isSelected = currentValues.includes(value);
                            const classes = [
                                'facet-button-item',
                                isSelected ? 'is-selected' : '',
                                !hasResults ? 'no-results' : ''
                            ].filter(Boolean).join(' ');

                            return `
                                <span class="${classes}" ${!hasResults ? 'aria-disabled="true"' : ''}>
                                    <span>${value} <span class="facet-count">(${count})</span></span>
                                    ${isSelected ? `
                                        <button
                                            type="button"
                                            class="facet-button-remove"
                                            data-value="${value}"
                                        >
                                            <span class="sr-only">Remove filter for ${value}</span>
                                            <svg class="facet-button-icon" stroke="currentColor" fill="none" viewBox="0 0 8 8">
                                                <path stroke-linecap="round" stroke-width="1.5" d="M1 1l6 6m0-6L1 7"></path>
                                            </svg>
                                        </button>
                                    ` : `
                                        <button
                                            type="button"
                                            class="facet-button-add"
                                            data-value="${value}"
                                            ${!hasResults ? 'disabled' : ''}
                                        >
                                            <span class="sr-only">Add filter for ${value}</span>
                                            <svg class="facet-button-icon" stroke="currentColor" fill="none" viewBox="0 0 8 8">
                                                <path stroke-linecap="round" stroke-width="1.5" d="M4 1v6M1 4h6"></path>
                                            </svg>
                                        </button>
                                    `}
                                </span>
                            `;
                        }).join('')}
                    </div>
                </div>
            </div>
        `;

        // Add event listeners
        this.contentElement.querySelectorAll('.facet-button-remove').forEach(button => {
            button.addEventListener('click', (e) => {
                const value = e.currentTarget.dataset.value;
                this.handleChange(value, false);
            });
        });

        this.contentElement.querySelectorAll('.facet-button-add').forEach(button => {
            button.addEventListener('click', (e) => {
                const value = e.currentTarget.dataset.value;
                this.handleChange(value, true);
            });
        });
    }

    handleChange(value, checked) {
        this.onChange(this.attribute, value, checked, false);
    }
}
