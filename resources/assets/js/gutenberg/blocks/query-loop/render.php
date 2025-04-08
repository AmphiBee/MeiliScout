<?php
use function Pollora\MeiliScout\clean_recursive;

$query_id = sanitize_text_field($attributes['queryId'] ?? '');
$enable_url_params = !empty($attributes['enableUrlParams']);
$query = $attributes['query'] ?? [];
$template = clean_recursive($block->parsed_block['innerBlocks'] ?? []);

if (empty($template)) {
    return;
}

$classes = ['wp-block-query-meilisearch'];
if ($enable_url_params) {
    $classes[] = 'has-urlparams';
}

$alpine_data = [
    'queryId' => $query_id,
    'enableUrlParams' => $enable_url_params,
    'query' => $query,
    'template' => $template
];

$json = json_encode($alpine_data);
?>

<div
    class="<?php echo esc_attr(implode(' ', $classes)); ?>"
    x-meiliscout-data="QueryLoop(<?php echo esc_attr($json); ?>)"
>
    <div x-meiliscout-html="posts"></div>
</div>
