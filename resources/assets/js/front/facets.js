import { FacetManager } from './facets/FacetManager';

// Facet Manager Class
class FacetManager {
    constructor() {
        this.queryLoops = new Map(); // Map of queryId -> { element, filters, template }
        this.facets = new Map(); // Map of facetId -> { element, type, attribute, filterType, targetQueryId }
        this.init();
    }

    init() {
        // Initialize query loops
        document.querySelectorAll('.wp-block-query-meilisearch').forEach(queryElement => {
            const queryId = queryElement.dataset.queryId || 'default';
            this.queryLoops.set(queryId, {
                element: queryElement,
                filters: new Map(),
                template: queryElement.dataset.template || ''
            });
        });

        // Initialize facets
        document.querySelectorAll('.meiliscout-facet').forEach(facetElement => {
            const parentBlock = facetElement.closest('[data-facet-type]');
            if (!parentBlock) return;

            const type = parentBlock.dataset.facetType;
            const attribute = parentBlock.dataset.facetAttribute;
            const filterType = parentBlock.dataset.filterType || 'taxonomy';
            const targetQueryId = parentBlock.dataset.targetQueryId || 'default';

            // Only initialize facets that have a corresponding query loop
            if (this.queryLoops.has(targetQueryId)) {
                this.facets.set(`${targetQueryId}-${attribute}`, {
                    element: facetElement,
                    type: type,
                    attribute: attribute,
                    filterType: filterType,
                    targetQueryId: targetQueryId
                });
            }
        });

        // Load filters from URL and fetch facets for each query loop
        this.queryLoops.forEach((queryData, queryId) => {
            this.loadFiltersFromUrl(queryId);
            this.fetchFacets(queryId);
        });
    }

    loadFiltersFromUrl(queryId) {
        const urlParams = new URLSearchParams(window.location.search);
        const filtersParam = urlParams.get(`filters-${queryId}`);

        if (filtersParam) {
            try {
                const filters = JSON.parse(decodeURIComponent(filtersParam));
                const queryData = this.queryLoops.get(queryId);
                if (queryData) {
                    Object.entries(filters).forEach(([key, value]) => {
                        queryData.filters.set(key, value);
                    });
                }
            } catch (e) {
                console.error(`Error parsing filters from URL for query ${queryId}:`, e);
            }
        }
    }

    updateUrl() {
        const urlParams = new URLSearchParams(window.location.search);

        // Update filters in URL for each query loop
        this.queryLoops.forEach((queryData, queryId) => {
            if (queryData.filters.size > 0) {
                const filters = {};
                queryData.filters.forEach((value, key) => {
                    const facetId = `${queryId}-${key}`;
                    const facet = this.facets.get(facetId);
                    if (facet) {
                        filters[key] = {
                            value,
                            type: facet.filterType
                        };
                    }
                });
                urlParams.set(`filters-${queryId}`, encodeURIComponent(JSON.stringify(filters)));
            } else {
                urlParams.delete(`filters-${queryId}`);
            }
        });

        // Update URL without reloading the page
        const newUrl = `${window.location.pathname}${urlParams.toString() ? '?' + urlParams.toString() : ''}`;
        window.history.pushState({}, '', newUrl);
    }

