import { BaseFacet } from './BaseFacet';

export const RangeFacet = (config) => {
    const base = BaseFacet(config);

    return {
        ...base,

        // Additional state for range facets
        min: 0,
        max: 0,

        // Additional methods specific to range facets
        init() {
            // Call parent init
            base.init.call(this);

            // Initialize min/max from values
            this.$watch('values', (values) => {
                const numbers = Object.keys(values).map(Number);
                this.min = Math.min(...numbers);
                this.max = Math.max(...numbers);
            });
        },

        handleRangeChange() {
            const currentValue = this.getCurrentValue() || {};
            const min = parseInt(currentValue.min) || this.min;
            const max = parseInt(currentValue.max) || this.max;

            if (!isNaN(min) && !isNaN(max) && min <= max) {
                this.handleChange({ min, max }, true, true);
            }
        }
    };
};
