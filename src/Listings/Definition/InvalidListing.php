<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Definition;

/**
 * A listing definition that cannot be served, with every reason why.
 */
final class InvalidListing extends \InvalidArgumentException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly string $listing, public readonly array $errors)
    {
        parent::__construct(sprintf('MeiliScout listing "%s": %s', $listing, implode(' ', $errors)));
    }
}
