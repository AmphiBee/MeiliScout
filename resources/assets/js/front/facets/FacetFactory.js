import { CheckboxFacet } from './types/CheckboxFacet';
import { RadioFacet } from './types/RadioFacet';
import { SelectFacet } from './types/SelectFacet';
import { RangeFacet } from './types/RangeFacet';
import { ButtonFacet } from './types/ButtonFacet';
// Import other facet types here...

export class FacetFactory {
    static createFacet(type, element, attribute, targetQueryId) {
        switch (type.toLowerCase()) {
            case 'checkbox':
                return new CheckboxFacet(element, attribute, targetQueryId);
            case 'radio':
                return new RadioFacet(element, attribute, targetQueryId);
            case 'select':
                return new SelectFacet(element, attribute, targetQueryId);
            case 'range':
                return new RangeFacet(element, attribute, targetQueryId);
            case 'button':
                return new ButtonFacet(element, attribute, targetQueryId);
            // Add other facet types here...
            default:
                throw new Error(`Unknown facet type: ${type}`);
        }
    }
} 