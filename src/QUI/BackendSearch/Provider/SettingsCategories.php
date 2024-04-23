<?php

namespace QUI\BackendSearch\Provider;

use QUI;
use QUI\BackendSearch\Builder;
use QUI\BackendSearch\ProviderInterface;
use QUI\Permissions\Permission;
use QUI\Utils\DOM as DOMUtils;
use QUI\Utils\Text\XML;

class SettingsCategories implements ProviderInterface
{
    const TYPE_SETTINGS = 'settings';
    const TYPE_SETTINGS_CONTENT = 'settings_content';

    /**
     * Build the cache
     *
     * @return void
     */
    public function buildCache(): void
    {
        $Builder = Builder::getInstance();
        $locales = $Builder->getLocales();
        $QUILocale = QUI::getLocale();
        $quiLocaleCurrent = $QUILocale->getCurrent();

        /** @var QUI\Locale $Locale */
        foreach ($locales as $Locale) {
            // temporarily set language of $QUILocale to current of $Locale (for categories parsing)
            $QUILocale->setCurrent($Locale->getCurrent());

            $menu = $Builder->getMenuData();

            $filter = array_filter($menu, function ($item) {
                return $item['name'] == self::TYPE_SETTINGS;
            });

            $groupLabel = $Locale->get(
                'quiqqer/backendsearch',
                'search.builder.group.menu.label',
                [
                    'type' => $Locale->get('quiqqer/system', 'settings')
                ]
            );

            $data = $this->parseSettingsMenuData($filter, $Locale);

            foreach ($data as $key => $entry) {
                if (empty($entry['title'])) {
                    continue;
                }

                if (!isset($entry['group'])) {
                    $entry['group'] = self::TYPE_SETTINGS;
                }

                if (!isset($entry['groupLabel'])) {
                    $entry['groupLabel'] = $groupLabel;
                }

                if (!isset($entry['icon'])) {
                    $entry['icon'] = 'fa fa-gears';
                }

                if (!isset($entry['filterGroup'])) {
                    $entry['filterGroup'] = $Builder::FILTER_NAVIGATION;
                }

                $searchData = json_decode($entry['searchdata'], true);

                if (empty($searchData['require'])) {
                    continue;
                }

                $Builder->addEntry($entry, $Locale->getCurrent());
            }
        }

        // reset $QUILocale
        $QUILocale->setCurrent($quiLocaleCurrent);
    }

    /**
     * Execute a search
     *
     * @param string $search
     * @param array $params
     * @return mixed
     */
    public function search(string $search, array $params = []): array
    {
        return [];
    }

    /**
     * Return a search entry
     *
     * @param integer $id
     * @return mixed
     */
    public function getEntry(int $id): mixed
    {
        return null;
    }

    /**
     * Get all available search groups of this provider.
     * Search results can be filtered by these search groups.
     *
     * @return array
     */
    public function getFilterGroups(): array
    {
        $filterGroups = [];

        // can only search settings if user has access to settings
        if (Permission::hasPermission('quiqqer.menu.settings')) {
            $filterGroups[] = [
                'group' => self::TYPE_SETTINGS_CONTENT,
                'label' => [
                    'quiqqer/backendsearch',
                    'search.builder.filter.label.settings'
                ]
            ];
        }

        return $filterGroups;
    }

    /**
     * Parse menu entries to a data array
     *
     * @param array $items
     * @param QUI\Locale $Locale
     * @param string $parentTitle (optional) - title of parent menu node
     * @return array
     */
    protected function parseSettingsMenuData($items, $Locale, $parentTitle = null): array
    {
        $data = [];
        $searchFields = ['require', 'exec', 'onClick', 'type', 'category'];

        if (!is_array($items)) {
            return [];
        }

        foreach ($items as $item) {
            $title = $item['text'];
            $description = $title;

            if (!is_null($parentTitle)) {
                $description = $parentTitle . ' -> ' . $description;    // @todo Trennzeichen ggf. ändern
            }

            $item['description'] = $description;

            if (
                isset($item['qui-xml-file'])
                && !empty($item['qui-xml-file'])
            ) {
                $data = array_merge(
                    $data,
                    $this->parseSearchDataFromSettingsXmlItem($item, $Locale)
                );

                continue;
            } else {
                $search = $item['text'];
            }

            // locale w. search string
            if (isset($item['locale']) && is_array($item['locale'])) {
                $search = $Locale->get($item['locale'][0], $item['locale'][1]);
                $title = $search;
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
                'title' => $title,
                'icon' => $icon,
                'search' => $search,
                'searchdata' => json_encode($searchData)
            ];

            if (
                isset($item['items'])
                && !empty($item['items'])
            ) {
                $data = array_merge($data, $this->parseSettingsMenuData($item['items'], $Locale, $description));
            }
        }

        return $data;
    }

