<?php

declare(strict_types=1);

/**
 * Render callback for the query-loop-facet block.
 *
 * @param array $attributes Block attributes.
 * @return string Rendered block HTML.
 */

$block_props = get_block_wrapper_attributes([
    'data-facet-type' => $attributes['facetType'] ?? '',
    'data-facet-attribute' => $attributes['facetAttribute'] ?? '',
    'data-filter-type' => $attributes['filterType'] ?? '',
    'data-target-query-id' => $attributes['targetQueryId'] ?? '',
    'data-target-show-empty-values' => isset($attributes['showEmptyValues']) && $attributes['showEmptyValues'] ? 'true' : 'false',
]);

$label = $attributes['label'] ?? '';
$facet_attribute = $attributes['facetAttribute'] ?? '';
?>
<div <?php echo $block_props; ?>>
    <div class="meiliscout-facet" data-facet-id="facet-<?php echo esc_attr($facet_attribute); ?>">
        <?php if ($label) : ?>
            <label class="facet-label"><?php echo esc_html($label); ?></label>
        <?php endif; ?>
        <div class="facet-content"></div>
    </div>
</div>
