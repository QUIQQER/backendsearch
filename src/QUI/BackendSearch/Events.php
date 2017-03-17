<?php

/**
 * This file contains QUI\BackendSearch\Search
 */
namespace QUI\BackendSearch;

use QUI;

/**
 * Class Events
 */
class Events
{
    /**
     * QUIQQER Event: onAdminLoadFooter
     *
     * @return void
     */
    public static function onAdminLoadFooter()
    {
        $jsFile = URL_OPT_DIR . 'quiqqer/backendsearch/bin/onAdminLoadFooter.js';
        echo '<script src="' . $jsFile . '"></script>';
    }
}
