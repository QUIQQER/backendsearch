<?php

if (!defined('QUIQQER_SYSTEM')) {
    define('QUIQQER_SYSTEM', true);
}

if (!defined('QUIQQER_AJAX')) {
    define('QUIQQER_AJAX', true);
}

require_once __DIR__ . '/QUI/BackendSearch/DatabaseEnvironment.php';
require_once __DIR__ . '/../../../../bootstrap.php';

require_once __DIR__ . '/QUI/BackendSearch/DatabaseTestCase.php';

$phpunitBootstrapConnection = null;

if (!QUITests\BackendSearch\DatabaseEnvironment::usesCiDatabase()) {
    $phpunitBootstrapConnection = Doctrine\DBAL\DriverManager::getConnection([
        'driver' => 'pdo_sqlite',
        'memory' => true
    ]);

    (new ReflectionProperty(QUI::class, 'QueryBuilder'))->setValue(
        null,
        $phpunitBootstrapConnection
    );

    QUI\Update::importDatabase(CMS_DIR . 'packages/quiqqer/core/database.xml');
    QUI\Update::importDatabase(dirname(__DIR__) . '/database.xml');

    register_shutdown_function(static function () use ($phpunitBootstrapConnection): void {
        $phpunitBootstrapConnection->close();
    });
}
