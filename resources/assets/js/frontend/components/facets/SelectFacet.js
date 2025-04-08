import { BaseFacet } from './BaseFacet';

export const SelectFacet = (config) => {
    const base = BaseFacet(config);

    return {
        ...base,

        // Additional methods specific to select facets
        handleSelectChange(value) {
            this.handleChange(value, true, true);
        }
    };
};
