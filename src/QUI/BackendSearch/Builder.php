<?php

/**
 * This file contains QUI\BackendSearch\Builder
 */

namespace QUI\BackendSearch;

use DOMDocument;
use DOMNode;
use DOMXPath;
use ForceUTF8\Encoding;
use QUI;
use QUI\Cache\Manager as CacheManager;
use QUI\Database\Exception;
use QUI\Permissions\Permission;
use QUI\Utils\DOM as DOMUtils;

/**
 * Class Builder
 * Building the Search DB
 *
 * @package QUI\Workspace
 */
class Builder
{
    const TYPE_APPS = 'apps';
    const TYPE_EXTRAS = 'extras';
    const TYPE_PROJECT = 'project';
    const TYPE_PROFILE = 'profile';

    const FILTER_NAVIGATION = 'navigation';
    const FILTER_SETTINGS = 'settings';

    const TYPE_APPS_ICON = 'fa fa-diamond';
    const TYPE_EXTRAS_ICON = 'fa fa-cubes';
    const TYPE_PROJECT_ICON = 'fa fa-home';
    const TYPE_PROFILE_ICON = 'fa fa-id-card-o';

    /**
     * @var Builder|null
     */
    protected static ?Builder $Instance = null;

    /**
     * @var array|null
     */
    protected ?array $menu = null;

    /**
     * list of locales
     *
     * @var array|null
     */
    protected ?array $locales = null;

    /**
     * @var string
     */
    protected string $table = 'quiqqerBackendSearch';

    /**
     * Return the global instance
     *
     * @return Builder
     */
    public static function getInstance(): Builder
    {
        if (is_null(self::$Instance)) {
            self::$Instance = new self();
        }

        return self::$Instance;
    }

    /**
     * Return the database table name
     *
     * @return string
     */
    public function getTable(): string
    {
        return QUI::getDBTableName($this->table);
    }

    /**
     * Returns all available locales
     */
    public function getLocales(): ?array
    {
        if (!is_null($this->locales)) {
            return $this->locales;
        }

        $available = QUI\Translator::getAvailableLanguages();
        $this->locales = [];

        foreach ($available as $lang) {
            $this->locales[$lang] = new QUI\Locale();
            $this->locales[$lang]->setCurrent($lang);
        }

        return $this->locales;
    }

    /**
     * Return the complete available list of all providers classes
     *
     * @return array
     */
    protected function getProviderClasses(): array
    {
        $cache = 'quiqqer/backendsearch/providers';

        try {
            return QUI\Cache\Manager::get($cache);
        } catch (QUI\Cache\Exception) {
        }

        $packages = QUI::getPackageManager()->getInstalled();
        $provider = [];

        foreach ($packages as $package) {
            try {
                $Package = QUI::getPackage($package['name']);

                if (!$Package->isQuiqqerPackage()) {
                    continue;
                }

                $packageProvider = $Package->getProvider();

                if (isset($packageProvider['desktopSearch'])) {
                    $provider = array_merge($provider, $packageProvider['desktopSearch']);
                }
            } catch (QUI\Exception $Exception) {
                QUI\System\Log::writeException($Exception);
            }
        }

        QUI\Cache\Manager::set($cache, $provider);

        return $provider;
    }

    /**
     * Get all groups the search results can be grouped by
     *
     * @return array
     * @throws \QUI\BackendSearch\Exception
     */
    public function getFilterGroups(): array
    {
        $cacheName = 'quiqqer/desktopsearch/filtergroups';

        try {
            return json_decode(CacheManager::get($cacheName), true);
        } catch (\Exception) {
            // nothing, retrieve filter groups freshly
        }

        $providers = $this->getProvider();
        $groups = [
            [
                'group' => self::FILTER_NAVIGATION,
                'label' => [
                    'quiqqer/backendsearch',
                    'search.builder.filter.label.' . self::FILTER_NAVIGATION
                ]
            ]
        ];

        /** @var ProviderInterface $Provider */
        foreach ($providers as $Provider) {
            $providerGroups = $Provider->getFilterGroups();

            foreach ($providerGroups as $group) {
                if (!isset($groups[$group['group']])) {
                    $groups[$group['group']] = $group;
                }
            }
        }

        $groups = array_values($groups);

        CacheManager::set($cacheName, json_encode($groups));

        return $groups;
    }

