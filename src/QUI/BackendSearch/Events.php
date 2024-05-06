<?php

/**
 * This file contains QUI\BackendSearch\Search
 */

namespace QUI\BackendSearch;

use QUI;
use QUI\Package\Package;

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
    public static function onAdminLoadFooter(): void
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
    public static function onPackageSetup(Package $Package): void
    {
        if ($Package->getName() !== 'quiqqer/backendsearch') {
            return;
        }

        $Conf = $Package->getConfig();
        $created = $Conf?->get('setup', 'cron_created');

        if (!empty($created)) {
            return;
        }

        if (!QUI::getDataBase()->table()->exist('cron')) {
            try {
                $CronPackage = QUI::getPackage('quiqqer/cron');
                $CronPackage->setup();
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::addError($Exception->getMessage(), [
                    'trace' => $Exception->getTraceAsString()
                ]);

                return;
            }
        }

        $CronManager = new QUI\Cron\Manager();
        $cronTitle = QUI::getLocale()->get('quiqqer/backendsearch', 'cron.search.build.title');

        if (!$CronManager->isCronSetUp($cronTitle)) {
            $CronManager->add($cronTitle, '0', '0', '*', '*', '*');
        }

        $Conf?->set('setup', 'cron_created', 1);
        $Conf?->save();
    }
}
