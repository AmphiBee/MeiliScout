import { FacetManager } from './FacetManager';
import { BaseFacet } from './BaseFacet';
import { FacetFactory } from './FacetFactory';
import { CheckboxFacet } from './types/CheckboxFacet';
import { RadioFacet } from './types/RadioFacet';
import { SelectFacet } from './types/SelectFacet';
import { RangeFacet } from './types/RangeFacet';
import { ButtonFacet } from './types/ButtonFacet';

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    FacetManager.getInstance();
});

// Export all components for external use
export {
    FacetManager,
    BaseFacet,
    FacetFactory,
    CheckboxFacet,
    RadioFacet,
    SelectFacet,
    RangeFacet,
    ButtonFacet
}; 