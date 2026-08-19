<?php

namespace QUITests\BackendSearch;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\BackendSearch\Builder;
use QUI\BackendSearch\Provider\Projects;
use QUI\Config;
use QUI\Locale;
use QUI\Projects\Manager as ProjectManager;
use QUI\Projects\Project;
use ReflectionProperty;

class ProjectsBuildCacheTest extends TestCase
{
    public function testBuildCacheProcessesEveryProjectInEveryLocale(): void
    {
        $german = $this->createMock(Locale::class);
        $german->method('getCurrent')->willReturn('de');
        $english = $this->createMock(Locale::class);
        $english->method('getCurrent')->willReturn('en');

        $Builder = new class ([$german, $english]) extends Builder {
            /** @var array<int, array{project: string, lang: string}> */
            public array $addedEntries = [];

            /** @param array<int, Locale> $testLocales */
            public function __construct(private array $testLocales)
            {
            }

            /** @return array<int, Locale> */
            public function getLocales(): array
            {
                return $this->testLocales;
            }

            /** @param array<string, mixed> $params */
            public function addEntry(array $params, string $lang): void
            {
                $this->addedEntries[] = [
                    'project' => (string)$params['searchdata']['params']['project'],
                    'lang' => $lang
                ];
            }
        };

        $firstProject = $this->createMock(Project::class);
        $firstProject->method('getName')->willReturn('phpunit-first');
        $firstProject->method('getLang')->willReturn('de');
        $secondProject = $this->createMock(Project::class);
        $secondProject->method('getName')->willReturn('phpunit-second');
        $secondProject->method('getLang')->willReturn('en');

        $Config = $this->createMock(Config::class);
        $Config->method('toArray')->willReturn([
            'phpunit-first' => [
                'langs' => 'de',
                'template' => ''
            ],
            'phpunit-second' => [
                'langs' => 'en',
                'template' => ''
            ]
        ]);

        $Projects = new class extends Projects {
            /** @return array<int, array<string, mixed>> */
            protected function getProjectSettingsSearchTerms(Project $Project, Locale $Locale): array
            {
                return [];
            }
        };

        $Instance = new ReflectionProperty(Builder::class, 'Instance');
        $originalBuilder = $Instance->getValue();
        $originalProjectManager = QUI::$ProjectManager;
        $originalConfigs = QUI::$Configs;
        $originalProjects = ProjectManager::$projects;

        try {
            $Instance->setValue(null, $Builder);
            QUI::$ProjectManager = new ProjectManager();
            QUI::$Configs['etc/projects.ini'] = $Config;
            ProjectManager::$projects = [
                'phpunit-first' => ['de' => $firstProject],
                'phpunit-second' => ['en' => $secondProject]
            ];

            $Projects->buildCache();
        } finally {
            $Instance->setValue(null, $originalBuilder);
            QUI::$ProjectManager = $originalProjectManager;
            QUI::$Configs = $originalConfigs;
            ProjectManager::$projects = $originalProjects;
        }

        self::assertSame([
            ['project' => 'phpunit-first', 'lang' => 'de'],
            ['project' => 'phpunit-first', 'lang' => 'en'],
            ['project' => 'phpunit-second', 'lang' => 'de'],
            ['project' => 'phpunit-second', 'lang' => 'en']
        ], $Builder->addedEntries);
    }
}
