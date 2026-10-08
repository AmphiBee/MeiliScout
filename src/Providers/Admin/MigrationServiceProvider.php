<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Providers\Admin;

use Pollora\MeiliScout\Foundation\ServiceProvider;
use Pollora\MeiliScout\Services\ClientFactory;
use Pollora\MeiliScout\Services\Indexer;
use Pollora\MeiliScout\Services\IndexNames;

use function add_action;
use function add_query_arg;
use function admin_url;
use function current_user_can;
use function esc_html;
use function esc_html__;
use function esc_url;
use function wp_die;
use function wp_get_referer;
use function wp_nonce_field;
use function wp_safe_redirect;
use function wp_verify_nonce;

/**
 * Tells administrators when the indexes need migrating, and lets them clean up after.
 *
 * Until a full indexation builds the indexes in the current format, searches
 * keep reading the previous ones: the notice stays until it is run. Once
 * searches moved, the previous indexes can be deleted from the notice.
 */
class MigrationServiceProvider extends ServiceProvider
{
    private const DELETE_ACTION = 'meiliscout_delete_legacy_indexes';

    /**
     * Registers the notices and the deletion handler.
     */
    public function register(): void
    {
        if (! ClientFactory::isConfigured()) {
            return;
        }

        add_action('admin_notices', [$this, 'renderNotices']);
        add_action('admin_post_'.self::DELETE_ACTION, [$this, 'deleteLegacyIndexes']);
    }

    /**
     * Shows the migration notice, or the clean-up one.
     */
    public function renderNotices(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        // The MeiliScout page shows its own
        if (($_GET['page'] ?? null) === AdminServiceProvider::PAGE) {
            return;
        }

        if (isset($_GET['meiliscout_legacy_deleted'])) {
            $this->notice('success', esc_html__('The previous Meilisearch indexes were deleted.', 'meiliscout'));
        }

        if (IndexNames::migrationPending()) {
            $this->renderMigrationNotice();

            return;
        }

        $legacy = IndexNames::legacyIndexes();

        if ($legacy !== []) {
            $this->renderCleanUpNotice($legacy);
        }
    }

    /**
     * Deletes the previous indexes. Hooked on admin-post.php.
     */
    public function deleteLegacyIndexes(): void
    {
        if (! current_user_can('manage_options')
            || ! isset($_POST['_wpnonce'])
            || ! wp_verify_nonce($_POST['_wpnonce'], self::DELETE_ACTION)) {
            wp_die(esc_html__('You are not allowed to delete these indexes.', 'meiliscout'), 403);
        }

        try {
            (new Indexer)->deleteLegacyIndexes();
            $redirect = add_query_arg('meiliscout_legacy_deleted', '1', wp_get_referer() ?: admin_url());
        } catch (\Exception $e) {
            error_log('MeiliScout: failed to delete the legacy indexes: '.$e->getMessage());
            $redirect = wp_get_referer() ?: admin_url();
        }

        wp_safe_redirect($redirect);
        exit;
    }

    private function renderMigrationNotice(): void
    {
        $previous = array_map([IndexNames::class, 'active'], IndexNames::BASES);
        $next = array_map([IndexNames::class, 'name'], IndexNames::BASES);

        $this->notice('warning', sprintf(
            '<strong>%s</strong></p><p>%s</p><p>%s</p><p><a class="button button-primary" href="%s">%s</a>',
            esc_html__('MeiliScout: a full indexation is needed.', 'meiliscout'),
            sprintf(
                /* translators: 1: indexes searches read now, 2: indexes the indexation builds */
                esc_html__('Searches keep using %1$s, in the previous format, until a full indexation builds %2$s. Content saved meanwhile is kept up to date in both.', 'meiliscout'),
                '<code>'.esc_html(implode(', ', $previous)).'</code>',
                '<code>'.esc_html(implode(', ', $next)).'</code>'
            ),
            sprintf(
                /* translators: %s: indexes the indexation builds */
                esc_html__('If your search API key is restricted to some indexes, give it access to %s first.', 'meiliscout'),
                '<code>'.esc_html(implode(', ', $next)).'</code>'
            ),
            esc_url(admin_url('admin.php?page='.AdminServiceProvider::PAGE.'#/indexation')),
            esc_html__('Go to the indexation', 'meiliscout')
        ));
    }

    /**
     * @param  list<string>  $legacy
     */
    private function renderCleanUpNotice(array $legacy): void
    {
        ob_start();
        wp_nonce_field(self::DELETE_ACTION);
        $nonce = (string) ob_get_clean();

        $this->notice('info', sprintf(
            '%s</p><form method="post" action="%s"><input type="hidden" name="action" value="%s">%s<p><button type="submit" class="button">%s</button></p></form><p>',
            sprintf(
                /* translators: %s: indexes no longer used */
                esc_html__('MeiliScout: searches no longer use these indexes, you can delete them: %s', 'meiliscout'),
                '<code>'.esc_html(implode(', ', $legacy)).'</code>'
            ),
            esc_url(admin_url('admin-post.php')),
            esc_html(self::DELETE_ACTION),
            $nonce,
            esc_html__('Delete the previous indexes', 'meiliscout')
        ));
    }

    /**
     * Prints an admin notice; $html is already escaped.
     */
    private function notice(string $type, string $html): void
    {
        printf('<div class="notice notice-%s"><p>%s</p></div>', esc_html($type), $html);
    }
}
