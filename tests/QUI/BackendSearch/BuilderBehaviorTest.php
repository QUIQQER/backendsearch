<?php

namespace QUITests\BackendSearch;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\BackendSearch\Builder;
use QUI\BackendSearch\ProviderInterface;
use QUI\Cache\Manager as CacheManager;
use QUI\Locale;
use Throwable;

class BuilderBehaviorTest extends TestCase
{
    public function testMenuCacheBuildersCreateLocalizedEntriesWithDefaultIcons(): void
    {
        $Locale = $this->createLocale('en');
        $menu = [
            [
                'name' => Builder::TYPE_APPS,
                'text' => 'Applications',
                'require' => 'controls/apps/Root',
                'items' => [
                    [
                        'name' => 'app-child',
                        'text' => 'Application child',
                        'require' => 'controls/apps/Child'
                    ],
                    [
                        'name' => 'app-without-require',
                        'text' => 'Not actionable'
                    ]
                ]
            ],
            [
                'name' => Builder::TYPE_EXTRAS,
                'text' => 'Extras',
                'icon' => 'fa fa-custom',
                'require' => 'controls/extras/Root'
            ]
        ];

        $Builder = new class ([$Locale], $menu) extends Builder {
            /** @var array<int, array{entry: array<string, mixed>, lang: string}> */
            public array $addedEntries = [];
            /** @var array<int, string> */
            public array $deletedGroups = [];

            /**
             * @param array<int, Locale> $testLocales
             * @param array<int, array<string, mixed>> $testMenu
             */
            public function __construct(private array $testLocales, private array $testMenu)
            {
            }

            public function getLocales(): array
            {
                return $this->testLocales;
            }

            public function getMenuData(): array
            {
                return $this->testMenu;
            }

            public function addEntry(array $params, string $lang): void
            {
                $this->addedEntries[] = ['entry' => $params, 'lang' => $lang];
            }

            protected function deleteEntriesByGroup(string $group): void
            {
                $this->deletedGroups[] = $group;
            }
        };

        $Builder->buildAppsCache();
        $Builder->buildExtrasCache();

        self::assertSame([Builder::TYPE_APPS, Builder::TYPE_EXTRAS], $Builder->deletedGroups);
        self::assertCount(3, $Builder->addedEntries);
        self::assertSame(
            [Builder::TYPE_APPS, Builder::TYPE_APPS, Builder::TYPE_EXTRAS],
            array_column(array_column($Builder->addedEntries, 'entry'), 'group')
        );
        self::assertSame(Builder::TYPE_APPS_ICON, $Builder->addedEntries[0]['entry']['icon']);
        self::assertSame(Builder::TYPE_APPS_ICON, $Builder->addedEntries[1]['entry']['icon']);
        self::assertSame('fa fa-custom', $Builder->addedEntries[2]['entry']['icon']);
        self::assertSame(['en', 'en', 'en'], array_column($Builder->addedEntries, 'lang'));
    }

    public function testProfileCacheAddsDynamicTermsAndRestoresGlobalLocale(): void
    {
        $originalLocale = QUI::$Locale;
        $globalLocale = new Locale();
        $globalLocale->setCurrent('fr');
        QUI::$Locale = $globalLocale;

        $german = $this->createLocale('de');
        $english = $this->createLocale('en');
        $menu = [[
            'name' => Builder::TYPE_PROFILE,
            'text' => 'Profile',
            'items' => [
                [
                    'name' => 'userProfile',
                    'text' => 'User profile',
                    'require' => 'controls/profile/User'
                ],
                [
                    'name' => 'profile-action',
                    'text' => 'Profile action',
                    'require' => 'controls/profile/Action'
                ],
                [
                    'name' => 'profile-heading',
                    'text' => 'Profile heading'
                ]
            ]
        ]];

        $Builder = new class ([$german, $english], $menu) extends Builder {
            /** @var array<int, array{entry: array<string, mixed>, lang: string}> */
            public array $addedEntries = [];
            /** @var array<int, string> */
            public array $deletedGroups = [];

            /**
             * @param array<int, Locale> $testLocales
             * @param array<int, array<string, mixed>> $testMenu
             */
            public function __construct(private array $testLocales, private array $testMenu)
            {
            }

            public function getLocales(): array
            {
                return $this->testLocales;
            }

            public function getMenuData(): array
            {
                return $this->testMenu;
            }

            public function addEntry(array $params, string $lang): void
            {
                $this->addedEntries[] = ['entry' => $params, 'lang' => $lang];
            }

            protected function deleteEntriesByGroup(string $group): void
            {
                $this->deletedGroups[] = $group;
            }

            protected function getProfileSearchterms(): array
            {
                return ['Email address', 'Company'];
            }
        };

        try {
            $Builder->buildProfileCache();
        } finally {
            QUI::$Locale = $originalLocale;
        }

        self::assertSame([Builder::TYPE_PROFILE], $Builder->deletedGroups);
        self::assertCount(4, $Builder->addedEntries);
        self::assertSame(['de', 'de', 'en', 'en'], array_column($Builder->addedEntries, 'lang'));
        self::assertStringContainsString('Email address Company', $Builder->addedEntries[0]['entry']['search']);
        self::assertSame(Builder::TYPE_PROFILE_ICON, $Builder->addedEntries[1]['entry']['icon']);
        self::assertSame('fr', $globalLocale->getCurrent());
    }

    public function testFilterGroupsMergeProviderGroupsAndCacheResult(): void
    {
        $cacheName = 'quiqqer/desktopsearch/filtergroups';
        $hadCachedValue = false;
        $cachedValue = null;

        try {
            $cachedValue = CacheManager::get($cacheName);
            $hadCachedValue = true;
        } catch (Throwable) {
        }

        CacheManager::clear($cacheName);

        $Provider = new class implements ProviderInterface {
            public function buildCache(): void
            {
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
                return [
                    ['group' => 'settings_content', 'label' => ['pkg', 'settings']],
                    ['group' => 'custom', 'label' => ['pkg', 'custom']]
                ];
            }
        };
        $Builder = new class ($Provider) extends Builder {
            public function __construct(private ProviderInterface $provider)
            {
            }

            public function getProvider(bool | string $provider = false): ProviderInterface | array
            {
                return $this->provider;
            }
        };

        try {
            $groups = $Builder->getFilterGroups();
            $cachedGroups = $Builder->getFilterGroups();
        } finally {
            CacheManager::clear($cacheName);

            if ($hadCachedValue) {
                CacheManager::set($cacheName, $cachedValue);
            }
        }

        self::assertSame(['navigation', 'settings_content', 'custom'], array_column($groups, 'group'));
        self::assertSame($groups, $cachedGroups);
    }

    private function createLocale(string $language): Locale
    {
        $Locale = $this->createMock(Locale::class);
        $Locale->method('getCurrent')->willReturn($language);
        $Locale->method('get')->willReturnCallback(
            static function (string $group, string $variable, mixed $params = false) use ($language): string {
                if (is_array($params) && isset($params['type'])) {
                    return $language . ':' . $params['type'];
                }

                return $language . ':' . $variable;
            }
        );

        return $Locale;
    }
}