    /**
     * Return the available provider instances
     *
     * @param bool|string $provider - optional, Return a specific provider
     * @return array|ProviderInterface
     *
     * @throws QUI\BackendSearch\Exception
     */
    public function getProvider(bool|string $provider = false): ProviderInterface|array
    {
        $result = [];

        foreach ($this->getProviderClasses() as $cls) {
            if (!class_exists($cls)) {
                continue;
            }

            try {
                $Instance = new $cls();

                if ($Instance instanceof ProviderInterface) {
                    $result[] = $Instance;
                }

                if ($provider && get_class($Instance) == $provider) {
                    return $Instance;
                }
            } catch (\Exception $Exception) {
                QUI\System\Log::writeException(
                    $Exception,
                    QUI\System\Log::LEVEL_ERROR,
                    [
                        'method' => 'QUI\Workspace\Search::getProviderInstances'
                    ]
                );
            }
        }

        if ($provider) {
            throw new QUI\BackendSearch\Exception('Provider not found', 404);
        }

        return $result;
    }

    /**
     * Return the menu data, entries of the admin menu
     *
     * @return array
     */
    public function getMenuData(): array
    {
        if (is_null($this->menu)) {
            $Menu = new QUI\Workspace\Menu();
            $menu = $Menu->createMenu();

            $this->menu = $menu;
        }

        return $this->menu;
    }

    /**
     * Executed at the QUIQQER Setup
     */
    public function setup(): void
    {
        QUI\Cache\Manager::clear('quiqqer/backendsearch/providers');

        $this->buildCache();
    }

    /**
     * Build the complete search cache and clears the cache
     */
    public function buildCache(): void
    {
        QUI::getDataBase()->table()->truncate($this->getTable());

        // apps
        try {
            $this->buildAppsCache();
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }

        // extras
        try {
            $this->buildExtrasCache();
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }

        // profile
        try {
            $this->buildProfileCache();
        } catch (Exception $Exception) {
            QUI\System\Log::writeException($Exception);
        }

        $provider = $this->getProvider();

        /* @var $Provider ProviderInterface */
        foreach ($provider as $Provider) {
            try {
                $Provider->buildCache();
            } catch (\Exception $Exception) {
                QUI\System\Log::addWarning(
                    self::class . ' :: buildCache() -> An error ocurred while building the search'
                    . ' cache for provider ' . get_class($Provider) . ' :: ' . $Exception->getMessage()
                );
            }
        }
    }

    /**
     * Build the cache for the apps search
     */
    public function buildAppsCache(): void
    {
        $this->buildMenuCacheHelper(self::TYPE_APPS);
    }

    /**
     * Build the cache for the extras search
     */
    public function buildExtrasCache(): void
    {
        $this->buildMenuCacheHelper(self::TYPE_EXTRAS);
    }

    /**
     * Build the cache for the profile search
     */
    public function buildProfileCache(): void
    {
        QUI::getDataBase()->delete($this->getTable(), [
            'group' => self::TYPE_PROFILE
        ]);

        $locales = $this->getLocales();
        $QUILocale = QUI::getLocale();
        $quiLocaleCurrent = $QUILocale->getCurrent();

        /** @var QUI\Locale $Locale */
        foreach ($locales as $Locale) {
            // temporarily set language of $QUILocale to current of $Locale (for template parsing)
            $QUILocale->setCurrent($Locale->getCurrent());

            $menu = $this->getMenuData();

            $filter = array_filter($menu, function ($item) {
                return $item['name'] == self::TYPE_PROFILE;
            });

            $groupLabel = $Locale->get('quiqqer/system', 'profile');
            $data = $this->parseMenuData($filter, $Locale);

            foreach ($data as $entry) {
                // add special search terms to user profile entry
                if ($entry['name'] == 'userProfile') {
                    $profileSearchTerms = $this->getProfileSearchterms();

                    // Skip entry if no profile search terms could be found or
                    // an error occurred while getting them
                    if (empty($profileSearchTerms)) {
                        continue;
                    }

                    $entry['search'] .= ' ' . implode(' ', $profileSearchTerms);
                }

                $entry['group'] = self::TYPE_PROFILE;
                $entry['groupLabel'] = $groupLabel;
                $entry['filterGroup'] = self::FILTER_NAVIGATION;

                if (!isset($entry['icon'])) {
                    $entry['icon'] = self::TYPE_PROFILE_ICON;
                }

                $searchData = json_decode($entry['searchdata'], true);

                if (empty($searchData['require'])) {
                    continue;
                }

                try {
                    $this->addEntry($entry, $Locale->getCurrent());
                } catch (\Exception $Exception) {
                    QUI\System\Log::addError(
                        self::class . ' :: buildProfileCache() -> Could not add entry ' . $entry['name'] . ': '
                        . $Exception->getMessage()
                    );
                }
            }
        }

        // reset $QUILocale
        $QUILocale->setCurrent($quiLocaleCurrent);
    }

