<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Seo;

use Pollora\MeiliScout\Listings\Seo\Sitemap\SitemapEntries;

/**
 * The SEO rules' table, {prefix}meiliscout_seo_rules: one rule per listing,
 * language and key (RuleKey), its fields in JSON.
 *
 * Created, and changed, by dbDelta() when its version is not the one recorded
 * (install()): on the admin's screens, by the REST endpoints and the command.
 * The front reads it only once it exists.
 */
final class SeoRules
{
    public const VERSION = 1;

    private const VERSION_OPTION = 'meiliscout_seo_rules_version';

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix.'meiliscout_seo_rules';
    }

    public static function installed(): bool
    {
        return (int) get_option(self::VERSION_OPTION, 0) === self::VERSION;
    }

    /**
     * Creates or updates the table, once per version.
     */
    public static function install(): void
    {
        if (self::installed()) {
            return;
        }

        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        // dbDelta's format: two spaces after PRIMARY KEY, one field per line
        dbDelta("CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  listing varchar(64) NOT NULL,
  locale varchar(20) NOT NULL DEFAULT '',
  rule_key varchar(160) NOT NULL DEFAULT '',
  specificity smallint(5) unsigned NOT NULL DEFAULT 0,
  fields longtext NOT NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY listing_locale_key (listing,locale,rule_key)
) {$charset};");

        update_option(self::VERSION_OPTION, self::VERSION, true);
    }

    /**
     * The rule of a view: the most specific of its keys, in its language
     * before every language's.
     *
     * @param  list<string>  $keys  RuleKey::candidates()
     */
    public static function find(string $listing, string $locale, array $keys): ?SeoRule
    {
        if ($keys === [] || ! self::installed()) {
            return null;
        }

        global $wpdb;
        $table = self::table();
        $in = implode(',', array_fill(0, count($keys), '%s'));

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table's name, and placeholders
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE listing = %s AND locale IN (%s, '') AND rule_key IN ({$in}) ORDER BY specificity DESC, locale = '' ASC LIMIT 1",
            $listing,
            $locale,
            ...$keys
        ), ARRAY_A);

        return is_array($row) ? self::fromRow($row) : null;
    }

    /**
     * @return list<SeoRule> By listing, language and specificity
     */
    public static function all(?string $listing = null): array
    {
        if (! self::installed()) {
            return [];
        }

        global $wpdb;
        $table = self::table();
        $sql = "SELECT * FROM {$table}";

        if ($listing !== null) {
            $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE listing = %s", $listing);
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above
        $rows = (array) $wpdb->get_results($sql.' ORDER BY listing, locale, specificity, rule_key', ARRAY_A);

        return array_map(self::fromRow(...), $rows);
    }

    public static function get(int $id): ?SeoRule
    {
        if (! self::installed()) {
            return null;
        }

        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table's name
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);

        return is_array($row) ? self::fromRow($row) : null;
    }

    /**
     * Saves a rule: its id updates that row, else the rule of the same
     * listing, language and key is replaced, else one is added.
     *
     * @return SeoRule As saved, with its id
     *
     * @throws \RuntimeException When the database refuses it
     */
    public static function save(SeoRule $rule): SeoRule
    {
        self::install();

        global $wpdb;
        $now = current_time('mysql', true);
        $data = [
            'listing' => $rule->listing,
            'locale' => $rule->locale,
            'rule_key' => $rule->key,
            'specificity' => RuleKey::specificity($rule->key),
            'fields' => (string) wp_json_encode($rule->fields(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ];

        $other = self::idOf($rule->listing, $rule->locale, $rule->key);
        $id = $rule->id ?? $other;

        if ($id !== null && $other !== null && $other !== $id) {
            throw new \RuntimeException(__('Another rule of this listing already has this language and these facets.', 'meiliscout'));
        }

        $saved = $id !== null
            ? $wpdb->update(self::table(), $data, ['id' => $id])
            : $wpdb->insert(self::table(), $data + ['created_at' => $now]);

        if ($saved === false) {
            throw new \RuntimeException($wpdb->last_error ?: __('The rule could not be saved.', 'meiliscout'));
        }

        // A rule of two facets names views of the sitemap
        SitemapEntries::forget();

        return self::get($id ?? (int) $wpdb->insert_id) ?? throw new \RuntimeException(__('The rule could not be saved.', 'meiliscout'));
    }

    public static function delete(int $id): bool
    {
        if (! self::installed()) {
            return false;
        }

        global $wpdb;
        SitemapEntries::forget();

        return (bool) $wpdb->delete(self::table(), ['id' => $id], ['%d']);
    }

    private static function idOf(string $listing, string $locale, string $key): ?int
    {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table's name
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE listing = %s AND locale = %s AND rule_key = %s", $listing, $locale, $key));

        return $id === null ? null : (int) $id;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function fromRow(array $row): SeoRule
    {
        $fields = json_decode((string) ($row['fields'] ?? ''), true);
        $fields = is_array($fields) ? $fields : [];

        return new SeoRule(
            (string) $row['listing'],
            (string) $row['locale'],
            (string) $row['rule_key'],
            (int) $row['specificity'],
            (string) ($fields['title'] ?? ''),
            (string) ($fields['description'] ?? ''),
            (string) ($fields['h1'] ?? ''),
            (string) ($fields['intro'] ?? ''),
            SeoRule::faqFrom($fields['faq'] ?? []),
            (int) $row['id'],
            (string) $row['updated_at'],
        );
    }
}
