<?php

namespace QUITests\BackendSearch;

use QUI;
use QUI\BackendSearch\Builder;
use QUI\BackendSearch\ProviderInterface;
use QUI\BackendSearch\Search;
use QUI\Interfaces\Users\User;
use QUI\Users\Manager as UserManager;
use QUI\Utils\Doctrine as DoctrineUtils;
use ReflectionProperty;
use RuntimeException;

class SearchDatabaseTest extends DatabaseTestCase
{
    private ?UserManager $originalUserManager;
    private mixed $originalBuilder;
    private ReflectionProperty $builderInstance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalUserManager = QUI::$Users;
        $this->builderInstance = new ReflectionProperty(Builder::class, 'Instance');
        $this->originalBuilder = $this->builderInstance->getValue();
        $User = $this->createMock(User::class);
        $User->method('getLang')->willReturn('en');
        $Users = $this->createMock(UserManager::class);
        $Users->method('getUserBySession')->willReturn($User);
        QUI::$Users = $Users;
    }

    protected function tearDown(): void
    {
        $this->restoreGlobalState();
        parent::tearDown();
    }

    public function testSearchCombinesFiltersProvidersAndDeduplicatesResults(): void
    {
        $searchTerm = 'phpunit-alpha-' . bin2hex(random_bytes(8));
        $targetGroup = 'phpunit-target-' . bin2hex(random_bytes(8));
        $databaseBuilder = new Builder();
        $databaseBuilder->addEntry([
            'title' => 'Matching database entry',
            'description' => 'Stored in the database',
            'icon' => '/bin/22x22/quiqqer.png',
            'search' => $searchTerm,
            'group' => $targetGroup,
            'filterGroup' => 'test-filter',
            'searchdata' => ['require' => 'controls/phpunit/Database']
        ], 'en');
        $databaseBuilder->addEntry([
            'title' => 'Different language',
            'search' => $searchTerm,
            'group' => $targetGroup,
            'filterGroup' => 'test-filter',
            'searchdata' => ['require' => 'controls/phpunit/Database']
        ], 'de');

        $storedId = (int)$this->connection->fetchOne(
            'SELECT ' . DoctrineUtils::quoteIdentifier('id') . ' FROM ' . $databaseBuilder->getTable()
            . ' WHERE ' . DoctrineUtils::quoteIdentifier('lang') . ' = ?'
            . ' AND ' . DoctrineUtils::quoteIdentifier('group') . ' = ?',
            ['en', $targetGroup]
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
                    ['id' => $this->duplicateId, 'title' => 'Duplicate', 'group' => 'provider-duplicate'],
                    [
                        'id' => 'provider-result',
                        'title' => 'Provider result',
                        'icon' => 'fa fa-star',
                        'group' => 'provider-group'
                    ],
                    ['title' => 'Provider result without ID', 'group' => 'provider-group']
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

        $result = (new Search())->search('  ' . $searchTerm . '  ', [
            'filterGroups' => ['test-filter', 123],
            'limit' => 2
        ]);

        self::assertCount(3, $result);
        self::assertSame($storedId, (int)$result[0]['id']);
        self::assertSame('Matching database entry', $result[0]['title']);
        self::assertSame('', $result[0]['icon']);
        self::assertSame('/bin/22x22/quiqqer.png', $result[0]['iconUrl']);
        self::assertSame('provider-result', $result[1]['id']);
        self::assertSame('fa fa-star', $result[1]['icon']);
        self::assertArrayNotHasKey('iconUrl', $result[1]);
        self::assertArrayNotHasKey('id', $result[2]);
        self::assertSame([false, false, false], array_column($result, 'groupHasMore'));
        self::assertSame(['test-filter'], $Builder->receivedFilters);
        self::assertSame(3, $Provider->receivedParams['limit']);
        self::assertSame(get_class($Provider), $result[1]['provider']);
        self::assertSame(get_class($Provider), $result[2]['provider']);

        $providerGroupResult = (new Search())->search($searchTerm, [
            'group' => 'provider-group',
            'limit' => 1
        ]);

        self::assertCount(1, $providerGroupResult);
        self::assertSame('provider-result', $providerGroupResult[0]['id']);
        self::assertTrue($providerGroupResult[0]['groupHasMore']);
        self::assertSame(2, $Provider->receivedParams['limit']);
    }

    public function testSearchLimitsEachGroupAndReportsAdditionalResults(): void
    {
        $searchTerm = 'phpunit-group-limit-' . bin2hex(random_bytes(8));
        $groupA = 'phpunit-group-a-' . bin2hex(random_bytes(8));
        $groupB = 'phpunit-group-b-' . bin2hex(random_bytes(8));
        $Builder = new Builder();

        foreach (range(1, 4) as $number) {
            $Builder->addEntry([
                'title' => 'Group A entry ' . $number,
                'search' => $searchTerm,
                'group' => $groupA,
                'filterGroup' => 'limit-test',
                'searchdata' => ['require' => 'controls/phpunit/GroupA']
            ], 'en');
        }

        foreach (range(1, 2) as $number) {
            $Builder->addEntry([
                'title' => 'Group B entry ' . $number,
                'search' => $searchTerm,
                'group' => $groupB,
                'filterGroup' => 'limit-test',
                'searchdata' => ['require' => 'controls/phpunit/GroupB']
            ], 'en');
        }

        $this->builderInstance->setValue(null, $Builder);
        $Search = new Search();
        $result = $Search->search($searchTerm, ['limit' => 2]);

        self::assertCount(4, $result);
        self::assertSame([$groupA, $groupA, $groupB, $groupB], array_column($result, 'group'));
        self::assertSame([true, true, false, false], array_column($result, 'groupHasMore'));

        $groupResult = $Search->search($searchTerm, [
            'group' => $groupA,
            'limit' => 3
        ]);

        self::assertCount(3, $groupResult);
        self::assertSame([$groupA, $groupA, $groupA], array_column($groupResult, 'group'));
        self::assertSame([true, true, true], array_column($groupResult, 'groupHasMore'));
    }

    public function testGetEntryReturnsStoredEntryAndEmptyArrayForMissingId(): void
    {
        $group = 'phpunit-lookup-' . bin2hex(random_bytes(8));
        $Builder = new Builder();
        $Builder->addEntry([
            'title' => 'Entry lookup',
            'search' => 'lookup',
            'group' => $group,
            'filterGroup' => 'lookup-filter',
            'searchdata' => ['require' => 'controls/phpunit/Lookup']
        ], 'en');
        $this->builderInstance->setValue(null, $Builder);

        $id = (int)$this->connection->fetchOne(
            'SELECT ' . DoctrineUtils::quoteIdentifier('id') . ' FROM ' . $Builder->getTable()
            . ' WHERE ' . DoctrineUtils::quoteIdentifier('group') . ' = ?',
            [$group]
        );

        $Search = new Search();
        $entry = $Search->getEntry($id);
        $missingId = (int)$this->connection->fetchOne(
            'SELECT COALESCE(MAX(' . DoctrineUtils::quoteIdentifier('id') . '), 0) + 1'
            . ' FROM ' . $Builder->getTable()
        );

        self::assertSame('Entry lookup', $entry['title']);
        self::assertSame('en', $entry['lang']);
        self::assertSame([], $Search->getEntry($missingId));
    }

    private function restoreGlobalState(): void
    {
        QUI::$Users = $this->originalUserManager;
        $this->builderInstance->setValue(null, $this->originalBuilder);
    }
}
