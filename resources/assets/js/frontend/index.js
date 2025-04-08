import Alpine from 'alpinejs';

// Import store
import { initMeiliscoutStore } from './stores/MeiliscoutStore';

// Import components
import { QueryLoop } from './components/QueryLoop';
import { BaseFacet } from './components/facets/BaseFacet';
import { ButtonFacet } from './components/facets/ButtonFacet';
import { CheckboxFacet } from './components/facets/CheckboxFacet';
import { RadioFacet } from './components/facets/RadioFacet';
import { RangeFacet } from './components/facets/RangeFacet';
import { SelectFacet } from './components/facets/SelectFacet';

// Initialize Alpine with our custom prefix
if (!window.Alpine) {
    window.Alpine = Alpine;
    Alpine.prefix('x-meiliscout-');
}

// Initialize MeiliscoutStore
initMeiliscoutStore();

// Register components with Alpine.js
document.addEventListener('alpine:init', () => {
    Alpine.data('QueryLoop', QueryLoop);
    Alpine.data('BaseFacet', BaseFacet);
    Alpine.data('ButtonFacet', ButtonFacet);
    Alpine.data('CheckboxFacet', CheckboxFacet);
    Alpine.data('RadioFacet', RadioFacet);
    Alpine.data('RangeFacet', RangeFacet);
    Alpine.data('SelectFacet', SelectFacet);
});

console.log('Starting Alpine.js...');

// Initialize Alpine.js
Alpine.start();
