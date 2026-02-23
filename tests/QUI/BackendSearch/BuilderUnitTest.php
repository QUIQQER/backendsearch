<?php

namespace QUITests\BackendSearch;

use PHPUnit\Framework\TestCase;
use QUI\BackendSearch\Builder;
use QUI\BackendSearch\Exception;
use QUI\BackendSearch\ProviderInterface;

class BuilderUnitTest extends TestCase
{
    public function testGetLocalesReturnsPresetCacheWithoutTranslatorCall(): void
    {
        $Builder = new TestableBuilder();
        $Locale = $this->createMock(\QUI\Locale::class);

        $Builder->setLocalesCache(['en' => $Locale]);

        $result = $Builder->getLocales();

        $this->assertSame(['en' => $Locale], $result);
    }

    public function testGetMenuDataReturnsPresetMenuWithoutWorkspaceAccess(): void
    {
        $Builder = new TestableBuilder();
        $menu = [
            ['name' => 'apps', 'text' => 'Apps']
        ];
        $Builder->setMenuCache($menu);

        $this->assertSame($menu, $Builder->getMenuData());
    }

    public function testGetProviderReturnsOnlyProviderImplementations(): void
    {
        $Builder = new class extends Builder {
            /** @var array<int,string> */
            public array $providerClasses = [];

            /**
             * @return array<int,string>
             */
            protected function getProviderClasses(): array
            {
                return $this->providerClasses;
            }
        };

        $Builder->providerClasses = [
            'NonExistent\\Provider\\ClassName',
            DummyProvider::class,
            DummyNonProvider::class
        ];

        $providers = $Builder->getProvider();

        $this->assertCount(1, $providers);
        $this->assertInstanceOf(ProviderInterface::class, $providers[0]);
        $this->assertInstanceOf(DummyProvider::class, $providers[0]);
    }

    public function testGetProviderReturnsSpecificProviderByClassName(): void
    {
        $Builder = new class extends Builder {
            /** @var array<int,string> */
            public array $providerClasses = [DummyProvider::class];

            /**
             * @return array<int,string>
             */
            protected function getProviderClasses(): array
            {
                return $this->providerClasses;
            }
        };

        $Provider = $Builder->getProvider(DummyProvider::class);

        $this->assertInstanceOf(DummyProvider::class, $Provider);
    }

    public function testGetProviderThrowsIfSpecificProviderDoesNotExist(): void
    {
        $Builder = new class extends Builder {
            /**
             * @return array<int,string>
             */
            protected function getProviderClasses(): array
            {
                return [DummyNonProvider::class];
            }
        };

        $this->expectException(Exception::class);
        $Builder->getProvider(DummyProvider::class);
    }

    public function testGetProviderThrowsIfSpecificClassExistsButIsNotProvider(): void
    {
        $Builder = new class extends Builder {
            /**
             * @return array<int,string>
             */
            protected function getProviderClasses(): array
            {
                return [DummyNonProvider::class];
            }
        };

        $this->expectException(Exception::class);
        $Builder->getProvider(DummyNonProvider::class);
    }

    public function testGetWhereConstraintReturnsDefaultConstraintsForEmptyFilters(): void
    {
        $Builder = new Builder();

        $constraint = $Builder->getWhereConstraint([]);

        $this->assertCount(2, $constraint);
        $this->assertStringContainsString("`group` != 'apps'", $constraint[0]);
        $this->assertStringContainsString("`group` != 'extras'", $constraint[1]);
    }

    public function testAddEntryThrowsExceptionWhenRequiredParamsAreMissing(): void
    {
        $Builder = new Builder();

        $this->expectException(Exception::class);
        $Builder->addEntry([], 'en');
    }

