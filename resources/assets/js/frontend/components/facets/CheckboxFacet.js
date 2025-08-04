import { BaseFacet } from './BaseFacet';

export const CheckboxFacet = (config) => {
    const base = BaseFacet(config);

    return {
        ...base,

        // Additional methods specific to checkbox facets
        handleCheckboxChange(value, checked) {
            this.handleChange(value, checked, false);
        }
    };
};
