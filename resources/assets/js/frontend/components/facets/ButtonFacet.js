import { BaseFacet } from './BaseFacet';

export const ButtonFacet = (config) => {
    const base = BaseFacet(config);

    return {
        ...base,

        // Additional methods specific to button facets
        handleButtonClick(value, isSelected) {
            this.handleChange(value, !isSelected, false);
        }
    };
};
