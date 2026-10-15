<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Suggest;

use Pollora\MeiliScout\Listings\Listings;
use Pollora\MeiliScout\Suggest\Definition\InvalidSuggest;
use Pollora\MeiliScout\Suggest\Definition\SuggestRegistry;
use Pollora\MeiliScout\Suggest\Render\Field;
use Pollora\MeiliScout\Suggest\Transport\SuggestTokens;

/**
 * Printing a suggest field: its markup, and the script module and look it needs.
 */
final class Suggest
{
    public const MODULE = '@meiliscout/suggest';

    public const STYLE = 'meiliscout-suggest';

    public const NAMESPACE = 'meiliscout/suggest';

    public static function boot(): void
    {
        add_action('init', [self::class, 'register']);
    }

    /**
     * Registers the client (a script module) and the default look.
     */
    public static function register(): void
    {
        $root = dirname(__DIR__, 2);

        $style = is_file($root.'/build/suggest/style.asset.php') ? require $root.'/build/suggest/style.asset.php' : ['version' => false];
        wp_register_style(self::STYLE, plugins_url('build/suggest/style.css', dirname(__DIR__)), [], $style['version'] ?? false);
        wp_add_inline_style(self::STYLE, Listings::themeColors());

        $asset = is_file($root.'/build/suggest/view.asset.php') ? require $root.'/build/suggest/view.asset.php' : ['dependencies' => [], 'version' => false];
        wp_register_script_module(self::MODULE, plugins_url('build/suggest/view.js', dirname(__DIR__)), $asset['dependencies'] ?? [], $asset['version'] ?? false);
    }

    /**
     * A field's HTML. An error message for an administrator when it cannot be served, nothing for a visitor.
     *
     * @param  array<string, mixed>  $args  placeholder, label, value, class
     */
    public static function render(string $id, array $args = []): string
    {
        try {
            $definition = SuggestRegistry::get($id);
        } catch (InvalidSuggest|\OutOfBoundsException $e) {
            return self::error($e->getMessage());
        }

        wp_enqueue_script_module(self::MODULE);

        /**
         * Filters whether the default look is loaded.
         *
         * @param  bool  $load  Default true.
         */
        if (apply_filters('meiliscout/suggest/load_styles', true)) {
            wp_enqueue_style(self::STYLE);
        }

        wp_interactivity_state(self::NAMESPACE, ['i18n' => self::i18n()]);

        return wp_interactivity_process_directives(Field::render($definition, $definition->transport === 'rest' ? null : SuggestTokens::forDefinition($definition), $args));
    }

    /**
     * The client's texts, in this language: plural forms for 0, 1 and more.
     *
     * @return array<string, mixed>
     */
    public static function i18n(): array
    {
        return [
            'results' => [
                'zero' => _n('%d result', '%d results', 0, 'meiliscout'),
                'one' => _n('%d result', '%d results', 1, 'meiliscout'),
                'many' => _n('%d result', '%d results', 2, 'meiliscout'),
            ],
            /* translators: %s: what the visitor typed */
            'empty' => __('No suggestion for “%s”.', 'meiliscout'),
            'error' => __('Suggestions are not available right now. Press Enter to search.', 'meiliscout'),
        ];
    }

    private static function error(string $message): string
    {
        return current_user_can('manage_options') ? '<p class="meiliscout-suggest__error">'.esc_html($message).'</p>' : '';
    }
}
