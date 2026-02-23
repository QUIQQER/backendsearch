<?php

namespace QUITests\BackendSearch;

use PHPUnit\Framework\TestCase;
use QUI\BackendSearch\Events;
use QUI\BackendSearch\Provider\Projects;
use QUI\BackendSearch\Provider\SettingsCategories;
use QUI\BackendSearch\Search;
use QUI\Package\Package;

class ModuleBehaviorTest extends TestCase
{
    public function testSearchSingletonReturnsSameInstance(): void
    {
        $first = Search::getInstance();
        $second = Search::getInstance();

        $this->assertSame($first, $second);
    }

    public function testOnAdminLoadFooterPrintsExpectedScriptTag(): void
    {
        if (!defined('URL_OPT_DIR')) {
            define('URL_OPT_DIR', '/opt/');
        }

        ob_start();
        Events::onAdminLoadFooter();
        $output = (string)ob_get_clean();

        $this->assertStringContainsString('quiqqer/backendsearch/bin/onAdminLoadFooter.js', $output);
        $this->assertStringContainsString('<script', $output);
    }

    public function testOnPackageSetupReturnsEarlyForDifferentPackageName(): void
    {
        $Package = $this->createMock(Package::class);
        $Package->method('getName')->willReturn('quiqqer/something-else');

        Events::onPackageSetup($Package);

        $this->assertTrue(true);
    }

    public function testProjectsProviderDefaultMethods(): void
    {
        $Provider = new Projects();

        $this->assertSame([], $Provider->search('foo', []));
        $this->assertNull($Provider->getEntry('x'));
        $this->assertSame([], $Provider->getFilterGroups());
    }

    public function testSettingsCategoriesDefaultMethods(): void
    {
        $Provider = new TestableSettingsCategories();

        $this->assertSame([], $Provider->search('foo', []));
        $this->assertNull($Provider->getEntry('x'));
    }

    public function testSettingsCategoriesParseSettingsMenuDataWithLocaleAndChildren(): void
    {
        $Provider = new TestableSettingsCategories();
        $Locale = $this->createMock(\QUI\Locale::class);

        $Locale->method('get')->willReturnCallback(
            static function (string $group, string $var): string {
                if ($group === 'pkg/demo' && $var === 'menu') {
                    return 'Localized Parent';
                }

                if ($group === 'quiqqer/system' && $var === 'settings') {
                    return 'Settings';
                }

                return $group . ':' . $var;
            }
        );

        $items = [
            [
                'text' => ['Parent', 'Settings'],
                'name' => 'parent',
                'icon' => ['fa', 'fa-cog'],
                'require' => 'controls/demo',
                'locale' => ['pkg/demo', 'menu'],
                'items' => [
                    [
                        'text' => 'Child Item',
                        'name' => 'child',
                        'onClick' => 'return false;'
                    ]
                ]
            ]
        ];

        $data = $Provider->parseSettingsMenuDataPublic($items, $Locale);

        $this->assertCount(2, $data);
        $this->assertSame('Localized Parent', $data[0]['title']);
        $this->assertSame(['fa', 'fa-cog'], $data[0]['icon']);
        $this->assertSame('Child Item', $data[1]['title']);
        $this->assertSame('Child Item', $data[1]['search']);
    }

    public function testSettingsCategoriesParseSearchDataFromSettingsXmlItem(): void
    {
        $Provider = new TestableSettingsCategories();
        $Locale = $this->createMock(\QUI\Locale::class);

        $Locale->method('get')->willReturnCallback(
            static function (string $group, string $var): string {
                if ($group === 'quiqqer/system' && $var === 'settings') {
                    return 'Settings';
                }

                return $group . ':' . $var;
            }
        );

        $xmlFile = sys_get_temp_dir() . '/backendsearch-settings-' . uniqid('', true) . '.xml';
        $xml = <<<XML
<?xml version="1.0"?>
<settings>
  <window>
    <categories>
      <category name="general">
        <title>General</title>
        <settings>
          <setting>
            <title>Option Title</title>
            <description>Option Description</description>
            <input>
              <title>Input Title</title>
            </input>
          </setting>
        </settings>
      </category>
    </categories>
  </window>
</settings>
XML;

        file_put_contents($xmlFile, $xml);

        try {
            $item = [
                'text' => ['Module', 'Settings'],
                'description' => ['Top', 'Entry'],
                'icon' => ['fa', 'fa-gears'],
                'qui-xml-file' => $xmlFile
            ];

            $data = $Provider->parseSearchDataFromSettingsXmlItemPublic($item, $Locale);

            $this->assertNotEmpty($data);
            $this->assertSame('Module Settings', $data[0]['title']);
            $this->assertSame('Top Entry', $data[0]['description']);
            $this->assertSame('fa fa-gears', $data[0]['icon']);
            $this->assertSame(SettingsCategories::TYPE_SETTINGS, $data[0]['group']);

            $this->assertCount(2, $data);
            $this->assertSame(SettingsCategories::TYPE_SETTINGS_CONTENT, $data[1]['group']);
            $this->assertStringContainsString('General', (string)$data[1]['title']);
            $this->assertStringContainsString('Option Title', (string)$data[1]['search']);
        } finally {
            if (file_exists($xmlFile)) {
                unlink($xmlFile);
            }
        }
    }

    public function testSettingsCategoriesParseSearchDataHandlesInvalidXmlFileEntries(): void
    {
        $Provider = new TestableSettingsCategories();
        $Locale = $this->createMock(\QUI\Locale::class);
        $Locale->method('get')->willReturn('Settings');

        $xmlFile = sys_get_temp_dir() . '/backendsearch-settings-valid-' . uniqid('', true) . '.xml';
        file_put_contents($xmlFile, '<?xml version="1.0"?><settings><window><categories></categories></window></settings>');

        try {
            $data = $Provider->parseSearchDataFromSettingsXmlItemPublic([
                'text' => 'Settings Root',
                'description' => 'Root Desc',
                'qui-xml-file' => [
                    ['invalid-array-entry'],
                    '/this/file/does/not/exist.xml',
                    $xmlFile
                ]
            ], $Locale);

            $this->assertCount(1, $data);
            $this->assertSame('Settings Root', $data[0]['title']);
        } finally {
            if (file_exists($xmlFile)) {
                unlink($xmlFile);
            }
        }
    }
}

class TestableSettingsCategories extends SettingsCategories
{
    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    public function parseSettingsMenuDataPublic(array $items, \QUI\Locale $Locale): array
    {
        return $this->parseSettingsMenuData($items, $Locale);
    }

    /**
     * @param array<string,mixed> $item
     * @return array<int,array<string,mixed>>
     */
    public function parseSearchDataFromSettingsXmlItemPublic(array $item, \QUI\Locale $Locale): array
    {
        return $this->parseSearchDataFromSettingsXmlItem($item, $Locale);
    }
}
