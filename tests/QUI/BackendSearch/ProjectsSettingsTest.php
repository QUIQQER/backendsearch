<?php

namespace QUITests\BackendSearch;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\BackendSearch\Builder;
use QUI\BackendSearch\Provider\Projects;
use QUI\Cache\Manager as CacheManager;
use QUI\Config;
use QUI\Interfaces\Template\EngineInterface;
use QUI\Locale;
use QUI\LocaleRuntimeCache;
use QUI\Projects\Project;
use QUI\Template;
use ReflectionProperty;
use Stash\Driver\Ephemeral;
use Stash\Pool;

class ProjectsSettingsTest extends TestCase
{
    private ?Config $originalCacheConfig;
    private ?Pool $originalStash;
    private ?Builder $originalBuilder;

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $originalTranslations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalCacheConfig = CacheManager::$Config;
        $this->originalStash = CacheManager::$Stash;
        $this->originalBuilder = (new ReflectionProperty(Builder::class, 'Instance'))->getValue();
        $this->originalTranslations = (new ReflectionProperty(LocaleRuntimeCache::class, 'languages'))->getValue();

        CacheManager::$Config = $this->createMock(Config::class);
        CacheManager::$Stash = new Pool(new Ephemeral());
    }

    protected function tearDown(): void
    {
        CacheManager::$Config = $this->originalCacheConfig;
        CacheManager::$Stash = $this->originalStash;
        (new ReflectionProperty(Builder::class, 'Instance'))->setValue(null, $this->originalBuilder);
        (new ReflectionProperty(LocaleRuntimeCache::class, 'languages'))->setValue(null, $this->originalTranslations);

        parent::tearDown();
    }

    public function testProjectSettingsCombineTemplatesAndRelatedXmlCategories(): void
    {
        $xmlFile = sys_get_temp_dir() . '/backendsearch-project-settings-' . bin2hex(random_bytes(8)) . '.xml';
        $cachePath = 'phpunit/backendsearch/project-settings-' . bin2hex(random_bytes(8));
        $cacheKey = $cachePath . '/relatedSettingsXml';
        $originalTemplate = QUI::$Template;

        file_put_contents($xmlFile, <<<'XML'
<?xml version="1.0"?>
<settings>
  <window>
    <categories>
      <category name="seo">
        <title>SEO category</title>
        <icon>fa fa-search</icon>
        <settings>
          <setting>
            <title>Meta title</title>
            <description>Meta description</description>
            <input>
              <title>Meta input title</title>
            </input>
          </setting>
        </settings>
      </category>
    </categories>
  </window>
</settings>
XML);

        $Engine = $this->createMock(EngineInterface::class);
        $Engine->expects(self::once())
            ->method('assign')
            ->with(self::callback(static function (array $params): bool {
                return isset($params['QUI'], $params['Project']);
            }));
        $Engine->expects(self::exactly(3))
            ->method('fetch')
            ->willReturnCallback(static function (string $template): string {
                $templateName = basename($template, '.html');

                return '<html><body><table><thead><tr><th>'
                    . $templateName
                    . ' header</th></tr></thead></table><label>'
                    . $templateName
                    . ' label</label></body></html>';
            });

        $Template = $this->createMock(Template::class);
        $Template->method('getEngine')->with(true)->willReturn($Engine);

        $Project = $this->createMock(Project::class);
        $Project->method('getName')->willReturn('phpunit-project');
        $Project->method('getCachePath')->willReturn($cachePath);

        $Locale = $this->createMock(Locale::class);
        $Locale->method('get')->willReturnCallback(
            static function (string $group, string $variable, mixed $params = false): string {
                if (is_array($params) && isset($params['project'])) {
                    return $variable . ':' . $params['project'];
                }

                return $variable;
            }
        );

        $Provider = new class extends Projects {
            /** @return array<int, array<string, mixed>> */
            public function getProjectSettingsSearchTermsPublic(Project $Project, Locale $Locale): array
            {
                return $this->getProjectSettingsSearchTerms($Project, $Locale);
            }
        };

        try {
            QUI::$Template = $Template;
            CacheManager::set($cacheKey, [$xmlFile]);

            $entries = $Provider->getProjectSettingsSearchTermsPublic($Project, $Locale);
        } finally {
            CacheManager::clear($cacheKey);
            QUI::$Template = $originalTemplate;

            if (file_exists($xmlFile)) {
                unlink($xmlFile);
            }
        }

        self::assertCount(4, $entries);
        self::assertSame(
            ['settings', 'adminSettings', 'mediaSettings'],
            array_map(
                static fn (array $entry): string => $entry['searchdata']['params']['category'],
                array_slice($entries, 0, 3)
            )
        );
        self::assertSame('settings header settings label', $entries[0]['search']);
        self::assertSame('settingsAdmin header settingsAdmin label', $entries[1]['search']);
        self::assertSame('settingsMedia header settingsMedia label', $entries[2]['search']);
        self::assertSame('SEO category', $entries[3]['title']);
        self::assertSame('fa fa-search', $entries[3]['icon']);
        self::assertSame('seo', $entries[3]['searchdata']['params']['category']);
        self::assertStringContainsString('Meta title', $entries[3]['search']);
        self::assertStringContainsString('Meta description', $entries[3]['search']);
        self::assertStringContainsString('Meta input title', $entries[3]['search']);
    }

    /** @param array<string, string|null> $translations */
    #[DataProvider('titleFallbacks')]
    public function testSettingsTitlesUseTranslationsBeforeFallingBackToTheKey(
        array $translations,
        string $expectedTitle
    ): void {
        $keys = [
            'projects.project.panel.settings.btn.settings',
            'projects.project.panel.settings.btn.adminSettings',
            'projects.project.panel.settings.btn.media'
        ];
        LocaleRuntimeCache::clear();
        $locales = [];

        foreach ($translations as $language => $text) {
            $Locale = new Locale();
            $Locale->setCurrent($language);
            $locales[$language] = $Locale;
            $values = [];

            foreach ($keys as $key) {
                if ($text !== null) {
                    $values[$key] = $text === 'placeholder' ? '[quiqqer/core] ' . $key : $text;
                }
            }

            LocaleRuntimeCache::set($language, 'quiqqer/core', $values);
        }

        $Builder = $this->createMock(Builder::class);
        $Builder->method('getLocales')->willReturn($locales);
        (new ReflectionProperty(Builder::class, 'Instance'))->setValue(null, $Builder);

        $Engine = $this->createMock(EngineInterface::class);
        $Engine->method('fetch')->willReturn('<html><body><label>Setting label</label></body></html>');
        $Template = $this->createMock(Template::class);
        $Template->method('getEngine')->with(true)->willReturn($Engine);

        $cachePath = 'phpunit/backendsearch/title-fallback-' . bin2hex(random_bytes(8));
        $Project = $this->createMock(Project::class);
        $Project->method('getName')->willReturn('phpunit-project');
        $Project->method('getCachePath')->willReturn($cachePath);
        $Provider = new class extends Projects {
            /** @return array<int, array<string, mixed>> */
            public function getSettings(Project $Project, Locale $Locale): array
            {
                return $this->getProjectSettingsSearchTerms($Project, $Locale);
            }
        };

        $xmlFile = sys_get_temp_dir() . '/backendsearch-title-fallback-' . bin2hex(random_bytes(8)) . '.xml';
        file_put_contents($xmlFile, <<<'XML'
<?xml version="1.0"?>
<quiqqer>
  <project>
    <settings>
      <window>
        <categories>
          <category name="translated">
            <title>
              <locale group="quiqqer/core" var="projects.project.panel.settings.btn.settings"/>
            </title>
          </category>
          <category name="empty-key">
            <title>
              <locale group="quiqqer/core" var=""/>
            </title>
          </category>
        </categories>
      </window>
    </settings>
  </project>
</quiqqer>
XML);

        $originalTemplate = QUI::$Template;

        try {
            QUI::$Template = $Template;
            CacheManager::set($cachePath . '/relatedSettingsXml', [$xmlFile]);
            $entries = $Provider->getSettings($Project, $locales['tr']);
        } finally {
            QUI::$Template = $originalTemplate;
            unlink($xmlFile);
        }

        self::assertCount(5, $entries);
        self::assertSame('', $entries[4]['title']);

        foreach (array_slice($entries, 0, 4) as $index => $entry) {
            $key = $keys[$index] ?? $keys[0];
            self::assertSame($expectedTitle === 'key' ? $key : $expectedTitle, $entry['title']);
        }

        foreach ($locales as $language => $Locale) {
            self::assertSame($language, $Locale->getCurrent());
        }
    }

    /** @return array<string, array{array<string, string|null>, string}> */
    public static function titleFallbacks(): array
    {
        return [
            'requested language takes precedence' => [
                [
                    'de' => 'Deutsch',
                    'en' => 'English',
                    'tr' => 'Türkçe',
                    'fr' => 'Français'
                ],
                'Türkçe'
            ],
            'empty requested translation uses English before the first language' => [
                [
                    'de' => 'Deutsch',
                    'en' => 'English',
                    'tr' => '',
                    'fr' => 'Français'
                ],
                'English'
            ],
            'missing requested translation uses English' => [
                [
                    'de' => 'Deutsch',
                    'en' => 'English',
                    'tr' => null,
                    'fr' => 'Français'
                ],
                'English'
            ],
            'placeholder is not a translated title' => [
                [
                    'de' => 'Deutsch',
                    'en' => 'English',
                    'tr' => 'placeholder',
                    'fr' => 'Français'
                ],
                'English'
            ],
            'empty English translation uses the first translated language' => [
                [
                    'de' => 'Deutsch',
                    'en' => '',
                    'tr' => '',
                    'fr' => 'Français'
                ],
                'Deutsch'
            ],
            'missing English translation skips empty fallback languages' => [
                [
                    'de' => '',
                    'en' => null,
                    'tr' => '',
                    'fr' => 'Français'
                ],
                'Français'
            ],
            'whitespace and zero cannot satisfy the cache validator' => [
                [
                    'de' => '0',
                    'en' => '   ',
                    'tr' => "\t",
                    'fr' => 'Français'
                ],
                'Français'
            ],
            'all empty translations fall back to the key' => [
                [
                    'de' => '',
                    'en' => '',
                    'tr' => '',
                    'fr' => ''
                ],
                'key'
            ],
            'all missing translations fall back to the key' => [
                [
                    'de' => null,
                    'en' => null,
                    'tr' => null,
                    'fr' => null
                ],
                'key'
            ]
        ];
    }
}
