import { BaseFacet } from '../BaseFacet';

export class RangeFacet extends BaseFacet {
    render(values, currentFilters) {
        if (!this.contentElement) return;

        // Trouver les valeurs min et max
        const numbers = Object.keys(values).map(Number);
        const min = Math.min(...numbers);
        const max = Math.max(...numbers);

        // Récupérer les valeurs actuelles
        const currentValue = this.getCurrentValue(currentFilters) || {};
        const currentMin = currentValue.min || min;
        const currentMax = currentValue.max || max;

        this.contentElement.innerHTML = `
            <div class="facet-range-wrapper">
                <div class="facet-range-inputs">
                    <div class="facet-range-input-group">
                        <label for="facet-${this.attribute}-min" class="facet-range-label">Min</label>
                        <input
                            type="number"
                            id="facet-${this.attribute}-min"
                            name="${this.attribute}-min"
                            class="facet-range-input"
                            min="${min}"
                            max="${max}"
                            value="${currentMin}"
                            placeholder="Min"
                        >
                    </div>
                    <div class="facet-range-separator">to</div>
                    <div class="facet-range-input-group">
                        <label for="facet-${this.attribute}-max" class="facet-range-label">Max</label>
                        <input
                            type="number"
                            id="facet-${this.attribute}-max"
                            name="${this.attribute}-max"
                            class="facet-range-input"
                            min="${min}"
                            max="${max}"
                            value="${currentMax}"
                            placeholder="Max"
                        >
                    </div>
                </div>
            </div>
        `;

        // Add event listeners
        const minInput = this.contentElement.querySelector(`#facet-${this.attribute}-min`);
        const maxInput = this.contentElement.querySelector(`#facet-${this.attribute}-max`);

        const updateRange = () => {
            const minVal = parseInt(minInput.value);
            const maxVal = parseInt(maxInput.value);

            if (!isNaN(minVal) && !isNaN(maxVal) && minVal <= maxVal) {
                this.handleChange({ min: minVal, max: maxVal }, true);
            }
        };

        minInput.addEventListener('change', updateRange);
        maxInput.addEventListener('change', updateRange);
    }

    handleChange(value, checked) {
        this.onChange(this.attribute, value, checked, true);
    }
} 