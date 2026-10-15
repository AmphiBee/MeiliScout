<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Suggest\Template;

/**
 * Attributes on a site that has Laravel's contracts (Blade, Pollora, Acorn):
 * Blade's `{{ }}` prints an Htmlable as it is, never escaped.
 */
final class HtmlableAttributes extends Attributes implements \Illuminate\Contracts\Support\Htmlable {}
