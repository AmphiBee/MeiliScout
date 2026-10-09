<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Render;

use Pollora\MeiliScout\Listings\Definition\ListingDefinition;

/**
 * Renders a listing's card for a post: a project's own renderer, given as a
 * listing's card (meiliscout_register_listing(..., ['card' => new MyCards])).
 */
interface CardRenderer
{
    public function render(\WP_Post $post, ListingDefinition $definition): string;
}
