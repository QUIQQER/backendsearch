<?php

/**
 * This file contains QUI\BackendSearch\Search
 */
namespace QUI\BackendSearch;

use QUI;
use QUI\Package\Package;
use QUI\BackendSearch\Builder;

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

    /**
     * QUIQQER Event: onPackageSetup
     *
     * @param Package $Package
     * @return void
     */
    public static function onPackageSetup(Package $Package)
    {
        if ($Package->getName() !== 'quiqqer/backendsearch') {
            return;
        }

        Builder::getInstance()->setup();
    }
}
