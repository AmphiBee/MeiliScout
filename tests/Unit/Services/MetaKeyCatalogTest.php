<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Tests\Unit\Services {

    use Pollora\MeiliScout\Services\MetaKeyCatalog;

    test('a key type is guessed from its values', function (array $values, string $type) {
        expect(MetaKeyCatalog::detect($values))->toBe($type);
    })->with([
        'prices' => [['12', '25.50', '1200'], MetaKeyCatalog::TYPE_NUMBER],
        'dates' => [['2026-10-07', '2025-01-31 14:00'], MetaKeyCatalog::TYPE_DATE],
        'ACF dates' => [['20261007', '20250131'], MetaKeyCatalog::TYPE_DATE],
        'numbers shaped like dates are numbers' => [['12345678', '20261007'], MetaKeyCatalog::TYPE_NUMBER],
        'flags' => [['yes', 'no'], MetaKeyCatalog::TYPE_BOOLEAN],
        'zeros and ones are numbers' => [['0', '1'], MetaKeyCatalog::TYPE_NUMBER],
        'serialized arrays' => [[serialize([1, 2]), serialize(['a'])], MetaKeyCatalog::TYPE_LIST],
        'mixed' => [['Lyon', '12'], MetaKeyCatalog::TYPE_TEXT],
        'nothing' => [[], MetaKeyCatalog::TYPE_EMPTY],
    ]);
}
