<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Query\Builders;

use Pollora\MeiliScout\Contracts\QueryInterface;

interface QueryBuilderInterface
{
    public function build(QueryInterface $query, array &$searchParams): void;
} 