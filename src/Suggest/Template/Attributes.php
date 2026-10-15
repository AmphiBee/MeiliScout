<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Suggest\Template;

/**
 * HTML a template prints as it is: attributes or a small element, written by
 * Hit. Printed with `<?= ?>`, with `{!! !!}` in Blade (and `{{ }}` where
 * Laravel's contracts are there: HtmlableAttributes); in Twig, Hit hands out
 * Twig's own Markup instead.
 */
class Attributes implements \Stringable
{
    public function __construct(private readonly string $html) {}

    public function __toString(): string
    {
        return $this->html;
    }

    /**
     * Blade's Htmlable contract: printed as is by {{ }}.
     */
    public function toHtml(): string
    {
        return $this->html;
    }
}
