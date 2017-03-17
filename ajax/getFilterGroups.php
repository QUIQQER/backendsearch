<?php

use QUI\BackendSearch\Builder;

/**
 * Get all available provider search groups
 *
 * @return array
 */
QUI::$Ajax->registerFunction(
    'package_quiqqer_backendsearch_ajax_getFilterGroups',
    function () {
        return Builder::getInstance()->getFilterGroups();
    },
    array()
);
