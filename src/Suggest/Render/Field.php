<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Suggest\Render;

use Pollora\MeiliScout\Listings\Language\Languages;
use Pollora\MeiliScout\Suggest\Definition\SuggestDefinition;

/**
 * A suggest field's markup: a real search form (it works without JavaScript,
 * and sends to the results page), a combobox the client wires, and a panel
 * of suggestions the client fills.
 *
 * ARIA 1.2 combobox with a list box: the input keeps the focus, the active
 * suggestion is named by aria-activedescendant, the count is announced in a
 * polite live region. The client's state lives in the field's context
 * (`data-wp-context`): its token, its searches (Plan), what it shows.
 */
final class Field
{
    /**
     * Suggestions a group keeps in the phone's sheet: little room, a keyboard.
     */
    public const MOBILE_LIMIT = 3;

    /**
     * Fields printed on this page, to give each its own ids.
     */
    private static int $printed = 0;

    /**
     * @param  array<string, mixed>  $token  Its tenant token and the public host: {value, exp, host}
     * @param  array<string, mixed>  $args  placeholder, label, value, class
     */
    public static function render(SuggestDefinition $definition, ?array $token, array $args = []): string
    {
        $dom = sprintf('meiliscout-suggest-%s-%d', $definition->id, ++self::$printed);
        $plan = Plan::forDefinition($definition);
        $action = $definition->resultsUrl ?? home_url('/');
        $value = array_key_exists('value', $args) ? (string) $args['value'] : (string) get_search_query(false);
        $label = (string) ($args['label'] ?? __('Search', 'meiliscout'));
        $placeholder = (string) ($args['placeholder'] ?? __('Search…', 'meiliscout'));
        $class = trim('meiliscout-suggest '.(string) ($args['class'] ?? ''));

        $context = [
            'id' => $dom,
            'field' => $definition->id,
            // Without a token the form stays a plain search
            'ready' => $token !== null,
            'host' => $token['host'] ?? '',
            'token' => $token === null ? null : ['value' => $token['value'], 'exp' => $token['exp']],
            'endpoint' => add_query_arg(array_filter(['lang' => Languages::current()]), rest_url('meiliscout/v1/suggest/'.$definition->id.'/token')),
            'plan' => $plan,
            'labels' => Plan::labels($definition),
            'minChars' => $definition->minChars,
            'debounce' => $definition->debounce,
            'action' => $action,
            'param' => $definition->searchParam,
            'query' => $value,
            'open' => false,
            'busy' => false,
            'activeId' => '',
            'options' => [],
            'groups' => array_map(static fn (array $source): array => ['key' => $source['key'], 'hits' => [], 'hasHits' => false], $plan['sources']),
            'total' => 0,
            'allUrl' => $action,
            'announce' => '',
            'focused' => false,
            // The phone's full-screen sheet, and the recent searches (kept in the browser)
            'sheet' => false,
            'recentEnabled' => $definition->recent,
            'recent' => [],
            'mobileLimit' => self::MOBILE_LIMIT,
            'empty' => false,
            'emptyText' => '',
            'canSeeAll' => false,
        ];

        $groups = '';
        foreach (array_values($definition->sources) as $i => $source) {
            $groups .= sprintf(
                '<div class="meiliscout-suggest__group" role="group" aria-labelledby="%1$s-g%2$d" data-wp-bind--hidden="!context.groups.%2$d.hasHits">'
                .'<div class="meiliscout-suggest__heading" id="%1$s-g%2$d" role="presentation">%3$s</div>'
                .'<template data-wp-each--hit="context.groups.%2$d.hits" data-wp-each-key="context.hit.optionId">%4$s</template></div>',
                esc_attr($dom),
                $i,
                esc_html($source->label),
                Templates::for($source)['markup']
            );
        }

        $recent = $definition->recent
            ? sprintf(
                '<div class="meiliscout-suggest__group meiliscout-suggest__group--recent" role="group" aria-labelledby="%1$s-recent" data-wp-bind--hidden="!context.recent.length" hidden>'
                .'<div class="meiliscout-suggest__heading" id="%1$s-recent" role="presentation">%2$s</div>'
                .'<template data-wp-each--hit="context.recent" data-wp-each-key="context.hit.optionId">'
                .'<a class="meiliscout-suggest__option meiliscout-suggest__option--recent" role="option" tabindex="-1" data-wp-bind--id="context.hit.optionId" data-wp-bind--href="context.hit.url" data-wp-bind--aria-selected="state.selected" data-wp-class--is-active="state.active">'
                .'<svg class="meiliscout-suggest__recent-icon" aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M3 12a9 9 0 1 0 3-6.7M3 4v5h5M12 7v5l3 2"/></svg>'
                .'<span class="meiliscout-suggest__title" data-wp-text="context.hit.title"></span></a></template></div>',
                esc_attr($dom),
                esc_html__('Recent searches', 'meiliscout')
            )
            : '';
        $forget = $definition->recent
            ? sprintf('<button class="meiliscout-suggest__forget" type="button" data-wp-bind--hidden="!context.recent.length" hidden data-wp-on--click="actions.forget">%s</button>', esc_html__('Clear recent searches', 'meiliscout'))
            : '';

        $html = sprintf(
            '<div class="%1$s" id="%2$s" data-wp-interactive="meiliscout/suggest" %3$s data-wp-init="callbacks.init" data-wp-on--focusout="actions.focusOut" data-wp-on-document--click="actions.clickOutside">'
            .'<div class="meiliscout-suggest__layer" data-wp-class--is-sheet="context.sheet">'
            .'<form class="meiliscout-suggest__form" role="search" method="get" action="%4$s" data-wp-on--submit="actions.submit">'
            .'<label class="meiliscout-suggest__label screen-reader-text" for="%2$s-input">%5$s</label>'
            .'<input class="meiliscout-suggest__input" id="%2$s-input" type="search" name="%6$s" value="%7$s" placeholder="%8$s"'
            .' role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false" aria-controls="%2$s-list"'
            .' autocomplete="off" autocorrect="off" autocapitalize="none" spellcheck="false" inputmode="search" enterkeyhint="search"'
            .' data-wp-bind--value="context.query" data-wp-bind--aria-expanded="context.open" data-wp-bind--aria-activedescendant="context.activeId"'
            .' data-wp-on--input="actions.input" data-wp-on--keydown="actions.keydown" data-wp-on--focus="actions.focus">'
            .'<button class="meiliscout-suggest__submit" type="submit"><span class="screen-reader-text">%9$s</span>'
            .'<svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24"><path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" d="M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zm9 16-4.2-4.2"/></svg></button>'
            .'<button class="meiliscout-suggest__close" type="button" hidden data-wp-bind--hidden="!context.sheet" data-wp-on--click="actions.closeSheet">%13$s</button>'
            .'</form>'
            .'<div class="meiliscout-suggest__panel" id="%2$s-panel" hidden data-wp-bind--hidden="!context.open" data-wp-on--mousedown="actions.keepFocus" data-wp-on--click="actions.panelClick" data-wp-on--touchmove="actions.touchScroll">'
            .'<div class="meiliscout-suggest__list" id="%2$s-list" role="listbox" aria-label="%10$s">%14$s%11$s</div>'
            .'%15$s'
            .'<p class="meiliscout-suggest__empty" data-wp-bind--hidden="!context.empty" hidden data-wp-text="context.emptyText"></p>'
            .'<a class="meiliscout-suggest__all" data-wp-bind--hidden="!context.canSeeAll" data-wp-bind--href="context.allUrl" hidden>%12$s</a>'
            .'</div>'
            .'<p class="screen-reader-text" role="status" aria-live="polite" data-wp-text="context.announce"></p>'
            .'</div>'
            .'</div>',
            esc_attr($class),
            esc_attr($dom),
            wp_interactivity_data_wp_context($context),
            esc_url($action),
            esc_html($label),
            esc_attr($definition->searchParam),
            esc_attr($value),
            esc_attr($placeholder),
            esc_html__('Search', 'meiliscout'),
            esc_attr__('Suggestions', 'meiliscout'),
            $groups,
            esc_html__('See all results', 'meiliscout'),
            esc_html__('Close', 'meiliscout'),
            $recent,
            $forget
        );

        return $html;
    }
}
