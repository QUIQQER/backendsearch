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
     *
     * @throws QUI\Exception
     */
    public static function onPackageSetup(Package $Package)
    {
        if ($Package->getName() !== 'quiqqer/backendsearch') {
            return;
        }

        $Conf    = $Package->getConfig();
        $created = $Conf->get('setup', 'cron_created');

        if (!empty($created)) {
            return;
        }

        $CronManager = new QUI\Cron\Manager();
        $cronTitle   = QUI::getLocale()->get('quiqqer/backendsearch', 'cron.search.build.title');

        if (!$CronManager->isCronSetUp($cronTitle)) {
            $CronManager->add($cronTitle, '0', '0', '*', '*', '*');
        }

        $Conf->set('setup', 'cron_created', 1);
        $Conf->save();
    }
}
