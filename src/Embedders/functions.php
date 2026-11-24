<?php

declare(strict_types=1);

use Pollora\MeiliScout\Embedders\Embedders;

if (! function_exists('meiliscout_register_embedder')) {
    /**
     * Declares an embedder for some indexes: their settings carry it whenever they are pushed.
     *
     * ```php
     * meiliscout_register_embedder('mistral', [
     *     'source' => 'rest',
     *     'url' => 'https://api.mistral.ai/v1/embeddings',
     *     'apiKey' => 'MISTRAL_API_KEY', // or fn () => config('ai.providers.mistral.key')
     *     'dimensions' => 1024,
     *     'request' => ['model' => 'mistral-embed', 'input' => ['{{text}}', '{{..}}']],
     *     'response' => ['data' => [['embedding' => '{{embedding}}'], '{{..}}']],
     *     'documentTemplate' => '{{ doc.post_title }}',
     * ], ['posts', 'manual_chunks']);
     * ```
     *
     * @param  array<string, mixed>  $config  Meilisearch's embedder settings; `apiKey` a callable, or a constant or environment variable name, never the key itself
     * @param  list<string>  $indexes  Base index names
     */
    function meiliscout_register_embedder(string $name, array $config, array $indexes = ['posts']): void
    {
        Embedders::declare($name, $config, $indexes);
    }
}
