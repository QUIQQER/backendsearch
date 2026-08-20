<?php

namespace QUITests\BackendSearch;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Table;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\BackendSearch\Provider\Media as MediaProvider;
use QUI\BackendSearch\Provider\Sites;
use QUI\BackendSearch\Provider\UsersAndGroups;
use QUI\Config;
use QUI\Groups\Manager as GroupManager;
use QUI\Interfaces\Users\User;
use QUI\Locale;
use QUI\Permissions\Permission;
use QUI\Projects\Manager as ProjectManager;
use QUI\Projects\Media as ProjectMedia;
use QUI\Projects\Project;
use QUI\Projects\Site;
use QUI\Users\Manager as UserManager;
use QUI\Utils\Doctrine as DoctrineUtils;
use ReflectionProperty;
use RuntimeException;

class ProviderSearchTest extends TestCase
{
    private Connection $connection;
    private ?ProjectManager $originalProjectManager;
    private array $originalConfigs;
    private array $originalProjects;
    private ?Locale $originalLocale;
    private ?UserManager $originalUsers;
    private ?GroupManager $originalGroups;
    private mixed $originalPermissionUser;

    /** @var list<string> */
    private array $ownedTables = [];

    private ?string $userFixtureUuid = null;
    private ?string $groupFixtureUuid = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = QUI::getDataBaseConnection();
        $this->originalProjectManager = QUI::$ProjectManager;
        $this->originalConfigs = QUI::$Configs;
        $this->originalProjects = ProjectManager::$projects;
        $this->originalLocale = QUI::$Locale;
        $this->originalUsers = QUI::$Users;
        $this->originalGroups = QUI::$Groups;
        $this->originalPermissionUser = (new ReflectionProperty(Permission::class, 'User'))->getValue();
        QUI::$ProjectManager = new ProjectManager();

