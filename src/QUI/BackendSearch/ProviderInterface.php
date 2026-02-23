<?php

/**
 * This file contains QUI\BackendSearch\ProviderInterface
 */

namespace QUI\BackendSearch;

/**
 * Interface ProviderInterface
 * Interface for a DesktopSearch Provider
 * https://dev.quiqqer.com/quiqqer/backendsearch/wikis/DesktopSearch/
 *
 * @package QUI\Workspace\Search
 */
interface ProviderInterface
{
    /**
     * Build the cache
     *
     * @return void
     */
    public function buildCache(): void;

    /**
     * Execute a search
     *
     * @param string $search
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public function search(string $search, array $params = []): array;

    /**
     * Return a search entry
     *
     * @param int|string $id
     * @return array<string,mixed>|null
     */
    public function getEntry(string | int $id): mixed;

    /**
     * Get all available search groups of this provider.
     * Search results can be filtered by these search groups.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getFilterGroups(): array;
}