    /**
     * Gets the WHERE constraint based on user permissions
     *
     * @param array $filters - the filters that are considered
     * @return array
     */
    public function getWhereConstraint(array $filters): array
    {
        $where = [
            'navApps' => '`group` != \'' . self::TYPE_APPS . '\'',
            'navExtras' => '`group` != \'' . self::TYPE_EXTRAS . '\'',
        ];

        foreach ($filters as $filter) {
            if ($filter == self::FILTER_NAVIGATION) {
                if (Permission::hasPermission('quiqqer.menu.apps')) {
                    unset($where['navApps']);
                }

                if (Permission::hasPermission('quiqqer.menu.extras')) {
                    unset($where['navExtras']);
                }
            }
        }

        return array_values($where);
    }

    /**
     * Helper to build a section / search group via menu items
     *
     * @param string $type
     * @throws Exception
     */
    protected function buildMenuCacheHelper(string $type): void
    {
        QUI::getDataBase()->delete($this->getTable(), [
            'group' => $type
        ]);

        $menu = $this->getMenuData();

        $filter = array_filter($menu, function ($item) use ($type) {
            return $item['name'] == $type;
        });

        $locales = $this->getLocales();

        /** @var QUI\Locale $Locale */
        foreach ($locales as $Locale) {
            $typeLabel = '';

            switch ($type) {
                case self::TYPE_APPS:
                    $typeLabel = $Locale->get(
                        'quiqqer/system',
                        'menu.apps.text'
                    );
                    break;

                case self::TYPE_EXTRAS:
                    $typeLabel = $Locale->get(
                        'quiqqer/system',
                        'menu.goto.text'
                    );
                    break;

                case self::TYPE_PROFILE:
                    $typeLabel = $Locale->get(
                        'quiqqer/system',
                        'profile'
                    );
                    break;
            }

            $groupLabel = $Locale->get(
                'quiqqer/backendsearch',
                'search.builder.group.menu.label',
                [
                    'type' => $typeLabel
                ]
            );

            $data = $this->parseMenuData($filter, $Locale);

            foreach ($data as $entry) {
                $entry['group'] = $type;
                $entry['groupLabel'] = $groupLabel;
                $entry['filterGroup'] = self::FILTER_NAVIGATION;

                switch ($type) {
                    case self::TYPE_APPS:
                        if (empty($entry['icon'])) {
                            $entry['icon'] = self::TYPE_APPS_ICON;
                        }
                        break;

                    case self::TYPE_EXTRAS:
                        if (empty($entry['icon'])) {
                            $entry['icon'] = self::TYPE_EXTRAS_ICON;
                        }
                        break;

                    case self::TYPE_PROJECT:
                        if (empty($entry['icon'])) {
                            $entry['icon'] = self::TYPE_PROJECT_ICON;
                        }
                        break;

                    case self::TYPE_PROFILE:
                        if (empty($entry['icon'])) {
                            $entry['icon'] = self::TYPE_PROFILE_ICON;
                        }
                        break;
                }

                $searchData = json_decode($entry['searchdata'], true);

                if (empty($searchData['require'])) {
                    continue;
                }

                try {
                    $this->addEntry($entry, $Locale->getCurrent());
                } catch (\Exception $Exception) {
                    QUI\System\Log::addError(
                        self::class . ' :: buildMenuCacheHelper("' . $type . '") -> Could not add entry for menu group'
                        . ' "' . $groupLabel . '" (' . $entry['name'] . '): ' . $Exception->getMessage()
                    );
                }
            }
        }
    }

