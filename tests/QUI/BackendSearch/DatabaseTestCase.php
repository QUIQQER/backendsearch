<?php

declare(strict_types=1);

namespace QUITests\BackendSearch;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use QUI;

abstract class DatabaseTestCase extends TestCase
{
    protected Connection $connection;

    private bool $ownsTransaction = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = QUI::getDataBaseConnection();

        if (!$this->connection->isTransactionActive()) {
            $this->connection->beginTransaction();
            $this->ownsTransaction = true;
        }
    }

    protected function tearDown(): void
    {
        if ($this->ownsTransaction && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }
}
