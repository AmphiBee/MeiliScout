<?php

declare(strict_types=1);

namespace Pollora\MeiliScout\Contracts;

interface Indexable
{
    /**
     * Retourne le nom de l'index Meilisearch
     */
    public function getIndexName(): string;
    /**
     * Retourne le nom de la clé primaire de la donnée indexée par Meilisearch
     */
    public function getPrimaryKey(): string;

    /**
     * Retourne la configuration de l'index
     */
    public function getIndexSettings(): array;

    /**
     * Récupère les éléments à indexer
     */
    public function getItems(): iterable;

    /**
     * Formate un élément pour l'indexation
     */
    public function formatForIndexing(mixed $item): array;

    /**
     * Formate un élément pour la recherche
     */
    public function formatForSearch(array $hit): mixed;
}
