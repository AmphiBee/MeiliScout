<?php

declare(strict_types=1);

/**
 * Render callback for the query-loop-facet block.
 *
 * @param  array  $attributes  Block attributes.
 * @return string Rendered block HTML.
 */
$block_props = get_block_wrapper_attributes();

$alpine_data = [
    'queryId' => $attributes['targetQueryId'] ?? '',
    'attribute' => $attributes['facetAttribute'] ?? '',
    'label' => $attributes['label'] ?? '',
    'showEmptyValues' => ! empty($attributes['showEmptyValues']),
];

$json = json_encode($alpine_data, JSON_THROW_ON_ERROR);

$facet_type = $attributes['facetType'] ?? '';
$component_name = ucfirst($facet_type).'Facet';
?>
<div <?php echo $block_props; ?>>
    <div
        x-meiliscout-data="<?php echo esc_attr($component_name); ?>(<?php echo esc_attr($json); ?>)"
        x-meiliscout-effect="values"
    >
        <?php if ($alpine_data['label']) { ?>
            <label class="facet-label"><?php echo esc_html($alpine_data['label']); ?></label>
        <?php } ?>

        <div class="facet-content">
            <?php if ($facet_type === 'checkbox') { ?>
                <div class="facet-checkbox-items">
                    <!-- Information de débogage intégrée -->
                    <div x-meiliscout-show="Object.keys(values).length === 0" class="facet-debug-info">
                        Aucune valeur disponible. Vérifiez la console pour plus de détails.
                    </div>

                    <!-- Utiliser Object.entries pour garantir une itération correcte -->
                    <template x-meiliscout-for="[value, count] in values" :key="value">
                        <div
                            class="facet-checkbox-wrapper"
                            :class="{
                                'no-results': count == 0,
                                'is-disabled': count == 0
                            }"
                            :aria-disabled="count === 0"
                        >
                            <div class="facet-checkbox-container">
                                <div class="facet-checkbox-group">
                                    <input
                                        :id="'facet-' + attribute + '-' + value"
                                        :name="attribute"
                                        type="checkbox"
                                        :value="value"
                                        class="facet-checkbox"
                                        :checked="isSelected(value)"
                                        :disabled="!hasResultsForValue(value)"
                                        @change="handleCheckboxChange(value, $event.target.checked)"
                                    >
                                    <svg class="facet-checkbox-icon" viewBox="0 0 14 14" fill="none">
                                        <path class="check-path" d="M3 8L6 11L11 3.5"></path>
                                        <path class="indeterminate-path" d="M3 7H11"></path>
                                    </svg>
                                </div>
                            </div>
                            <label :for="'facet-' + attribute + '-' + value" class="facet-checkbox-label">
                                <span x-meiliscout-text="value"></span>
                                <span class="facet-count" x-meiliscout-text="'(' + count + ')'"></span>
                            </label>
                        </div>
                    </template>
                </div>
            <?php } elseif ($facet_type === 'radio') { ?>
                <div class="facet-radio-items">
                    <template x-meiliscout-for="[value, count] in Object.entries(values)" :key="value">
                        <div
                            class="facet-radio-wrapper"
                            :class="{
                                'no-results': count === 0,
                                'is-disabled': count === 0
                            }"
                            :aria-disabled="count === 0"
                        >
                            <div class="facet-radio-input-container">
                                <input
                                    :id="'facet-' + attribute + '-' + value"
                                    :name="attribute"
                                    type="radio"
                                    :value="value"
                                    class="facet-radio-input"
                                    :checked="isSelected(value)"
                                    :disabled="count === 0"
                                    @change="handleRadioChange(value)"
                                    :aria-describedby="'facet-' + attribute + '-' + value + '-description'"
                                >
                            </div>
                            <div class="facet-radio-content">
                                <label :for="'facet-' + attribute + '-' + value" class="facet-radio-label">
                                    <span x-meiliscout-text="value"></span>
                                </label>
                                <span :id="'facet-' + attribute + '-' + value + '-description'" class="facet-count">
                                    <span x-meiliscout-text="'(' + count + ')'"></span>
                                </span>
                            </div>
                        </div>
                    </template>
                </div>
            <?php } elseif ($facet_type === 'select') { ?>
                <div class="facet-select-wrapper">
                    <select
                        :id="'facet-' + attribute"
                        :name="attribute"
                        class="facet-select-input"
                        @change="handleSelectChange($event.target.value)"
                    >
                        <option value="">Select an option</option>
                        <template x-meiliscout-for="[value, count] in Object.entries(values)" :key="value">
                            <option
                                :value="value"
                                :selected="isSelected(value)"
                                :disabled="count === 0"
                                :class="{ 'no-results': count === 0 }"
                                x-meiliscout-text="value + ' (' + count + ')'"
                            ></option>
                        </template>
                    </select>
                    <svg class="facet-select-icon" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M4.22 6.22a.75.75 0 0 1 1.06 0L8 8.94l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                    </svg>
                </div>
            <?php } elseif ($facet_type === 'range') { ?>
                <div class="facet-range-wrapper">
                    <div class="facet-range-inputs">
                        <div class="facet-range-input-group">
                            <label :for="'facet-' + attribute + '-min'" class="facet-range-label">Min</label>
                            <input
                                type="number"
                                :id="'facet-' + attribute + '-min'"
                                :name="attribute + '-min'"
                                class="facet-range-input"
                                :min="min"
                                :max="max"
                                :value="getCurrentValue()?.min || min"
                                placeholder="Min"
                                @change="handleRangeChange"
                            >
                        </div>
                        <div class="facet-range-separator">to</div>
                        <div class="facet-range-input-group">
                            <label :for="'facet-' + attribute + '-max'" class="facet-range-label">Max</label>
                            <input
                                type="number"
                                :id="'facet-' + attribute + '-max'"
                                :name="attribute + '-max'"
                                class="facet-range-input"
                                :min="min"
                                :max="max"
                                :value="getCurrentValue()?.max || max"
                                placeholder="Max"
                                @change="handleRangeChange"
                            >
                        </div>
                    </div>
                </div>
            <?php } elseif ($facet_type === 'button') { ?>
                <div class="facet-button-wrapper">
                    <div class="facet-button-container">
                        <div class="facet-button-list">
                            <template x-meiliscout-for="[value, count] in Object.entries(values)" :key="value">
                                <span
                                    class="facet-button-item"
                                    :class="{
                                        'is-selected': isSelected(value),
                                        'no-results': count === 0,
                                        'is-disabled': count === 0
                                    }"
                                    :aria-disabled="count === 0"
                                >
                                    <span>
                                        <span x-meiliscout-text="value"></span>
                                        <span class="facet-count" x-meiliscout-text="'(' + count + ')'"></span>
                                    </span>
                                    <template x-meiliscout-if="isSelected(value)">
                                        <button
                                            type="button"
                                            class="facet-button-remove"
                                            @click="handleButtonClick(value, true)"
                                        >
                                            <span class="sr-only" x-meiliscout-text="'Remove filter for ' + value"></span>
                                            <svg class="facet-button-icon" stroke="currentColor" fill="none" viewBox="0 0 8 8">
                                                <path stroke-linecap="round" stroke-width="1.5" d="M1 1l6 6m0-6L1 7"></path>
                                            </svg>
                                        </button>
                                    </template>
                                    <template x-meiliscout-if="!isSelected(value)">
                                        <button
                                            type="button"
                                            class="facet-button-add"
                                            :disabled="count === 0"
                                            @click="handleButtonClick(value, false)"
                                        >
                                            <span class="sr-only" x-meiliscout-text="'Add filter for ' + value"></span>
                                            <svg class="facet-button-icon" stroke="currentColor" fill="none" viewBox="0 0 8 8">
                                                <path stroke-linecap="round" stroke-width="1.5" d="M4 1v6M1 4h6"></path>
                                            </svg>
                                        </button>
                                    </template>
                                </span>
                            </template>
                        </div>
                    </div>
                </div>
            <?php } ?>
        </div>
    </div>
</div>
