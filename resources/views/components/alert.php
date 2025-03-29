<?php
if (!isset($message) || empty($message)) {
    return;
}

$type = $type ?? 'success';
?>

<div class="notice notice-<?php echo esc_attr($type); ?> is-dismissible">
    <p><?php echo esc_html($message); ?></p>
</div>
