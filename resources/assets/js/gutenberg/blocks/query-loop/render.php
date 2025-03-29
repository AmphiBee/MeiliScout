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
?>
<div
    class="<?php echo esc_attr(implode(' ', $classes)) ?>"
    data-query-id="<?php echo esc_attr($query_id) ?>"
    data-enable-url-params="<?php echo $enable_url_params ? 'true' : 'false' ?>"
    data-query='<?php echo esc_attr(json_encode($query)) ?>'
    data-template="<?php echo esc_attr(json_encode($template, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); ?>"
>
    <?php echo $content ?>
</div>
