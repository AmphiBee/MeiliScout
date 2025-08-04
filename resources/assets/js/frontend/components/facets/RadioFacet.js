import { BaseFacet } from './BaseFacet';

export const RadioFacet = (config) => {
    const base = BaseFacet(config);

    return {
        ...base,

        // Additional methods specific to radio facets
        handleRadioChange(value) {
            this.handleChange(value, true, true);
        }
    };
};
