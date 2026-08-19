<?php

namespace QUITests\BackendSearch;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
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
use ReflectionProperty;

class ProviderSearchTest extends TestCase
{
    private Connection $originalConnection;
    private Connection $connection;
    private ?ProjectManager $originalProjectManager;
    private array $originalConfigs;
    private array $originalProjects;
    private ?Locale $originalLocale;
    private ?UserManager $originalUsers;
    private ?GroupManager $originalGroups;
    private mixed $originalPermissionUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = QUI::getDataBaseConnection();
        $this->originalProjectManager = QUI::$ProjectManager;
        $this->originalConfigs = QUI::$Configs;
        $this->originalProjects = ProjectManager::$projects;
        $this->originalLocale = QUI::$Locale;
        $this->originalUsers = QUI::$Users;
        $this->originalGroups = QUI::$Groups;
        $this->originalPermissionUser = (new ReflectionProperty(Permission::class, 'User'))->getValue();
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true
        ]);

        $this->setConnection($this->connection);
        QUI::$ProjectManager = new ProjectManager();

        $Locale = $this->createMock(Locale::class);
        $Locale->method('get')->willReturnCallback(
            static function (string $group, string $variable, mixed $params = false): string {
                return $variable . ':' . (is_array($params) ? implode(',', $params) : '');
            }
        );
        QUI::$Locale = $Locale;
    }

    protected function tearDown(): void
    {
        $this->setConnection($this->originalConnection);
        QUI::$ProjectManager = $this->originalProjectManager;
        QUI::$Configs = $this->originalConfigs;
        ProjectManager::$projects = $this->originalProjects;
        QUI::$Locale = $this->originalLocale;
        QUI::$Users = $this->originalUsers;
        QUI::$Groups = $this->originalGroups;
        (new ReflectionProperty(Permission::class, 'User'))->setValue(null, $this->originalPermissionUser);
        $this->connection->close();

        parent::tearDown();
    }

    public function testMediaSearchUsesSelectedTypesAndMapsResultIcons(): void
    {
        $tableName = 'phpunit_backendsearch_media';
        $Table = new Table($tableName);
        $Table->addColumn('id', 'integer');
        $Table->addColumn('title', 'string', ['length' => 100]);
        $Table->addColumn('file', 'string', ['length' => 100]);
        $Table->addColumn('type', 'string', ['length' => 20]);
        $Table->addColumn('mime_type', 'string', ['length' => 100]);
        $Table->setPrimaryKey(['id']);
        $this->connection->createSchemaManager()->createTable($Table);

        foreach (
            [
                [1, 'Needle document', 'document.pdf', 'file', 'application/pdf'],
                [2, 'Needle folder', '', 'folder', ''],
                [3, 'Needle image', 'image.png', 'image', 'image/png'],
                [4, 'Needle ignored', 'ignored.bin', 'other', 'application/octet-stream']
            ] as [$id, $title, $file, $type, $mimeType]
        ) {
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

    public function testSitesSearchBuildsProjectSpecificResults(): void
    {
        $Site = $this->createMock(Site::class);
        $Site->method('getAttribute')->with('title')->willReturn('SQLite site');
        $Site->method('getId')->willReturn(42);
        $Site->method('getUrlRewritten')->willReturn('/sqlite-site');

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
        self::assertSame('SQLite site (#42)', $results[0]['title']);
        self::assertSame('/sqlite-site', $results[0]['description']);
        self::assertSame('project-phpunit-sites-en', $results[0]['group']);
    }

    public function testUsersAndGroupsSearchesSqliteTablesWithNumericTerm(): void
    {
        QUI::$Users = new UserManager();
        QUI::$Groups = new GroupManager();

        $User = $this->createMock(User::class);
        $User->method('isSU')->willReturn(true);
        Permission::setUser($User);

        $usersTable = UserManager::table();
        $addressesTable = UserManager::tableAddress();
        $groupsTable = GroupManager::table();

        $Users = new Table($usersTable);
        $Users->addColumn('id', 'integer');
        $Users->addColumn('uuid', 'string', ['length' => 64]);
        $Users->addColumn('username', 'string', ['length' => 100]);
        $Users->addColumn('firstname', 'string', ['length' => 100]);
        $Users->addColumn('lastname', 'string', ['length' => 100]);
        $Users->addColumn('email', 'string', ['length' => 100]);
        $Users->setPrimaryKey(['id']);
        $this->connection->createSchemaManager()->createTable($Users);

        $Addresses = new Table($addressesTable);
        $Addresses->addColumn('userUuid', 'string', ['length' => 64]);
        foreach (['firstname', 'lastname', 'mail', 'company', 'street_no', 'zip', 'city'] as $column) {
            $Addresses->addColumn($column, 'string', ['length' => 100]);
        }
        $this->connection->createSchemaManager()->createTable($Addresses);

        $Groups = new Table($groupsTable);
        $Groups->addColumn('id', 'integer');
        $Groups->addColumn('name', 'string', ['length' => 100]);
        $Groups->setPrimaryKey(['id']);
        $this->connection->createSchemaManager()->createTable($Groups);

        $this->connection->insert($usersTable, [
            'id' => 7,
            'uuid' => 'phpunit-user-uuid',
            'username' => 'sqlite-user',
            'firstname' => 'Test',
            'lastname' => 'User',
            'email' => 'test@example.invalid'
        ]);
        $this->connection->insert($addressesTable, [
            'userUuid' => 'phpunit-user-uuid',
            'firstname' => 'Test',
            'lastname' => 'User',
            'mail' => 'test@example.invalid',
            'company' => 'Company',
            'street_no' => '7',
            'zip' => '12345',
            'city' => 'Test City'
        ]);
        $this->connection->insert($groupsTable, [
            'id' => 7,
            'name' => 'sqlite-group'
        ]);

        $Provider = new UsersAndGroups();
        $results = $Provider->search('7', [
            'filterGroups' => [UsersAndGroups::FILTER_USERS_GROUPS],
            'limit' => 5
        ]);

        self::assertSame(['u7', 'g7'], array_column($results, 'id'));
        self::assertSame(['sqlite-user', 'sqlite-group'], array_column($results, 'title'));
        self::assertSame(['users', 'groups'], array_column($results, 'group'));
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

    private function setConnection(Connection $Connection): void
    {
        (new ReflectionProperty(QUI::class, 'QueryBuilder'))->setValue(null, $Connection);
    }
}
