<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Listings\Query;

use Pollora\MeiliScout\Contracts\QueryInterface;

/**
 * WP_Query arguments the builders read without a WP_Query: listings build
 * their filters with the same builders, from arguments, without querying.
 */
final class ArrayQuery implements QueryInterface
{
    /**
     * @param  array<string, mixed>  $vars
     */
    public function __construct(private array $vars) {}

    public function get(string $key, $default = null)
    {
        return $this->vars[$key] ?? $default;
    }

    public function set($key, $value)
    {
        $this->vars[$key] = $value;
    }
}