    async fetchFacets(queryId) {
        const queryData = this.queryLoops.get(queryId);
        if (!queryData) return;

        try {
            const queryParams = new URLSearchParams(window.location.search);
            const currentPage = queryParams.get('paged') || 1;

            // Get Query Loop block attributes
            const queryAttrs = queryData.element.dataset.query ? JSON.parse(queryData.element.dataset.query) : {};

            // Get facets for this query loop
            const facets = Array.from(this.facets.values())
                .filter(facet => facet.targetQueryId === queryId)
                .map(facet => facet.attribute);

            // Prepare request body
            const requestBody = {
                query: {
                    ...queryAttrs,
                    paged: currentPage,
                    use_meilisearch: true,
                    facets: facets
                },
                template: queryData.template
            };

            // Add filters if any
            if (queryData.filters.size > 0) {
                const filters = {};
                queryData.filters.forEach((value, key) => {
                    const facetId = `${queryId}-${key}`;
                    const facet = this.facets.get(facetId);
                    if (facet) {
                        filters[key] = {
                            value,
                            type: facet.filterType
                        };
                    }
                });
                requestBody.filters = filters;
            }

            const response = await fetch('/wp-json/meiliscout/v1/facets', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(requestBody)
            });

            const data = await response.json();

            this.updateFacets(data.facet_distribution, queryId);
            this.updatePosts(data.posts, queryId);
            this.updateUrl();

        } catch (error) {
            console.error(`Error fetching facets for query ${queryId}:`, error);
        }
    }

    updateFacets(distribution, queryId) {
        // Update only facets associated with this query loop
        Array.from(this.facets.values())
            .filter(facet => facet.targetQueryId === queryId)
            .forEach(facet => {
                const values = distribution[facet.attribute] || {};
                this.renderFacet(facet, values);
            });
    }

    renderFacet(facet, values) {
        const contentElement = facet.element.querySelector('.facet-content');
        if (!contentElement) return;

        contentElement.innerHTML = '';

        switch (facet.type) {
            case 'checkbox':
                this.renderCheckboxFacet(contentElement, values, facet.attribute);
                break;
            case 'radio':
                this.renderRadioFacet(contentElement, values, facet.attribute);
                break;
            case 'select':
                this.renderSelectFacet(contentElement, values, facet.attribute);
                break;
            case 'range':
                this.renderRangeFacet(contentElement, values, facet.attribute);
                break;
        }
    }

    renderCheckboxFacet(container, values, attribute) {
        const facetId = `${this.getFacetByAttribute(attribute)?.targetQueryId || 'default'}-${attribute}`;
        const facet = this.facets.get(facetId);
        if (!facet) return;

        const queryData = this.queryLoops.get(facet.targetQueryId);
        if (!queryData) return;

        Object.entries(values).forEach(([value, count]) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'facet-checkbox-item';

            const input = document.createElement('input');
            input.type = 'checkbox';
            input.id = `facet-${attribute}-${value}`;
            input.value = value;

            // Vérifier si la valeur est sélectionnée dans les filtres du query loop
            const currentValues = queryData.filters.get(attribute) || [];
            input.checked = Array.isArray(currentValues)
                ? currentValues.includes(value)
                : currentValues === value;

            input.addEventListener('change', () => this.handleFacetChange(facetId, value, input.checked));

            const label = document.createElement('label');
            label.htmlFor = input.id;
            label.textContent = `${value} (${count})`;

            wrapper.appendChild(input);
            wrapper.appendChild(label);
            container.appendChild(wrapper);
        });
    }

    renderRadioFacet(container, values, attribute) {
        const facetId = `${this.getFacetByAttribute(attribute)?.targetQueryId || 'default'}-${attribute}`;
        const facet = this.facets.get(facetId);
        if (!facet) return;

        const queryData = this.queryLoops.get(facet.targetQueryId);
        if (!queryData) return;

        Object.entries(values).forEach(([value, count]) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'facet-radio-item';

            const input = document.createElement('input');
            input.type = 'radio';
            input.name = `facet-${attribute}`;
            input.id = `facet-${attribute}-${value}`;
            input.value = value;
            input.checked = queryData.filters.get(attribute) === value;

            input.addEventListener('change', () => {
                if (input.checked) {
                    this.handleFacetChange(facetId, value, true, true);
                }
            });

            const label = document.createElement('label');
            label.htmlFor = input.id;
            label.textContent = `${value} (${count})`;

            wrapper.appendChild(input);
            wrapper.appendChild(label);
            container.appendChild(wrapper);
        });
    }

    renderSelectFacet(container, values, attribute) {
        const facetId = `${this.getFacetByAttribute(attribute)?.targetQueryId || 'default'}-${attribute}`;
        const facet = this.facets.get(facetId);
        if (!facet) return;

        const queryData = this.queryLoops.get(facet.targetQueryId);
        if (!queryData) return;

        const select = document.createElement('select');
        select.className = 'facet-select';

        const defaultOption = document.createElement('option');
        defaultOption.value = '';
        defaultOption.textContent = 'Select an option';
        select.appendChild(defaultOption);

        Object.entries(values).forEach(([value, count]) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = `${value} (${count})`;
            option.selected = queryData.filters.get(attribute) === value;
            select.appendChild(option);
        });

        select.addEventListener('change', () => {
            this.handleFacetChange(facetId, select.value, true, true);
        });

        container.appendChild(select);
    }

    renderRangeFacet(container, values, attribute) {
        const facetId = `${this.getFacetByAttribute(attribute)?.targetQueryId || 'default'}-${attribute}`;
        const facet = this.facets.get(facetId);
        if (!facet) return;

        const queryData = this.queryLoops.get(facet.targetQueryId);
        if (!queryData) return;

        const numbers = Object.keys(values).map(Number);
        const min = Math.min(...numbers);
        const max = Math.max(...numbers);

        const wrapper = document.createElement('div');
        wrapper.className = 'facet-range';

        const minInput = document.createElement('input');
        minInput.type = 'number';
        minInput.min = min;
        minInput.max = max;
        minInput.value = queryData.filters.get(`${attribute}_min`) || min;

        const maxInput = document.createElement('input');
        maxInput.type = 'number';
        maxInput.min = min;
        maxInput.max = max;
        maxInput.value = queryData.filters.get(`${attribute}_max`) || max;

        const updateRange = () => {
            const minVal = parseInt(minInput.value);
            const maxVal = parseInt(maxInput.value);

            if (minVal <= maxVal) {
                this.handleFacetChange(`${facetId}_min`, minVal, true, true);
                this.handleFacetChange(`${facetId}_max`, maxVal, true, true);
            }
        };

        minInput.addEventListener('change', updateRange);
        maxInput.addEventListener('change', updateRange);

        wrapper.appendChild(minInput);
        wrapper.appendChild(document.createTextNode(' - '));
        wrapper.appendChild(maxInput);
        container.appendChild(wrapper);
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

    updatePosts(postsHtml, queryId) {
        const queryData = this.queryLoops.get(queryId);
        if (!queryData) return;

        const postTemplates = queryData.element.querySelectorAll('.wp-block-post-template');
        postTemplates.forEach(container => {
            container.innerHTML = postsHtml;
        });
    }

    // Nouvelle méthode utilitaire pour trouver une facette par son attribut
    getFacetByAttribute(attribute) {
        return Array.from(this.facets.values()).find(facet => facet.attribute === attribute);
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    FacetManager.getInstance();
});
