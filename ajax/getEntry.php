<?php

/**
 * Search for the desktop
 *
 * @param string $search
 * @param string $params
 *
 * @return array
 */

use QUI\BackendSearch\Builder;
use QUI\BackendSearch\Search;

QUI::$Ajax->registerFunction(
    'package_quiqqer_backendsearch_ajax_getEntry',
    function ($id, $provider) {
        $Search = Search::getInstance();
        $Builder = Builder::getInstance();

        if (empty($provider)) {
            return $Search->getEntry($id);
        }

        $Provider = $Builder->getProvider($provider);
        $entryData = $Provider->getEntry($id);

        if (
            $entryData
            && isset($entryData['searchdata'])
            && is_array($entryData['searchdata'])
        ) {
            $entryData['searchdata'] = json_encode($entryData['searchdata']);
        }

        return $entryData;
    },
    ['id', 'provider']
);
