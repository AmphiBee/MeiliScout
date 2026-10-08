<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Integrations\QueryMonitor;

use Pollora\MeiliScout\Query\QueryLog;

/**
 * The MeiliScout panel of Query Monitor. Loaded only when Query Monitor runs.
 */
final class Output extends \QM_Output_Html
{
    public function __construct(\QM_Collector $collector)
    {
        parent::__construct($collector);
        add_filter('qm/output/menus', [$this, 'admin_menu'], 101);
    }

    public function name()
    {
        return 'MeiliScout';
    }

    /**
     * @param  array<string, mixed[]>  $menu
     * @return array<string, mixed[]>
     */
    public function admin_menu(array $menu)
    {
        $queries = $this->collector->get_data()->queries;
        $fallbacks = count(array_filter($queries, static fn (array $query) => ! $query['served']));

        $title = $fallbacks > 0
            /* translators: 1: queries, 2: queries MySQL served */
            ? sprintf(__('MeiliScout (%1$d, %2$d on MySQL)', 'meiliscout'), count($queries), $fallbacks)
            /* translators: %d: queries */
            : sprintf(__('MeiliScout (%d)', 'meiliscout'), count($queries));

        $menu[$this->collector->id()] = $this->menu(['title' => $title]);

        return $menu;
    }

    public function output(): void
    {
        $queries = $this->collector->get_data()->queries;

        if ($queries === []) {
            $this->before_non_tabular_output();
            echo '<section><p>'.esc_html__('No query asked for Meilisearch on this page.', 'meiliscout').'</p></section>';
            $this->after_non_tabular_output();

            return;
        }

        $this->before_tabular_output();

        echo '<thead><tr>';
        foreach ([__('Query', 'meiliscout'), __('Served by', 'meiliscout'), __('Found', 'meiliscout'), __('Time', 'meiliscout'), __('Search sent to Meilisearch', 'meiliscout')] as $heading) {
            echo '<th scope="col">'.esc_html($heading).'</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($queries as $number => $query) {
            echo '<tr>';
            echo '<td class="qm-num">'.esc_html((string) ($number + 1)).($query['main'] ? '<br><span class="qm-info">'.esc_html__('main query', 'meiliscout').'</span>' : '').'</td>';
            echo '<td>'.($query['served']
                ? esc_html__('Meilisearch', 'meiliscout')
                : '<span class="qm-warn">MySQL</span><br><code>'.esc_html((string) $query['reason']).'</code>').'</td>';
            echo '<td class="qm-num">'.esc_html((string) $query['found_posts']).'</td>';
            echo '<td class="qm-num">'.($query['time'] !== null ? esc_html(number_format_i18n((float) $query['time'], 1).' ms') : '').'</td>';
            echo '<td>';

            if (is_array($query['params'])) {
                $curl = QueryLog::curl((string) $query['index'], $query['params']);

                echo '<code>'.esc_html((string) $query['index']).'</code>';
                echo '<pre class="qm-pre-wrap">'.esc_html((string) wp_json_encode($query['params'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)).'</pre>';
                echo '<textarea readonly rows="2" style="width:100%" aria-label="'.esc_attr__('As a curl command', 'meiliscout').'">'.esc_textarea($curl).'</textarea>';
                echo '<button type="button" class="qm-button" onclick="navigator.clipboard.writeText(this.previousElementSibling.value)">'.esc_html__('Copy as cURL', 'meiliscout').'</button>';
            }

            echo '</td></tr>';
        }

        echo '</tbody>';
        $this->after_tabular_output();
    }
}
