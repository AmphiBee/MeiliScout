import { FacetFactory } from './FacetFactory';

export class FacetManager {
    static instance = null;

    static getInstance() {
        if (!FacetManager.instance) {
            FacetManager.instance = new FacetManager();
        }
        return FacetManager.instance;
    }

    constructor() {
        // Prevent multiple instances
        if (FacetManager.instance) {
            return FacetManager.instance;
        }

        this.queryLoops = new Map(); // Map of queryId -> { element, filters, template, enableUrlParams, facetValues }
        this.facets = new Map(); // Map of facetId -> FacetInstance
        this.urlEnabledQueryId = null; // Store the first query ID that has URL params enabled
        this.init();
    }

    init() {
        // Initialize query loops
        document.querySelectorAll('.wp-block-query-meilisearch').forEach(queryElement => {
            const queryId = queryElement.dataset.queryId || 'default';
            const enableUrlParams = queryElement.dataset.enableUrlParams === 'true';

            console.log('Initializing query loop:', { queryId, element: queryElement, enableUrlParams });

            // If URL params are enabled and we don't have a urlEnabledQueryId yet, set it
            if (enableUrlParams && !this.urlEnabledQueryId) {
                this.urlEnabledQueryId = queryId;
            }

            this.queryLoops.set(queryId, {
                element: queryElement,
                filters: new Map(),
                template: queryElement.dataset.template || '',
                enableUrlParams,
                facetValues: new Map() // Store all possible values for facets
            });
        });

        console.log('Query loops initialized:', Array.from(this.queryLoops.keys()));

        // Initialize facets
        document.querySelectorAll('.meiliscout-facet').forEach(facetElement => {
            const parentBlock = facetElement.closest('[data-facet-type]');
            if (!parentBlock) {
                console.warn('Facet element has no parent block with data-facet-type:', facetElement);
                return;
            }

            const type = parentBlock.dataset.facetType;
            const attribute = parentBlock.dataset.facetAttribute;
            const targetQueryId = parentBlock.dataset.targetQueryId || 'default';
            const showEmptyValues = parentBlock.dataset.targetShowEmptyValues === 'true';

            console.log('Initializing facet:', {
                type,
                attribute,
                targetQueryId,
                showEmptyValues,
                parentBlockDataset: parentBlock.dataset,
                element: facetElement
            });

            // Only initialize facets that have a corresponding query loop
            if (this.queryLoops.has(targetQueryId)) {
                const facetId = `${targetQueryId}-${attribute}`;
                try {
                    const facetInstance = FacetFactory.createFacet(
                        type,
                        facetElement,
                        attribute,
                        targetQueryId
                    );

                    // Set the showEmptyValues property
                    facetInstance.showEmptyValues = showEmptyValues;
                    console.log('Created facet instance:', {
                        facetId,
                        showEmptyValues: facetInstance.showEmptyValues,
                        instance: facetInstance
                    });

                    // Set the onChange callback
                    facetInstance.setOnChange((attribute, value, checked, isSingle) => {
                        this.handleFacetChange(facetId, value, checked, isSingle);
                    });

                    this.facets.set(facetId, facetInstance);
                } catch (error) {
                    console.error(`Failed to create facet: ${error.message}`);
                }
            } else {
                console.warn(`No query loop found for targetQueryId: ${targetQueryId}`);
            }
        });

        console.log('Facets initialized:', Array.from(this.facets.keys()));

        // Load filters from URL and fetch facets for each query loop
        this.queryLoops.forEach((queryData, queryId) => {
            this.loadFiltersFromUrl(queryId);
        });

        // Fetch facets only once per queryId
        const processedQueryIds = new Set();
        console.log('Starting to fetch facets for queryIds...');
        this.facets.forEach((facet, facetId) => {
            const queryId = facet.targetQueryId;
            if (!processedQueryIds.has(queryId)) {
                console.log('Fetching facets for queryId:', queryId);
                processedQueryIds.add(queryId);
                this.fetchFacets(queryId);
            }
        });
    }

    loadFiltersFromUrl(queryId) {
        const queryData = this.queryLoops.get(queryId);

        console.log('Loading filters from URL for queryId:', this.urlEnabledQueryId);

        if (!queryData || !queryData.enableUrlParams || queryId !== this.urlEnabledQueryId) {
            return;
        }

        const urlParams = new URLSearchParams(window.location.search);

        // Look for our prefixed parameters (ms-tax-, ms-meta-, etc.)
        for (const [key, value] of urlParams.entries()) {
            if (key.startsWith('ms-')) {
                const [, type, attribute] = key.split('-');
                if (attribute) {
                    // Handle comma-separated values
                    const values = value.split(',').map(v => v.trim()).filter(Boolean);
                    if (values.length > 1) {
                        queryData.filters.set(attribute, values);
                    } else {
                        queryData.filters.set(attribute, values[0]);
                    }
                    console.log('Loaded filter:', { key, value, type, attribute, values });
                }
            }
        }
    }

