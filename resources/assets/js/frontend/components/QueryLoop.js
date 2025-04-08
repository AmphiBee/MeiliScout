import Alpine from 'alpinejs';

export const QueryLoop = (config) => ({
    // State
    config: config,
    queryId: '',
    enableUrlParams: false,
    localPosts: '', // Local cache for posts
    localIsLoading: false, // Local cache for loading state
    
    // Methods
    init() {
        this.queryId = this.config.queryId;
        this.enableUrlParams = this.config.enableUrlParams;
        
        // Initialize the query in the store
        Alpine.store('meiliscout').initQuery(this.queryId, this.config);
        
        // Initialize local values
        const queryData = Alpine.store('meiliscout').getQuery(this.queryId);
        if (queryData) {
            this.localPosts = queryData.posts;
            this.localIsLoading = queryData.isLoading;
        }
        
        // Watch for store changes using Alpine's $watch
        this.$watch('$store.meiliscout.queries', (queries) => {
            if (queries[this.queryId]) {
                this.localPosts = queries[this.queryId].posts;
                this.localIsLoading = queries[this.queryId].isLoading;
            }
        });
    },
    
    // Computed properties that connect to the store
    get posts() {
        return this.localPosts || '';
    },
    
    get isLoading() {
        return this.localIsLoading;
    },
    
    get hasFilters() {
        return Alpine.store('meiliscout').hasFilters(this.queryId);
    }
}); 