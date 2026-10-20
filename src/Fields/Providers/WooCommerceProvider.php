<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Fields\Providers;

use Pollora\MeiliScout\Fields\FieldProvider;
use Pollora\MeiliScout\Fields\MetaField;
use Pollora\MeiliScout\Services\MetaKeyCatalog;

/**
 * The product fields WooCommerce stores in the postmeta, by name. A fixed
 * list of the keys stores and themes search, filter and sort on.
 */
final class WooCommerceProvider implements FieldProvider
{
    public const SOURCE = 'woocommerce';

    public function available(): bool
    {
        return class_exists('WooCommerce');
    }

    /**
     * @return list<MetaField>
     */
    public function fields(): array
    {
        $number = MetaKeyCatalog::TYPE_NUMBER;
        $text = MetaKeyCatalog::TYPE_TEXT;
        $flag = MetaKeyCatalog::TYPE_BOOLEAN;

        $fields = [
            ['_price', __('Price', 'meiliscout'), $number],
            ['_regular_price', __('Regular price', 'meiliscout'), $number],
            ['_sale_price', __('Sale price', 'meiliscout'), $number],
            ['_sku', __('SKU', 'meiliscout'), $text],
            ['_stock', __('Stock quantity', 'meiliscout'), $number],
            ['_stock_status', __('Stock status', 'meiliscout'), $text],
            ['_manage_stock', __('Manage stock', 'meiliscout'), $flag],
            ['total_sales', __('Units sold', 'meiliscout'), $number],
            ['_wc_average_rating', __('Average rating', 'meiliscout'), $number],
            ['_wc_review_count', __('Review count', 'meiliscout'), $number],
            ['_virtual', __('Virtual', 'meiliscout'), $flag],
            ['_downloadable', __('Downloadable', 'meiliscout'), $flag],
            ['_weight', __('Weight', 'meiliscout'), $number],
            ['_length', __('Length', 'meiliscout'), $number],
            ['_width', __('Width', 'meiliscout'), $number],
            ['_height', __('Height', 'meiliscout'), $number],
        ];

        return array_map(
            static fn (array $field) => new MetaField(
                key: $field[0],
                label: 'WooCommerce › '.$field[1],
                group: 'WooCommerce',
                source: self::SOURCE,
                type: $field[2],
                postTypes: ['product'],
            ),
            $fields
        );
    }
}
