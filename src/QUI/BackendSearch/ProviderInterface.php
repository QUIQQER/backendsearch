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
     * @param array $params
     * @return array
     */
    public function search(string $search, array $params = []): array;

    /**
     * Return a search entry
     *
     * @param integer $id
     * @return ?array
     */
    public function getEntry(int $id): mixed;

    /**
     * Get all available search groups of this provider.
     * Search results can be filtered by these search groups.
     *
     * @return array
     */
    public function getFilterGroups(): array;
}