    public function testParseMenuDataParsesNestedItemsAndLocaleLabels(): void
    {
        $Builder = new TestableBuilder();
        $Locale = $this->createMock(\QUI\Locale::class);

        $Locale->method('exists')->willReturnCallback(
            static function (string $group, string $var): bool {
                return $group === 'pkg/example' && $var === 'menu.text';
            }
        );

        $Locale->method('get')->willReturnCallback(
            static function (string $group, string $var): string {
                if ($group === 'pkg/example' && $var === 'menu.text') {
                    return 'Translated Parent';
                }

                return $group . ':' . $var;
            }
        );

        $items = [
            [
                'name' => 'parent',
                'text' => ['Parent', 'Node'],
                'icon' => ['fa', 'fa-cube'],
                'locale' => ['pkg/example', 'menu.text'],
                'require' => 'controls/example',
                'items' => [
                    [
                        'name' => 'child',
                        'text' => 'Child Node',
                        'type' => 'panel'
                    ]
                ]
            ]
        ];

        $data = $Builder->parseMenuDataPublic($items, $Locale);

        $this->assertCount(2, $data);
        $this->assertSame('parent', $data[0]['name']);
        $this->assertSame('Translated Parent', $data[0]['title']);
        $this->assertSame('fa fa-cube', $data[0]['icon']);
        $this->assertSame('child', $data[1]['name']);
        $this->assertSame('Translated Parent -> Child Node', $data[1]['description']);

        $parentSearchData = json_decode((string)$data[0]['searchdata'], true);
        $this->assertSame('controls/example', $parentSearchData['require']);
    }

    public function testParseMenuDataSkipsEntriesWithoutTitle(): void
    {
        $Builder = new TestableBuilder();
        $Locale = $this->createMock(\QUI\Locale::class);

        $items = [
            [
                'name' => 'empty',
                'text' => ''
            ],
            [
                'name' => 'valid',
                'text' => 'Visible'
            ]
        ];

        $data = $Builder->parseMenuDataPublic($items, $Locale);

        $this->assertCount(1, $data);
        $this->assertSame('valid', $data[0]['name']);
    }

    public function testToStringValueConvertsArrayPartsToString(): void
    {
        $Builder = new TestableBuilder();

        $this->assertSame('a b 1', $Builder->toStringValuePublic(['a', 'b', 1]));
        $this->assertSame('abc', $Builder->toStringValuePublic('abc'));
    }

    public function testGetLocalesCreatesLocaleMapFromAvailableLanguages(): void
    {
        $Builder = new TestableBuilder();
        $Builder->availableLanguages = ['de', 'en'];

        $locales = $Builder->getLocales();

        $this->assertArrayHasKey('de', $locales);
        $this->assertArrayHasKey('en', $locales);
        $this->assertInstanceOf(\QUI\Locale::class, $locales['de']);
    }

    public function testGetProfileSearchTermsParsesTableHeadersAndLabels(): void
    {
        $Builder = new TestableBuilder();
        $Builder->profileTemplate = <<<HTML
<html>
<body>
  <table>
    <thead>
      <tr><th>Header One</th><th>Header Two</th></tr>
    </thead>
  </table>
  <label><span>Label One</span></label>
</body>
</html>
HTML;

        $terms = $Builder->getProfileSearchtermsPublic();

        $this->assertContains('Header One', $terms);
        $this->assertContains('Header Two', $terms);
        $this->assertContains('Label One', $terms);
    }
}

class DummyNonProvider
{
}

class DummyProvider implements ProviderInterface
{
    public function buildCache(): void
    {
    }

    public function search(string $search, array $params = []): array
    {
        return [];
    }

    public function getEntry(string | int $id): mixed
    {
        return [
            'searchdata' => [
                'id' => $id
            ]
        ];
    }

    public function getFilterGroups(): array
    {
        return [];
    }
}

class TestableBuilder extends Builder
{
    /** @var array<int,string>|mixed */
    public mixed $availableLanguages = [];

    public string $profileTemplate = '';

    /**
     * @param array<string,\QUI\Locale> $locales
     */
    public function setLocalesCache(array $locales): void
    {
        $this->locales = $locales;
    }

    /**
     * @param array<int,array<string,mixed>> $menu
     */
    public function setMenuCache(array $menu): void
    {
        $this->menu = $menu;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    public function parseMenuDataPublic(array $items, \QUI\Locale $Locale): array
    {
        return $this->parseMenuData($items, $Locale);
    }

    /**
     * @param array<mixed>|string $value
     */
    public function toStringValuePublic(array | string $value): string
    {
        return $this->toStringValue($value);
    }

    /**
     * @return mixed
     */
    protected function getAvailableLanguages(): mixed
    {
        return $this->availableLanguages;
    }

    protected function getUserProfileTemplate(): string
    {
        return $this->profileTemplate;
    }

    /**
     * @return array<int,string>
     */
    public function getProfileSearchtermsPublic(): array
    {
        return $this->getProfileSearchterms();
    }
}
