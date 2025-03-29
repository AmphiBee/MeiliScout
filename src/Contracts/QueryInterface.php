<?php

namespace Pollora\MeiliScout\Contracts;

interface QueryInterface
{
    public function get($key, $default = null);
} 