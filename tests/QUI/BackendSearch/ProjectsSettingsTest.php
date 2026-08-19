<?php

namespace QUITests\BackendSearch;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\BackendSearch\Provider\Projects;
use QUI\Cache\Manager as CacheManager;
use QUI\Interfaces\Template\EngineInterface;
use QUI\Locale;
use QUI\Projects\Project;
use QUI\Template;

class ProjectsSettingsTest extends TestCase
{
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
}
