<?php

/**
 * Search for the desktop
 *
 * @param string $search
 * @param string $params
 *
 * @return array
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_backendsearch_ajax_search',
    function ($search, $params) {
        return QUI\Workspace\Search\Search::getInstance()->search(
            $search,
            json_decode($params, true)
        );
    },
    array('search', 'params')
);
