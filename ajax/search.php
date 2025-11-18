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
        $params = json_decode($params, true);

        if (!is_array($params)) {
            $params = [];
        }

        return Search::getInstance()->search($search, $params);
    },
    ['search', 'params']
);
