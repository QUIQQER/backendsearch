<?php

/**
 * Search for the desktop
 *
 * @param string $search
 * @param string $params
 *
 * @return array
 */

use QUI\BackendSearch\Search;

QUI::$Ajax->registerFunction(
    'package_quiqqer_backendsearch_ajax_search',
    function ($search, $params) {
        return Search::getInstance()->search(
            $search,
            json_decode($params, true)
        );
    },
    ['search', 'params']
);