    updateUrl() {
        // Only update URL if we have a query with URL params enabled
        if (!this.urlEnabledQueryId) {
            return;
        }

        const queryData = this.queryLoops.get(this.urlEnabledQueryId);
        if (!queryData || !queryData.enableUrlParams) {
            return;
        }

        const urlParams = new URLSearchParams(window.location.search);

        // Remove all existing ms- parameters
        Array.from(urlParams.keys())
            .filter(key => key.startsWith('ms-'))
            .forEach(key => urlParams.delete(key));

        // Add new parameters
        if (queryData.filters.size > 0) {
            queryData.filters.forEach((value, key) => {
                const facet = Array.from(this.facets.values())
                    .find(f => f.targetQueryId === this.urlEnabledQueryId && f.attribute === key);

                if (facet) {
                    const parentBlock = facet.element.closest('[data-filter-type]');
                    const filterType = parentBlock ? parentBlock.dataset.filterType : 'tax';
                    const prefix = filterType === 'taxonomy' ? 'tax' : 'meta';

                    // Create the parameter key with our new format
                    const paramKey = `ms-${prefix}-${key}`;

                    // Handle both single values and arrays
                    if (Array.isArray(value)) {
                        // Join multiple values with commas
                        urlParams.set(paramKey, value.join(','));
                    } else {
                        urlParams.set(paramKey, value);
                    }
                }
            });
        }

        const newUrl = `${window.location.pathname}${urlParams.toString() ? '?' + urlParams.toString() : ''}`;
        window.history.pushState({}, '', newUrl);
    }

    async fetchFacets(queryId) {
        const queryData = this.queryLoops.get(queryId);
        if (!queryData) return;

        try {
            console.log('Making API call for queryId:', queryId);
            const queryParams = new URLSearchParams(window.location.search);
            const currentPage = queryParams.get('paged') || 1;

            const queryAttrs = queryData.element.dataset.query ? JSON.parse(queryData.element.dataset.query) : {};

            // Get all facets for this queryId
            const facets = Array.from(this.facets.values())
                .filter(facet => facet.targetQueryId === queryId)
                .map(facet => ({
                    attribute: facet.attribute,
                    showEmptyValues: facet.showEmptyValues
                }));

            console.log('Facets for queryId:', queryId, facets);

            const requestBody = {
                query: {
                    ...queryAttrs,
                    paged: currentPage,
                    use_meilisearch: true,
                    facets: facets
                },
                template: queryData.template
            };

            if (queryData.filters.size > 0) {
                const filters = {};
                queryData.filters.forEach((value, key) => {
                    const facet = Array.from(this.facets.values())
                        .find(f => f.targetQueryId === queryId && f.attribute === key);

                    if (facet) {
                        const parentBlock = facet.element.closest('[data-filter-type]');
                        const filterType = parentBlock ? parentBlock.dataset.filterType : 'taxonomy';

                        filters[key] = {
                            value: value,
                            type: filterType
                        };
                    }
                });
                requestBody.filters = filters;
            }

            console.log('Fetching facets with request:', requestBody);

            const response = await fetch('/wp-json/meiliscout/v1/facets', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(requestBody)
            });

            const data = await response.json();
            console.log('Received facets response for queryId:', queryId, data);

            // Store all facet values (including empty ones)
            if (data.all_facet_values) {
                queryData.facetValues = new Map(Object.entries(data.all_facet_values));
            }

            this.updateFacets(data.facet_distribution || {}, queryId);
            this.updatePosts(data.posts, queryId);
            this.updateUrl();

        } catch (error) {
            console.error(`Error fetching facets for query ${queryId}:`, error);
        }
    }

    updateFacets(distribution, queryId) {
        const queryData = this.queryLoops.get(queryId);
        if (!queryData) return;

        console.log('Updating facets for queryId:', queryId, {
            distribution,
            allFacetValues: queryData.facetValues,
        });

        Array.from(this.facets.values())
            .filter(facet => facet.targetQueryId === queryId)
            .forEach(facet => {
                let values = distribution[facet.attribute] || {};

                // If showEmptyValues is true, merge with all possible values
                if (facet.showEmptyValues && queryData.facetValues.has(facet.attribute)) {
                    console.log('Processing facet with showEmptyValues:', {
                        attribute: facet.attribute,
                        currentValues: values,
                        allPossibleValues: queryData.facetValues.get(facet.attribute)
                    });

                    const allValues = queryData.facetValues.get(facet.attribute);
                    values = Object.fromEntries(
                        Object.keys(allValues).map(value => [
                            value,
                            distribution[facet.attribute]?.[value] || 0
                        ])
                    );

                    console.log('Merged values:', values);
                }

                facet.render(values, queryData.filters);
            });
    }

    updatePosts(postsHtml, queryId) {
        const queryData = this.queryLoops.get(queryId);

        if (!queryData) return;

        const postTemplates = queryData.element.querySelectorAll('.wp-block-post-template');

        postTemplates.forEach(container => {
            container.innerHTML = postsHtml;
        });
    }

    handleFacetChange(facetId, value, checked, isSingle = false) {
        const facet = this.facets.get(facetId);
        if (!facet) return;

        const queryData = this.queryLoops.get(facet.targetQueryId);
        if (!queryData) return;

        if (isSingle) {
            if (checked) {
                queryData.filters.set(facet.attribute, value);
            } else {
                queryData.filters.delete(facet.attribute);
            }
        } else {
            let values = queryData.filters.get(facet.attribute) || [];
            if (!Array.isArray(values)) {
                values = [];
            }

            if (checked) {
                values.push(value);
            } else {
                values = values.filter(v => v !== value);
            }

            if (values.length) {
                queryData.filters.set(facet.attribute, values);
            } else {
                queryData.filters.delete(facet.attribute);
            }
        }

        this.fetchFacets(facet.targetQueryId);
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    FacetManager.getInstance();
});
