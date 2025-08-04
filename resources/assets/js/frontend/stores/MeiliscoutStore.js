import Alpine from 'alpinejs';

/**
 * Central Alpine store for Meiliscout
 * Manages all queries and facets state reactively
 */
export const initMeiliscoutStore = () => {
    // Create a debug flag in window for easy toggling
    window.meiliscoutDebug = true; // Enabled by default for debugging
    
    // Debug helper function
    const debug = (message, data = null) => {
        if (window.meiliscoutDebug) {
            if (data) {
                console.log(`[MeiliscoutStore] ${message}`, data);
            } else {
                console.log(`[MeiliscoutStore] ${message}`);
            }
        }
    };
    
    Alpine.store('meiliscout', {
        // State storage for different queries
        queries: {},
        
        // Enable debugging from browser console
        enableDebugging() {
            window.meiliscoutDebug = true;
            debug('Debugging enabled');
            debug('Current store state:', this.queries);
            return 'Debugging enabled for MeiliscoutStore';
        },
        
        // Disable debugging
        disableDebugging() {
            window.meiliscoutDebug = false;
            return 'Debugging disabled for MeiliscoutStore';
        },
        
        // Initialize a query with a specific ID and configuration
        initQuery(queryId, config) {
            debug(`Initializing query: ${queryId}`, config);
            
            // Always create a new query object to ensure reactivity
            // This is important for Alpine to detect changes
            this.queries = {
                ...this.queries,
                [queryId]: {
                    config: config,
                    query: config.query || {},
                    template: config.template || '',
                    posts: '',
                    facetDistribution: {},
                    allFacetValues: {},
                    filters: {},
                    isLoading: false
                }
            };
            
            debug(`Query initialized: ${queryId}`, this.queries[queryId]);
            
            // Load filters from URL if enabled
            if (config.enableUrlParams) {
                this.loadFiltersFromUrl(queryId);
            }
            
            // Initial fetch - using setTimeout to ensure the component is fully mounted
            setTimeout(() => {
                this.fetchFacets(queryId);
            }, 0);
        },
        
        // Check if a query has filters
        hasFilters(queryId) {
            const hasFilters = this.queries[queryId] && 
                   Object.keys(this.queries[queryId].filters).length > 0;
            
            debug(`hasFilters check for ${queryId}: ${hasFilters}`);
            return hasFilters;
        },
        
        // Get query data
        getQuery(queryId) {
            const query = this.queries[queryId] || null;
            debug(`getQuery for ${queryId}`, query);
            return query;
        },
        
        // Update filters
        updateFilter(queryId, attribute, value, checked, isSingle = false) {
            debug(`updateFilter: ${queryId}, ${attribute}, ${value}, checked: ${checked}, isSingle: ${isSingle}`);
            
            if (!this.queries[queryId]) {
                debug(`Query not found: ${queryId}`);
                return;
            }
            
            const queryData = this.queries[queryId];
            let newFilters = {...queryData.filters};
            
            if (isSingle) {
                if (checked) {
                    newFilters[attribute] = value;
                } else {
                    delete newFilters[attribute];
                }
            } else {
                // For multi-select filters like checkboxes
                let values = newFilters[attribute] || [];
                if (!Array.isArray(values)) {
                    values = [];
                }
                
                if (checked) {
                    if (!values.includes(value)) {
                        values = [...values, value];
                    }
                } else {
                    values = values.filter(v => v !== value);
                }
                
                if (values.length) {
                    newFilters[attribute] = values;
                } else {
                    delete newFilters[attribute];
                }
            }
            
            debug(`New filters for ${queryId}:`, newFilters);
            
            // Update filters with the new object to trigger reactivity
            this.queries = {
                ...this.queries,
                [queryId]: {
                    ...queryData,
                    filters: newFilters
                }
            };
            
            // Trigger facet fetch with updated filters
            this.fetchFacets(queryId);
        },
        
        // Load filters from URL parameters
        loadFiltersFromUrl(queryId) {
            debug(`Loading filters from URL for ${queryId}`);
            
            if (!this.queries[queryId]) {
                debug(`Query not found: ${queryId}`);
                return;
            }
            
            const urlParams = new URLSearchParams(window.location.search);
            const filters = {};
            
            for (const [key, value] of urlParams.entries()) {
                if (key.startsWith('ms-')) {
                    const [, type, attribute] = key.split('-');
                    if (attribute) {
                        const values = value.split(',').map(v => v.trim()).filter(Boolean);
                        if (values.length > 1) {
                            filters[attribute] = values;
                        } else {
                            filters[attribute] = values[0];
                        }
                    }
                }
            }
            
            debug(`Filters loaded from URL for ${queryId}:`, filters);
            
            // Update with a new object to trigger reactivity
            const queryData = this.queries[queryId];
            this.queries = {
                ...this.queries,
                [queryId]: {
                    ...queryData,
                    filters: filters
                }
            };
        },
        
        // Update URL with current filters
        updateUrl(queryId) {
            if (!this.queries[queryId] || !this.queries[queryId].config.enableUrlParams) return;
            
            const urlParams = new URLSearchParams(window.location.search);
            
            // Remove existing ms- parameters
            Array.from(urlParams.keys())
                .filter(key => key.startsWith('ms-'))
                .forEach(key => urlParams.delete(key));
            
            // Add new parameters
            if (this.hasFilters(queryId)) {
                const filters = this.queries[queryId].filters;
                
                Object.entries(filters).forEach(([key, value]) => {
                    const paramKey = `ms-tax-${key}`;
                    if (Array.isArray(value)) {
                        urlParams.set(paramKey, value.join(','));
                    } else {
                        urlParams.set(paramKey, value);
                    }
                });
            }
            
            const newUrl = `${window.location.pathname}${urlParams.toString() ? '?' + urlParams.toString() : ''}`;
            debug(`Updating URL for ${queryId}: ${newUrl}`);
            window.history.pushState({}, '', newUrl);
        },
        
        // Fetch facets and posts from the API
        async fetchFacets(queryId) {
            debug(`Fetching facets for ${queryId}`);
            
            if (!this.queries[queryId]) {
                debug(`Query not found: ${queryId}`);
                return;
            }
            
            const queryData = this.queries[queryId];
            
            // Update loading state
            this.queries = {
                ...this.queries,
                [queryId]: {
                    ...queryData,
                    isLoading: true
                }
            };
            
            debug(`Set loading state for ${queryId}: true`);
            
            try {
                const queryParams = new URLSearchParams(window.location.search);
                const currentPage = queryParams.get('paged') || 1;
                
                // Use standard facets endpoint
                const endpoint = '/wp-json/meiliscout/v1/facets';
                
                // Gutenberg context - use existing format
                const requestBody = {
                    query: {
                        ...queryData.query,
                        paged: currentPage,
                        use_meilisearch: true,
                    },
                    template: queryData.template
                };
                
                if (this.hasFilters(queryId)) {
                    const filters = {};
                    Object.entries(queryData.filters).forEach(([key, value]) => {
                        filters[key] = {
                            value: value,
                            type: 'taxonomy'
                        };
                    });
                    requestBody.filters = filters;
                }
                
                debug(`API request for ${queryId}:`, requestBody);
                
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(requestBody)
                });
                
                const data = await response.json();
                debug(`API response for ${queryId}:`, data);
                
                // Force plain JavaScript objects for facet data
                const facetDistribution = {};
                if (data.facet_distribution) {
                    Object.entries(data.facet_distribution).forEach(([key, value]) => {
                        facetDistribution[key] = {...value};
                    });
                }
                
                const allFacetValues = {};
                if (data.all_facet_values) {
                    Object.entries(data.all_facet_values).forEach(([key, value]) => {
                        allFacetValues[key] = {...value};
                    });
                }
                
                // Update store with response data - create a new object to trigger reactivity
                this.queries = {
                    ...this.queries,
                    [queryId]: {
                        ...queryData,
                        posts: data.posts,
                        facetDistribution: facetDistribution,
                        allFacetValues: allFacetValues,
                        isLoading: false
                    }
                };
                
                debug(`Updated store with API response for ${queryId}`);
                debug(`facetDistribution keys:`, Object.keys(facetDistribution));
                
                // Update URL if enabled
                this.updateUrl(queryId);
                
            } catch (error) {
                console.error('Error fetching facets:', error);
                debug(`API error for ${queryId}:`, error);
                
                // Update loading state on error
                this.queries = {
                    ...this.queries,
                    [queryId]: {
                        ...this.queries[queryId],
                        isLoading: false
                    }
                };
            }
        },
        
        // Get facet distribution for a specific attribute
        getFacetValues(queryId, attribute, showEmptyValues = false) {
            if (!this.queries[queryId]) {
                debug(`Query not found in getFacetValues: ${queryId}`);
                return {};
            }
            
            const queryData = this.queries[queryId];
            const facetDistribution = queryData.facetDistribution || {};
            let values = facetDistribution[attribute] || {};
            
            // Make sure we return a plain object, not a proxy
            values = {...values};
            
            // If showEmptyValues is true, merge with all possible values
            if (showEmptyValues && queryData.allFacetValues[attribute]) {
                const allValues = {...queryData.allFacetValues[attribute]};
                
                values = Object.fromEntries(
                    Object.keys(allValues).map(value => [
                        value,
                        values[value] || 0
                    ])
                );
            }
            
            debug(`getFacetValues for ${queryId}.${attribute}:`, values);
            debug(`getFacetValues returns object type:`, typeof values);
            debug(`getFacetValues returns keys:`, Object.keys(values));
            
            return values;
        },
        
        // Get all possible facet values
        getAllFacetValues(queryId, attribute) {
            if (!this.queries[queryId]) return {};
            const values = this.queries[queryId].allFacetValues[attribute] || {};
            
            // Make sure we return a plain object, not a proxy
            const plainValues = {...values};
            
            debug(`getAllFacetValues for ${queryId}.${attribute}:`, plainValues);
            return plainValues;
        },
        
        // Check if a facet value is selected
        isValueSelected(queryId, attribute, value) {
            if (!this.queries[queryId] || !this.queries[queryId].filters[attribute]) {
                return false;
            }
            
            const currentValue = this.queries[queryId].filters[attribute];
            let isSelected;
            
            if (Array.isArray(currentValue)) {
                isSelected = currentValue.includes(value);
            } else {
                isSelected = currentValue === value;
            }
            
            debug(`isValueSelected for ${queryId}.${attribute}.${value}: ${isSelected}`);
            return isSelected;
        },
        
        // Check if a facet value has results
        hasResultsForValue(queryId, attribute, value) {
            if (!this.queries[queryId]) {
                debug(`Query not found in hasResultsForValue: ${queryId}`);
                return false;
            }
            
            const queryData = this.queries[queryId];
            
            // Check if facetDistribution exists for this attribute
            if (!queryData.facetDistribution || !queryData.facetDistribution[attribute]) {
                debug(`No facetDistribution for ${attribute} in query ${queryId}`);
                return false;
            }
            
            // Get the number of results for this value
            const count = queryData.facetDistribution[attribute][value] || 0;
            const hasResults = count > 0;
            
            debug(`hasResultsForValue for ${queryId}.${attribute}.${value}: ${hasResults} (count: ${count})`);
            return hasResults;
        },
        
        // Get current filter value
        getCurrentFilterValue(queryId, attribute) {
            if (!this.queries[queryId]) return null;
            const value = this.queries[queryId].filters[attribute] || null;
            debug(`getCurrentFilterValue for ${queryId}.${attribute}:`, value);
            return value;
        },
        
        
        // Dump store state to console for debugging
        dumpState() {
            console.log('=== MEILISCOUT STORE STATE ===');
            console.log(JSON.parse(JSON.stringify(this.queries)));
            return 'Store state dumped to console';
        }
    });
}; 