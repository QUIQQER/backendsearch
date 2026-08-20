<?php

namespace QUITests\BackendSearch;

use QUI\BackendSearch\Builder;
use QUI\BackendSearch\ProviderInterface;
use QUI\Utils\Doctrine as DoctrineUtils;
use RuntimeException;

class BuilderDatabaseTest extends DatabaseTestCase
{
    public function testAddEntryPreservesDescriptionWhenIconIsMissing(): void
    {
        $Builder = new Builder();
        $group = 'phpunit-add-entry-' . bin2hex(random_bytes(8));

        $Builder->addEntry([
            'title' => 'Database entry',
            'description' => 'Description must be preserved',
            'search' => 'database searchable text',
            'group' => $group,
            'filterGroup' => 'phpunit',
            'searchdata' => [
                'require' => 'controls/phpunit/Test'
            ]
        ], 'en');

        $stored = $this->connection->createQueryBuilder()
            ->select('description', 'icon', 'searchdata', 'lang')
            ->from($Builder->getTable())
            ->where('"group" = :group')
            ->setParameter('group', $group)
            ->executeQuery()
            ->fetchAssociative();

        self::assertIsArray($stored);
        self::assertSame('Description must be preserved', $stored['description']);
        self::assertSame('', $stored['icon']);
        self::assertSame(
            ['require' => 'controls/phpunit/Test'],
            json_decode((string)$stored['searchdata'], true)
        );
        self::assertSame('en', $stored['lang']);
    }

    public function testBuildCacheClearsEntriesAndRunsEveryCacheBuilderAndProvider(): void
    {
        $databaseBuilder = new Builder();
        $databaseBuilder->addEntry([
            'title' => 'Entry removed by rebuild',
            'search' => 'remove me',
            'group' => 'phpunit-rebuild',
            'filterGroup' => 'phpunit',
            'searchdata' => ['require' => 'controls/phpunit/Rebuild']
        ], 'en');

        $Provider = new class implements ProviderInterface {
            public int $buildCalls = 0;

            public function buildCache(): void
            {
                $this->buildCalls++;
            }

            public function search(string $search, array $params = []): array
            {
                return [];
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
            public int $buildCalls = 0;

            public function buildCache(): void
            {
                $this->buildCalls++;
                throw new RuntimeException('Expected cache provider failure');
            }

            public function search(string $search, array $params = []): array
            {
                return [];
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
            /** @var array<string, int> */
            public array $cacheBuilds = [
                'apps' => 0,
                'extras' => 0,
                'profile' => 0
            ];

            /** @param array<int, ProviderInterface> $providers */
            public function __construct(private array $providers)
            {
            }

            public function buildAppsCache(): void
            {
                $this->cacheBuilds['apps']++;
            }

            public function buildExtrasCache(): void
            {
                $this->cacheBuilds['extras']++;
            }

            public function buildProfileCache(): void
            {
                $this->cacheBuilds['profile']++;
            }

            public function getProvider(bool | string $provider = false): ProviderInterface | array
            {
                return $this->providers;
            }
        };

        $Builder->buildCache();

        self::assertSame(['apps' => 1, 'extras' => 1, 'profile' => 1], $Builder->cacheBuilds);
        self::assertSame(1, $Provider->buildCalls);
        self::assertSame(1, $FailingProvider->buildCalls);
        self::assertSame(0, (int)$this->connection->fetchOne(
            'SELECT COUNT(*) FROM ' . $Builder->getTable()
        ));
    }

    public function testDeleteEntriesByGroupOnlyRemovesSelectedGroup(): void
    {
        $prefix = 'phpunit-delete-' . bin2hex(random_bytes(8));
        $removeGroup = $prefix . '-remove';
        $keepGroup = $prefix . '-keep';
        $Builder = new class extends Builder {
            public function deleteEntriesByGroupPublic(string $group): void
            {
                $this->deleteEntriesByGroup($group);
            }
        };

        foreach ([$removeGroup, $keepGroup] as $group) {
            $Builder->addEntry([
                'title' => $group,
                'search' => $group,
                'group' => $group,
                'filterGroup' => 'phpunit',
                'searchdata' => ['require' => 'controls/phpunit/DeleteGroup']
            ], 'en');
        }

        $Builder->deleteEntriesByGroupPublic($removeGroup);

        self::assertSame(
            [$keepGroup],
            $this->connection->fetchFirstColumn(
                'SELECT ' . DoctrineUtils::quoteIdentifier('group') . ' FROM ' . $Builder->getTable()
                . ' WHERE ' . DoctrineUtils::quoteIdentifier('group') . ' IN (?, ?)'
                . ' ORDER BY ' . DoctrineUtils::quoteIdentifier('group'),
                [$removeGroup, $keepGroup]
            )
        );
    }
}
