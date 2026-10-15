<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Suggest\Definition;

/**
 * A suggest definition that cannot be served, with every reason why.
 */
final class InvalidSuggest extends \InvalidArgumentException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly string $suggest, public readonly array $errors)
    {
        parent::__construct(sprintf('MeiliScout suggest "%s": %s', $suggest, implode(' ', $errors)));
    }
}
