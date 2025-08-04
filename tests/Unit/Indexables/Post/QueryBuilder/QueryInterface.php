<?php

namespace Pollora\MeiliScout\Tests\Unit\Indexables\Post\QueryBuilder;

interface QueryInterface
{
    public function get($key, $default = null);
}
