<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Render;

use Pollora\MeiliScout\Listings\Definition\ListingDefinition;

/**
 * The cards of the client transport (C), made from the documents Meilisearch
 * returns: the same on the server (first render) and in the client
 * (resources/listings/hits.js), tests/fixtures/listings/card-cases.json
 * keeping both equal. Only public fields (PublicFields), only text.
 *
 *     { id, url, title, excerpt, date, dateLabel, type, terms: { taxonomy: [{ name, slug }] }, metas: { key: string|string[] } }
 */
final class Hits
{
    /**
     * What a results search asks for: the document's fields a card reads, the
     * listing's public meta keys. The excerpt comes cropped (attributesToCrop).
     *
     * @return list<string>
     */
    public static function fields(ListingDefinition $definition): array
    {
        return [
            'ID', 'url', 'post_title', 'post_excerpt', 'post_date', 'post_type', 'taxonomies',
            ...array_map(fn (string $key) => "metas.{$key}", $definition->publicMetas),
        ];
    }

    /**
     * Words of an excerpt made from the content, as WordPress counts them (excerpt_length).
     */
    public static function excerptLength(): int
    {
        return max(1, (int) apply_filters('excerpt_length', 55));
    }

    /**
     * @param  array<string, mixed>  $document  A hit, with _formatted.content_text cropped
     * @param  list<string>  $metas  The listing's public meta keys
     * @param  array{format: string, months: list<string>, monthsShort: list<string>, weekdays: list<string>, weekdaysShort: list<string>, meridiem: array<string, string>}  $date
     * @return array<string, mixed>
     */
    public static function fromDocument(array $document, array $metas, array $date): array
    {
        $excerpt = trim(self::text($document['post_excerpt'] ?? ''));

        if ($excerpt === '') {
            $excerpt = trim(self::text($document['_formatted']['content_text'] ?? ''));
        }

        $terms = [];
        foreach ((array) ($document['taxonomies'] ?? []) as $taxonomy => $list) {
            $terms[(string) $taxonomy] = [];
            foreach ((array) $list as $term) {
                $terms[(string) $taxonomy][] = ['name' => self::text($term['name'] ?? ''), 'slug' => (string) ($term['slug'] ?? '')];
            }
        }

        $values = [];
        foreach ($metas as $key) {
            $value = $document['metas'][$key] ?? '';
            $values[$key] = is_array($value) ? array_map([self::class, 'scalar'], array_values($value)) : self::scalar($value);
        }

        $postDate = (string) ($document['post_date'] ?? '');

        return [
            'id' => (int) ($document['ID'] ?? 0),
            'url' => (string) ($document['url'] ?? ''),
            'title' => self::text($document['post_title'] ?? ''),
            'excerpt' => $excerpt,
            'date' => $postDate,
            'dateLabel' => $postDate === '' ? '' : self::formatDate($date, $postDate),
            'type' => (string) ($document['post_type'] ?? ''),
            'terms' => (object) $terms,
            'metas' => (object) $values,
        ];
    }

    /**
     * The names date_i18n() writes in the site's language, and its date format.
     *
     * @return array{format: string, months: list<string>, monthsShort: list<string>, weekdays: list<string>, weekdaysShort: list<string>, meridiem: array<string, string>}
     */
    public static function dateNames(): array
    {
        global $wp_locale;

        return [
            'format' => (string) get_option('date_format'),
            'months' => array_values(array_map('strval', (array) $wp_locale->month)),
            'monthsShort' => array_values(array_map('strval', (array) $wp_locale->month_abbrev)),
            'weekdays' => array_values(array_map('strval', (array) $wp_locale->weekday)),
            'weekdaysShort' => array_values(array_map('strval', (array) $wp_locale->weekday_abbrev)),
            'meridiem' => array_map('strval', (array) $wp_locale->meridiem),
        ];
    }

    /**
     * A post's date (Y-m-d H:i:s, the site's time) in a PHP date format, as
     * mysql2date() writes it with the site's names. The client has the same
     * port: no time zone, no locale of its own.
     *
     * @param  array{format: string, months: list<string>, monthsShort: list<string>, weekdays: list<string>, weekdaysShort: list<string>, meridiem: array<string, string>}  $names
     */
    public static function formatDate(array $names, string $date): string
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}):(\d{2}))?/', $date, $m)) {
            return '';
        }

        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        [$hour, $minute, $second] = [(int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0)];
        $weekday = (int) gmdate('w', gmmktime(0, 0, 0, $month, $day, $year));
        $hour12 = $hour % 12 === 0 ? 12 : $hour % 12;
        $meridiem = $hour < 12 ? 'am' : 'pm';
        $format = $names['format'];
        $out = '';

        for ($i = 0, $length = strlen($format); $i < $length; $i++) {
            $char = $format[$i];

            if ($char === '\\') {
                $out .= $format[++$i] ?? '';

                continue;
            }

            $out .= match ($char) {
                'd' => sprintf('%02d', $day),
                'j' => (string) $day,
                'D' => $names['weekdaysShort'][$weekday] ?? '',
                'l' => $names['weekdays'][$weekday] ?? '',
                'N' => (string) ($weekday === 0 ? 7 : $weekday),
                'w' => (string) $weekday,
                'S' => self::suffix($day),
                'F' => $names['months'][$month - 1] ?? '',
                'M' => $names['monthsShort'][$month - 1] ?? '',
                'm' => sprintf('%02d', $month),
                'n' => (string) $month,
                'Y' => (string) $year,
                'y' => sprintf('%02d', $year % 100),
                'a' => $names['meridiem'][$meridiem] ?? $meridiem,
                'A' => $names['meridiem'][strtoupper($meridiem)] ?? strtoupper($meridiem),
                'g' => (string) $hour12,
                'h' => sprintf('%02d', $hour12),
                'G' => (string) $hour,
                'H' => sprintf('%02d', $hour),
                'i' => sprintf('%02d', $minute),
                's' => sprintf('%02d', $second),
                default => $char,
            };
        }

        return $out;
    }

    private static function suffix(int $day): string
    {
        if ($day % 100 >= 11 && $day % 100 <= 13) {
            return 'th';
        }

        return ['th', 'st', 'nd', 'rd'][$day % 10] ?? 'th';
    }

    private static function text(mixed $value): string
    {
        return html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? '1' : '',
            is_float($value) => (string) (floor($value) === $value && abs($value) < 1e15 ? (int) $value : $value),
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