    /**
     * Add cache entry for a specific language
     *
     * @param array $params
     * @param string $lang
     * @throws QUI\Exception
     */
    public function addEntry(array $params, string $lang): void
    {
        $needles = ['title', 'search', 'group', 'filterGroup', 'searchdata'];

        foreach ($needles as $needle) {
            if (empty($params[$needle])) {
                throw new QUI\BackendSearch\Exception(
                    [
                        'quiqqer/backendsearch',
                        'exception.builder.addEntry.missing_params',
                        [
                            'params' => json_encode(array_keys($params)),
                            'needle' => json_encode($needle),
                            'lang' => $lang
                        ]
                    ],
                    404
                );
            }
        }

        // ID is automatically set via auto_increment
        if (isset($params['id'])) {
            unset($params['id']);
        }

        if (!isset($params['description'])) {
            $params['description'] = '';
        }

        if (!isset($params['icon'])) {
            $params['description'] = '';
        }

        if (isset($params['name'])) {
            unset($params['name']);
        }

        if (isset($params['groupLabel']) && is_array($params['groupLabel'])) {
            $params['groupLabel'] = json_encode($params['groupLabel']);
        }

        if (isset($params['searchdata']) && is_array($params['searchdata'])) {
            $params['searchdata'] = json_encode($params['searchdata']);
        }

        $params['lang'] = $lang;

        QUI::getDataBase()->insert($this->getTable(), $params);
    }

    /**
     * Parse menu entries to a data array
     *
     * @param array $items
     * @param QUI\Locale $Locale
     * @param string|null $parentTitle (optional) - title of parent menu node
     * @return array
     */
    protected function parseMenuData(array $items, QUI\Locale $Locale, string $parentTitle = null): array
    {
        $data = [];
        $searchFields = ['require', 'exec', 'onClick', 'type'];

        foreach ($items as $item) {
            $title = $item['text'];
            $search = $item['text'];

            // locale w. search string
            if (isset($item['locale']) && is_array($item['locale'])) {
                $localeGroup = $item['locale'][0];
                $localeVar = $item['locale'][1];

                if ($Locale->exists($localeGroup, $localeVar)) {
                    $search = $Locale->get($item['locale'][0], $item['locale'][1]);
                    $title = $search;
                }
            }

            if (empty($title)) {
                continue;
            }

            $description = $title;

            if (!is_null($parentTitle)) {
                $description = $parentTitle . ' -> ' . $description;    // @todo Trennzeichen ggf. ändern
            }

            $icon = '';

            if (isset($item['icon'])) {
                $icon = $item['icon'];
            }

            $searchData = [];

            foreach ($searchFields as $field) {
                if (isset($item[$field])) {
                    $searchData[$field] = $item[$field];
                }
            }

            $data[] = [
                'name' => $item['name'],
                'title' => $title,
                'description' => $description,
                'icon' => $icon,
                'search' => $search,
                'searchdata' => json_encode($searchData)
            ];

            if (!empty($item['items'])) {
                $data = array_merge($data, $this->parseMenuData($item['items'], $Locale, $description));
            }
        }

        return $data;
    }

    /**
     * Get search terms from user profile (dynamic) template
     *
     * @return array - list of search terms
     */
    protected function getProfileSearchterms(): array
    {
        $search = [];
        $html = QUI::getUsers()->getProfileTemplate();

        try {
            $Doc = new DOMDocument();
            $Doc->loadHTML($html);
        } catch (\Exception $Exception) {
            QUI\System\Log::addNotice(
                self::class . ' :: getProfileSearchterms -> Could not parse user profile search terms: '
                . $Exception->getMessage()
            );

            return $search;
        }

        $Path = new DOMXPath($Doc);

        // table headers
        $titles = $Path->query('//table/thead/tr/th');

        foreach ($titles as $Title) {
            $search[] = Encoding::toUTF8(trim(DOMUtils::getTextFromNode($Title)));
        }

        // labels
        $labels = $Path->query('//label/span');

        /** @var DOMNode $Label */
        foreach ($labels as $Label) {
            $search[] = Encoding::toUTF8(trim(DOMUtils::getTextFromNode($Label)));
        }

        return $search;
    }
}
