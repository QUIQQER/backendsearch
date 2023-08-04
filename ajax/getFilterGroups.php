<?php

/**
 * Get all available provider search groups
 *
 * @return array
 */

use QUI\BackendSearch\Builder;

QUI::$Ajax->registerFunction(
    'package_quiqqer_backendsearch_ajax_getFilterGroups',
    function () {
        return Builder::getInstance()->getFilterGroups();
    }
);