        $Locale = $this->createMock(Locale::class);
        $Locale->method('get')->willReturnCallback(
            static function (string $group, string $variable, mixed $params = false): string {
                return $variable . ':' . (is_array($params) ? implode(',', $params) : '');
            }
        );
        $Locale->method('getCurrent')->willReturn('en');
        QUI::$Locale = $Locale;
    }

    protected function tearDown(): void
    {
        try {
            $this->removeDatabaseFixtures();
        } finally {
            QUI::$ProjectManager = $this->originalProjectManager;
            QUI::$Configs = $this->originalConfigs;
            ProjectManager::$projects = $this->originalProjects;
            QUI::$Locale = $this->originalLocale;
            QUI::$Users = $this->originalUsers;
            QUI::$Groups = $this->originalGroups;
            (new ReflectionProperty(Permission::class, 'User'))->setValue(null, $this->originalPermissionUser);
        }

        parent::tearDown();
    }

    public function testMediaSearchUsesSelectedTypesAndMapsResultIcons(): void
    {
        $this->createMediaSearchProject([
            [1, 'Needle document', 'document.pdf', 'file', 'application/pdf'],
            [2, 'Needle folder', '', 'folder', ''],
            [3, 'Needle image', 'image.png', 'image', 'image/png'],
            [4, 'Needle ignored', 'ignored.bin', 'other', 'application/octet-stream']
        ]);

        $results = (new MediaProvider())->search('Needle', [
            'filterGroups' => ['file', 'folder', 'image'],
            'limit' => 10
        ]);

        self::assertCount(3, $results);
        self::assertSame(['fa fa-file-text-o', 'fa fa-folder-o', 'fa fa-picture-o'], array_column($results, 'icon'));
        self::assertSame(
            ['phpunit-media-project-1', 'phpunit-media-project-2', 'phpunit-media-project-3'],
            array_column($results, 'id')
        );
        self::assertSame('document.pdf', $results[0]['description']);
        self::assertSame('phpunit-media-project-media', $results[0]['group']);
    }

    public function testMediaSearchFindsExactIdsWithinSelectedTypes(): void
    {
        $this->createMediaSearchProject([
            [42, 'First image', 'first.png', 'image', 'image/png'],
            [142, 'Second image', 'second.png', 'image', 'image/png'],
            [43, 'Document', 'document.pdf', 'file', 'application/pdf'],
            [44, 'Folder', '', 'folder', '']
        ]);

        $Provider = new MediaProvider();
        $filterGroups = ['file', 'folder', 'image'];

        self::assertSame(
            ['phpunit-media-project-42'],
            array_column($Provider->search('42', ['filterGroups' => $filterGroups]), 'id')
        );
        self::assertSame(
            ['phpunit-media-project-43'],
            array_column($Provider->search('43', ['filterGroups' => $filterGroups]), 'id')
        );
        self::assertSame(
            ['phpunit-media-project-44'],
            array_column($Provider->search('44', ['filterGroups' => $filterGroups]), 'id')
        );
        self::assertSame([], $Provider->search('43', ['filterGroups' => ['image']]));
    }

    public function testMediaSearchLocalizesJsonTitlesAndUsesAvailableFallbacks(): void
    {
        $this->createMediaSearchProject([
            [
                1,
                '{"de":"Deutscher Needle-Titel","en":"English Needle title"}',
                'localized.pdf',
                'file',
                'application/pdf'
            ],
            [
                2,
                '{"de":"Fallback Needle-Titel"}',
                'fallback.pdf',
                'file',
                'application/pdf'
            ],
            [3, 'Plain Needle title', 'plain.pdf', 'file', 'application/pdf']
        ]);

        $results = (new MediaProvider())->search('Needle', [
            'filterGroups' => ['file'],
            'limit' => 10
        ]);

        self::assertSame(
            ['English Needle title', 'Fallback Needle-Titel', 'Plain Needle title'],
            array_column($results, 'title')
        );
    }

    public function testSitesSearchBuildsProjectSpecificResults(): void
    {
        $Site = $this->createMock(Site::class);
        $Site->method('getAttribute')->with('title')->willReturn('Database site');
        $Site->method('getId')->willReturn(42);
        $Site->method('getUrlRewritten')->willReturn('/database-site');

        $Project = $this->createMock(Project::class);
        $Project->method('getName')->willReturn('phpunit-sites');
        $Project->method('getLang')->willReturn('en');
        $Project->expects(self::once())
            ->method('getSitesIds')
            ->with(self::callback(static function (array $params): bool {
                return $params['where']['active'] === -1
                    && $params['where_or']['title']['value'] === 'needle'
                    && $params['where_or']['name']['value'] === 'needle'
                    && $params['where_or']['id'] === 'needle'
                    && $params['limit'] === 3;
            }))
            ->willReturn([['id' => 42]]);
        $Project->method('get')->with(42)->willReturn($Site);
        $this->setProjects(['phpunit-sites' => ['en' => $Project]]);

        $results = (new Sites())->search('needle', [
            'filterGroups' => [Sites::FILTER_SITES],
            'limit' => 3
        ]);

        self::assertCount(1, $results);
        self::assertSame('phpunit-sites-en-42', $results[0]['id']);
        self::assertSame('Database site (#42)', $results[0]['title']);
        self::assertSame('/database-site', $results[0]['description']);
        self::assertSame('project-phpunit-sites-en', $results[0]['group']);
    }

    public function testUsersAndGroupsSearchesDatabaseTablesWithNumericTerm(): void
    {
        QUI::$Users = new UserManager();
        QUI::$Groups = new GroupManager();

        $User = $this->createMock(User::class);
        $User->method('isSU')->willReturn(true);
        Permission::setUser($User);

        $usersTable = UserManager::table();
        $addressesTable = UserManager::tableAddress();
        $groupsTable = GroupManager::table();
        $fixtureId = $this->findUnusedUserAndGroupId($usersTable, $groupsTable);
        $fixtureToken = bin2hex(random_bytes(8));
        $this->userFixtureUuid = 'phpunit-user-' . $fixtureToken;
        $this->groupFixtureUuid = 'phpunit-group-' . $fixtureToken;

        $this->connection->insert($usersTable, [
            'id' => $fixtureId,
            'uuid' => $this->userFixtureUuid,
            'username' => 'phpunit-user-' . $fixtureToken,
            'firstname' => 'Test',
            'lastname' => 'User',
            'email' => 'test@example.invalid'
        ]);
        $this->connection->insert($addressesTable, [
            'id' => $fixtureId,
            'uuid' => 'phpunit-address-' . $fixtureToken,
            'uid' => (string)$fixtureId,
            'userUuid' => $this->userFixtureUuid,
            'firstname' => 'Test',
            'lastname' => 'User',
            'mail' => 'test@example.invalid',
            'company' => 'Company',
            'street_no' => '7',
            'zip' => '12345',
            'city' => 'Test City'
        ]);
        $this->connection->insert($groupsTable, [
            'id' => $fixtureId,
            'uuid' => $this->groupFixtureUuid,
            'name' => 'phpunit-group-' . $fixtureToken
        ]);

        $Provider = new UsersAndGroups();
        $results = $Provider->search((string)$fixtureId, [
            'filterGroups' => [UsersAndGroups::FILTER_USERS_GROUPS],
            'limit' => 5
        ]);
        $expectedIds = ['u' . $fixtureId, 'g' . $fixtureId];
        $fixtureResults = array_values(array_filter(
            $results,
            static fn (array $result): bool => in_array($result['id'] ?? null, $expectedIds, true)
        ));

        self::assertSame($expectedIds, array_column($fixtureResults, 'id'));
        self::assertSame(
            ['phpunit-user-' . $fixtureToken, 'phpunit-group-' . $fixtureToken],
            array_column($fixtureResults, 'title')
        );
        self::assertSame(['users', 'groups'], array_column($fixtureResults, 'group'));
        self::assertSame(UsersAndGroups::FILTER_USERS_GROUPS, $Provider->getFilterGroups()[0]['group']);
    }

    /** @param array<string, array<string, Project>> $projects */
    private function setProjects(array $projects): void
    {
        $config = [];

        foreach ($projects as $name => $languages) {
            $config[$name] = [
                'langs' => implode(',', array_keys($languages)),
                'template' => ''
            ];
        }

        $Config = $this->createMock(Config::class);
        $Config->method('toArray')->willReturn($config);
        QUI::$Configs['etc/projects.ini'] = $Config;
        ProjectManager::$projects = $projects;
    }

    /** @param list<array{int, string, string, string, string}> $rows */
    private function createMediaSearchProject(array $rows): void
    {
        $tableName = QUI::getDBTableName('backendsearch_test_media_' . bin2hex(random_bytes(6)));
        $Table = new Table($tableName);
        $Table->addColumn('id', 'integer');
        $Table->addColumn('title', 'string', ['length' => 100]);
        $Table->addColumn('file', 'string', ['length' => 100]);
        $Table->addColumn('type', 'string', ['length' => 20]);
        $Table->addColumn('mime_type', 'string', ['length' => 100]);
        $Table->setPrimaryKey(['id']);
        $this->connection->createSchemaManager()->createTable($Table);
        $this->ownedTables[] = $tableName;

        foreach ($rows as [$id, $title, $file, $type, $mimeType]) {
            $this->connection->insert($tableName, [
                'id' => $id,
                'title' => $title,
                'file' => $file,
                'type' => $type,
                'mime_type' => $mimeType
            ]);
        }

        $Media = $this->createMock(ProjectMedia::class);
        $Media->method('getTable')->willReturn($tableName);
        $Project = $this->createMock(Project::class);
        $Project->method('getName')->willReturn('phpunit-media-project');
        $Project->method('getMedia')->willReturn($Media);
        $this->setProjects(['phpunit-media-project' => ['de' => $Project]]);
    }

    private function findUnusedUserAndGroupId(string $usersTable, string $groupsTable): int
    {
        foreach (range(1, 20) as $_attempt) {
            $fixtureId = random_int(1_000_000, 2_000_000_000);

            $userExists = (int)$this->connection->createQueryBuilder()
                ->select('COUNT(*)')
                ->from(DoctrineUtils::quoteIdentifier($usersTable))
                ->where(DoctrineUtils::quoteIdentifier('id') . ' = :id')
                ->setParameter('id', $fixtureId)
                ->executeQuery()
                ->fetchOne();
            $groupExists = (int)$this->connection->createQueryBuilder()
                ->select('COUNT(*)')
                ->from(DoctrineUtils::quoteIdentifier($groupsTable))
                ->where(DoctrineUtils::quoteIdentifier('id') . ' = :id')
                ->setParameter('id', $fixtureId)
                ->executeQuery()
                ->fetchOne();

            if ($userExists === 0 && $groupExists === 0) {
                return $fixtureId;
            }
        }

        throw new RuntimeException('Could not allocate a database fixture ID.');
    }

    private function removeDatabaseFixtures(): void
    {
        if ($this->userFixtureUuid !== null) {
            $this->connection->delete(UserManager::tableAddress(), [
                'userUuid' => $this->userFixtureUuid
            ]);
            $this->connection->delete(UserManager::table(), [
                'uuid' => $this->userFixtureUuid
            ]);
        }

        if ($this->groupFixtureUuid !== null) {
            $this->connection->delete(GroupManager::table(), [
                'uuid' => $this->groupFixtureUuid
            ]);
        }

        $SchemaManager = $this->connection->createSchemaManager();

        foreach (array_reverse($this->ownedTables) as $tableName) {
            if ($SchemaManager->tablesExist([$tableName])) {
                $SchemaManager->dropTable($tableName);
            }
        }
    }
}
