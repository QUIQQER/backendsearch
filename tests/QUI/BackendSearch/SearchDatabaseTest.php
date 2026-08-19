<?php

namespace QUITests\BackendSearch;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\BackendSearch\Builder;
use QUI\BackendSearch\ProviderInterface;
use QUI\BackendSearch\Search;
use QUI\Interfaces\Users\User;
use QUI\Update;
use QUI\Users\Manager as UserManager;
use ReflectionProperty;
use RuntimeException;
use Throwable;

class SearchDatabaseTest extends TestCase
{
    private Connection $originalConnection;
    private Connection $connection;
    private ?UserManager $originalUserManager;
    private mixed $originalBuilder;
    private ReflectionProperty $builderInstance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = QUI::getDataBaseConnection();
        $this->originalUserManager = QUI::$Users;
        $this->builderInstance = new ReflectionProperty(Builder::class, 'Instance');
        $this->originalBuilder = $this->builderInstance->getValue();
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true
        ]);

        try {
            $this->setConnection($this->connection);
            Update::importDatabase(dirname(__DIR__, 3) . '/database.xml');

            $User = $this->createMock(User::class);
            $User->method('getLang')->willReturn('en');
            $Users = $this->createMock(UserManager::class);
            $Users->method('getUserBySession')->willReturn($User);
            QUI::$Users = $Users;
        } catch (Throwable $Exception) {
            $this->restoreGlobalState();
            $this->connection->close();

            throw $Exception;
        }
    }

    protected function tearDown(): void
    {
        $this->restoreGlobalState();
        $this->connection->close();

        parent::tearDown();
    }

    public function testSearchCombinesFiltersProvidersAndDeduplicatesResults(): void
    {
        $databaseBuilder = new Builder();
        $databaseBuilder->addEntry([
            'title' => 'Matching database entry',
            'description' => 'Stored in SQLite',
            'icon' => 'fa fa-database',
            'search' => 'alpha searchable',
            'group' => 'target-group',
            'filterGroup' => 'test-filter',
            'searchdata' => ['require' => 'controls/phpunit/Database']
        ], 'en');
        $databaseBuilder->addEntry([
            'title' => 'Different language',
            'search' => 'alpha searchable',
            'group' => 'target-group',
            'filterGroup' => 'test-filter',
            'searchdata' => ['require' => 'controls/phpunit/Database']
        ], 'de');

        $storedId = (int)$this->connection->fetchOne(
            'SELECT id FROM ' . $databaseBuilder->getTable() . ' WHERE lang = ?',
            ['en']
        );

        $Provider = new class ($storedId) implements ProviderInterface {
            /** @var array<string, mixed> */
            public array $receivedParams = [];

            public function __construct(private int $duplicateId)
            {
            }

            public function buildCache(): void
            {
            }

            public function search(string $search, array $params = []): array
            {
                $this->receivedParams = $params;

                return [
                    ['id' => $this->duplicateId, 'title' => 'Duplicate'],
                    ['id' => 'provider-result', 'title' => 'Provider result'],
                    ['title' => 'Provider result without ID']
                ];
            }

            public function getEntry(string | int $id): mixed
            {
                return null;
            }

            public function getFilterGroups(): array
            {
                return [];
            }
        };

        $FailingProvider = new class implements ProviderInterface {
            public function buildCache(): void
            {
            }

            public function search(string $search, array $params = []): array
            {
                throw new RuntimeException('Expected provider failure');
            }

            public function getEntry(string | int $id): mixed
            {
                return null;
            }

            public function getFilterGroups(): array
            {
                return [];
            }
        };

        $Builder = new class ([$Provider, $FailingProvider]) extends Builder {
            /** @var array<int, string> */
            public array $receivedFilters = [];

            /** @param array<int, ProviderInterface> $providers */
            public function __construct(private array $providers)
            {
            }

            public function getProvider(bool | string $provider = false): ProviderInterface | array
            {
                return $this->providers;
            }

            public function getWhereConstraint(array $filters): array
            {
                $this->receivedFilters = $filters;

                return [];
            }
        };
        $this->builderInstance->setValue(null, $Builder);

        $result = (new Search())->search('  alpha  ', [
            'group' => 'target-group',
            'filterGroups' => ['test-filter', 123],
            'limit' => 2
        ]);

        self::assertCount(3, $result);
        self::assertSame($storedId, (int)$result[0]['id']);
        self::assertSame('Matching database entry', $result[0]['title']);
        self::assertSame('provider-result', $result[1]['id']);
        self::assertArrayNotHasKey('id', $result[2]);
        self::assertSame(['test-filter'], $Builder->receivedFilters);
        self::assertSame(2, $Provider->receivedParams['limit']);
        self::assertSame(get_class($Provider), $result[1]['provider']);
        self::assertSame(get_class($Provider), $result[2]['provider']);
    }

    public function testGetEntryReturnsStoredEntryAndEmptyArrayForMissingId(): void
    {
        $Builder = new Builder();
        $Builder->addEntry([
            'title' => 'Entry lookup',
            'search' => 'lookup',
            'group' => 'lookup-group',
            'filterGroup' => 'lookup-filter',
            'searchdata' => ['require' => 'controls/phpunit/Lookup']
        ], 'en');
        $this->builderInstance->setValue(null, $Builder);

        $id = (int)$this->connection->fetchOne(
            'SELECT id FROM ' . $Builder->getTable() . ' WHERE "group" = ?',
            ['lookup-group']
        );

        $Search = new Search();
        $entry = $Search->getEntry($id);

        self::assertSame('Entry lookup', $entry['title']);
        self::assertSame('en', $entry['lang']);
        self::assertSame([], $Search->getEntry($id + 1000));
    }

    private function restoreGlobalState(): void
    {
        $this->setConnection($this->originalConnection);
        QUI::$Users = $this->originalUserManager;
        $this->builderInstance->setValue(null, $this->originalBuilder);
    }

    private function setConnection(Connection $Connection): void
    {
        (new ReflectionProperty(QUI::class, 'QueryBuilder'))->setValue(null, $Connection);
    }
}
