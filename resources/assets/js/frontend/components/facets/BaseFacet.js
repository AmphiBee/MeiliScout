import Alpine from 'alpinejs';

export const BaseFacet = (config) => ({
    config: config,
    // State
    queryId: '',
    attribute: '',
    label: '',
    showEmptyValues: false,
    localValues: {}, // Local cache for facet values
    localAllValues: {}, // Local cache for all facet values
    values: {}, // Direct property for facet values

    // Methods
    init() {
        const config = this.config;
        this.queryId = config.queryId;
        this.attribute = config.attribute;
        this.label = config.label || '';
        this.showEmptyValues = config.showEmptyValues || false;

        // Debug info
        console.log(`[BaseFacet init] ID: ${this.queryId}, Attribute: ${this.attribute}`);

        // Initialize values from store immediately
        this.updateLocalValues();

        // Watch for store changes using Alpine's $watch
        this.$watch('$store.meiliscout.queries', (queries) => {
            if (queries[this.queryId]) {
                console.log(`[BaseFacet watcher] Store updated for ${this.queryId}`);
                this.updateLocalValues();
            }
        });
    },

    // Update local cached values from the store
    updateLocalValues() {
        const previousValues = JSON.stringify(this.values);

        this.localValues = Alpine.store('meiliscout').getFacetValues(
            this.queryId,
            this.attribute,
            this.showEmptyValues
        );

        this.localAllValues = Alpine.store('meiliscout').getAllFacetValues(
            this.queryId,
            this.attribute
        );

        // Assure que values soit un objet clair de type { 'Actus': 5, 'Cat': 0, ... }
        this.values = {};

        Object.entries(this.localAllValues || {}).forEach(([key]) => {

            this.values[key] = this.localValues[key] ?? 0;
        });

        const newValues = JSON.stringify(this.values);

        if (previousValues !== newValues) {
            console.log(`[BaseFacet ${this.queryId}] Values updated for ${this.attribute}:`, this.values);

            const entries = Object.entries(this.values);
            console.log(`[BaseFacet ${this.queryId}] Values has ${entries.length} entries`);

            const zeroValues = entries.filter(([, count]) => count === 0);
            if (zeroValues.length > 0) {
                console.log(`[BaseFacet ${this.queryId}] Has ${zeroValues.length} zero-count values:`,
                    zeroValues.map(([value]) => value));
            }
        }
    },


    // We still provide the getter for backward compatibility
    get facetValues() {
        return this.values;
    },

    get allValues() {
        return this.localAllValues;
    },

    get hasResults() {
        return Object.values(this.values).some(count => count > 0);
    },

    // Methods
    handleChange(value, checked, isSingle = false) {
        console.log(`[BaseFacet ${this.queryId}] Change triggered: ${this.attribute} = ${value}, checked: ${checked}, isSingle: ${isSingle}`);
        Alpine.store('meiliscout').updateFilter(this.queryId, this.attribute, value, checked, isSingle);
    },

    getCurrentValue() {
        return Alpine.store('meiliscout').getCurrentFilterValue(this.queryId, this.attribute);
    },

    isSelected(value) {
        return Alpine.store('meiliscout').isValueSelected(this.queryId, this.attribute, value);
    },

    hasResultsForValue(value) {
        const count = this.values[value] || 0;
        console.log('hasResultsForValue', count > 0);
        return count > 0;
    },

    // Helper for debugging in templates
    debugValues() {
        console.log(`[DEBUG] ${this.attribute} values:`, this.values);
        console.log(`[DEBUG] ${this.attribute} values type:`, typeof this.values);
        console.log(`[DEBUG] ${this.attribute} is array:`, Array.isArray(this.values));
        console.log(`[DEBUG] ${this.attribute} keys:`, Object.keys(this.values));

        const entries = Object.entries(this.values);
        console.log(`[DEBUG] ${this.attribute} entries for x-for:`, entries);
        console.log(`[DEBUG] ${this.attribute} entry count:`, entries.length);

        // Ajouter des informations sur les valeurs sans résultats
        const zeroEntries = entries.filter(([, count]) => count === 0);
        console.log(`[DEBUG] ${this.attribute} zero-count entries:`, zeroEntries);

        return true;
    }
});
