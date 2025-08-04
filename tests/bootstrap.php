<?php

namespace {
    if (! function_exists('get_option')) {
        function get_option($option, $default = false)
        {
            return match ($option) {
                'posts_per_page' => 10,
                default => $default
            };
        }
    }
}
