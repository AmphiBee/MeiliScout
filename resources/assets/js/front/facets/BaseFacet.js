export class BaseFacet {
    constructor(element, attribute, targetQueryId) {
        if (this.constructor === BaseFacet) {
            throw new Error("Abstract classes can't be instantiated.");
        }
        this.element = element;
        console.log(this.element);
        this.attribute = attribute;
        this.targetQueryId = targetQueryId;
        this.contentElement = element.querySelector('.facet-content');
        this.onChange = null; // Initialize onChange callback
        this.showEmptyValues = false; // Initialize showEmptyValues
    }

    /**
     * Set the onChange callback
     * @param {Function} callback The callback to be called when the facet value changes
     */
    setOnChange(callback) {
        this.onChange = callback;
    }

    /**
     * Render the facet with the given values
     * @param {Object} values The facet values and their counts
     * @param {Map} currentFilters The current filters
     */
    render(values, currentFilters) {
        throw new Error("Method 'render' must be implemented.");
    }

    /**
     * Handle value change for the facet
     * @param {string} value The new value
     * @param {boolean} checked Whether the value is checked/selected
     */
    handleChange(value, checked) {
        if (typeof this.onChange === 'function') {
            this.onChange(this.attribute, value, checked, false);
        }
    }

    /**
     * Get the current filter value(s)
     * @param {Map} currentFilters The current filters
     * @returns {any} The current value(s) for this facet
     */
    getCurrentValue(currentFilters) {
        return currentFilters.get(this.attribute);
    }

    /**
     * Check if a value has results
     * @param {number} count The number of results for this value
     * @returns {boolean} Whether the value has results
     */
    hasResults(count) {
        return count > 0;
    }
} 