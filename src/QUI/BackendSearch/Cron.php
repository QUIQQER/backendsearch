<?php

/**
 * This file contains QUI\BackendSearch\Search
 */

namespace QUI\BackendSearch;

/**
 * Class Cron
 */
class Cron
{
    /**
     * Build the search cache
     *
     * @return void
     */
    public static function buildSearchCache(): void
    {
        Builder::getInstance()->setup();
    }
}