    /**
     * Parses a search string from settings.xml data
     *
     * @param array $item - The settings item
     * @param QUI\Locale $Locale
     * @return array - search data
     */
    protected function parseSearchDataFromSettingsXmlItem($item, $Locale)
    {
        $xmlFile = $item['qui-xml-file'];

        if (is_array($xmlFile)) {
            $xmlFiles = $xmlFile;
        } else {
            $xmlFiles = [$xmlFile];
        }

        $dataEntries = [];

        foreach ($xmlFiles as $xmlFile) {
            if (!file_exists($xmlFile)) {
                $xmlFile = CMS_DIR . $xmlFile;
            }

            if (!file_exists($xmlFile)) {
                QUI\System\Log::addWarning(
                    self::class . ' :: parseSearchStringFromSettingsXml -> XML file ' . $xmlFile . ' does not exist.'
                );

                continue;
            }

            $Dom = XML::getDomFromXml($xmlFile);
            $Path = new \DOMXPath($Dom);
            $categories = $Path->query("//settings/window/categories/category");
            $descPrefix = $Locale->get('quiqqer/system', 'settings') . ' -> ' . $item['text'];

            // add menu entry for settings
            $dataEntries[] = [
                'title' => $item['text'],
                'description' => $item['description'],
                'group' => self::TYPE_SETTINGS,
                'groupLabel' => $Locale->get(
                    'quiqqer/backendsearch',
                    'search.builder.group.menu.label',
                    [
                        'type' => $Locale->get('quiqqer/system', 'settings')
                    ]
                ),
                'searchdata' => json_encode([
                    'params' => [
                        'category' => false,
                        'xmlFile' => $xmlFile
                    ],
                    'require' => 'package/quiqqer/backendsearch/bin/controls/builder/Settings'
                ]),
                'icon' => !empty($item['icon']) ? $item['icon'] : 'fa fa-gears',
                'search' => $item['text']
            ];

            /** @var \DOMElement $Category */
            foreach ($categories as $Category) {
                $entry = [
                    'searchdata' => [
                        'params' => [
                            'category' => $Category->getAttribute('name'),
                            'xmlFile' => $xmlFile
                        ],
                        'require' => 'package/quiqqer/backendsearch/bin/controls/builder/Settings'
                    ],
                    'icon' => !empty($item['icon']) ? $item['icon'] : 'fa fa-gears',
                    'group' => self::TYPE_SETTINGS_CONTENT,
                    'filterGroup' => self::TYPE_SETTINGS_CONTENT,
                    'groupLabel' => $Locale->get('quiqqer/system', 'settings')
                ];

                $searchStringParts = [];

                /** @var \DOMNode $Child */
                foreach ($Category->childNodes as $Child) {
                    if ($Child->nodeName == '#text') {
                        continue;
                    }

                    if ($Child->nodeName == 'title' || $Child->nodeName == 'text') {
                        $nodeText = DOMUtils::getTextFromNode($Child);
                        $entry['title'] = $item['text'] . ' - ' . $nodeText;
                        $entry['description'] = $descPrefix . ' -> ' . $nodeText;
                        $searchStringParts[] = $nodeText;
                        continue;
                    }

                    if ($Child->nodeName == 'settings') {
                        /** @var \DOMNode $SettingChild */
                        foreach ($Child->childNodes as $SettingChild) {
                            if ($SettingChild->nodeName == 'title' || $SettingChild->nodeName == 'text') {
                                $searchStringParts[] = DOMUtils::getTextFromNode($SettingChild);
                                continue;
                            }

                            if ($SettingChild->nodeName == 'description' || $SettingChild->nodeName == 'description') {
                                $searchStringParts[] = DOMUtils::getTextFromNode($SettingChild);
                                continue;
                            }

                            if ($SettingChild->hasChildNodes()) {
                                foreach ($SettingChild->childNodes as $SettingInputChild) {
                                    if ($SettingInputChild->nodeName == 'title' || $SettingInputChild->nodeName == 'text') {
                                        $searchStringParts[] = DOMUtils::getTextFromNode($SettingInputChild);
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }

                $entry['search'] = implode(' ', $searchStringParts);
                $entry['searchdata'] = json_encode($entry['searchdata']);
                $dataEntries[] = $entry;
            }
        }

        return $dataEntries;
    }
}
