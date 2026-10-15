<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Suggest\Template;

use Pollora\MeiliScout\Suggest\Definition\SuggestDefinition;
use Pollora\MeiliScout\Suggest\Definition\SuggestSource;

/**
 * A source's template written as a class: given as a source's `template`
 * (meiliscout_register_suggest(..., ['sources' => ['posts' => ['template' => new MyHit]]])).
 */
interface SuggestTemplate
{
    /**
     * The markup of one suggestion: one root element, bound with $hit.
     */
    public function markup(SuggestSource $source, SuggestDefinition $definition, Hit $hit): string;
}
