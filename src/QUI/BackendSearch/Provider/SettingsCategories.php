<?php

namespace QUI\BackendSearch\Provider;

use DOMElement;
use DOMNode;
use DOMXPath;
use QUI;
use QUI\BackendSearch\Builder;
use QUI\BackendSearch\ProviderInterface;
use QUI\Exception;
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
     * @throws Exception
     */
    public function buildCache(): void
    {
        $Builder = Builder::getInstance();
        $locales = $Builder->getLocales();
        $QUILocale = QUI::getLocale();
        $quiLocaleCurrent = $QUILocale->getCurrent();

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

            foreach ($data as $entry) {
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
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public function search(string $search, array $params = []): array
    {
        return [];
    }

    /**
     * Return a search entry
     *
     * @param string|integer $id
     * @return mixed
     */
    public function getEntry(string | int $id): mixed
    {
        return null;
    }

    /**
     * Get all available search groups of this provider.
     * Search results can be filtered by these search groups.
     *
     * @return array<int,array<string,mixed>>
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
     * @param array<int,array<string,mixed>> $items
     * @param QUI\Locale $Locale
     * @param string|null $parentTitle (optional) - title of parent menu node
     * @return array<int,array<string,mixed>>
     */
    protected function parseSettingsMenuData(
        array $items,
        QUI\Locale $Locale,
        null | string $parentTitle = null
    ): array {
        $data = [];
        $searchFields = ['require', 'exec', 'onClick', 'type', 'category'];

        foreach ($items as $item) {
            $title = $this->toStringValue($item['text'] ?? '');
            $description = $title;

            if (!is_null($parentTitle)) {
                $description = $parentTitle . ' -> ' . $description;    // @todo Trennzeichen ggf. ändern
            }

            $item['description'] = $description;

            if (!empty($item['qui-xml-file'])) {
                $data = array_merge(
                    $data,
                    $this->parseSearchDataFromSettingsXmlItem($item, $Locale)
                );

                continue;
            } else {
                $search = $title;
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

            if (!empty($item['items']) && is_array($item['items'])) {
                $data = array_merge($data, $this->parseSettingsMenuData($item['items'], $Locale, $description));
            }
        }

        return $data;
    }

    /**
     * Parses a search string from settings.xml data
     *
     * @param array<string,mixed> $item - The settings item
     * @param QUI\Locale $Locale
     * @return array<int,array<string,mixed>> - search data
     */
    protected function parseSearchDataFromSettingsXmlItem(array $item, QUI\Locale $Locale): array
    {
        $xmlFile = $item['qui-xml-file'];

        if (is_array($xmlFile)) {
            $xmlFiles = $xmlFile;
        } else {
            $xmlFiles = [$xmlFile];
        }

        $dataEntries = [];

        foreach ($xmlFiles as $xmlFile) {
            if (!is_string($xmlFile) || $xmlFile === '') {
                continue;
            }

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
            $Path = new DOMXPath($Dom);
            $categories = $Path->query("//settings/window/categories/category");
            $itemText = $this->toStringValue($item['text'] ?? '');
            $itemDescription = $this->toStringValue($item['description'] ?? '');
            $itemIcon = !empty($item['icon']) ? $this->toStringValue($item['icon']) : 'fa fa-gears';
            $descPrefix = $Locale->get('quiqqer/system', 'settings') . ' -> ' . $itemText;

            // add menu entry for settings
            $dataEntries[] = [
                'title' => $itemText,
                'description' => $itemDescription,
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
                'icon' => $itemIcon,
                'search' => $itemText
            ];

            if ($categories === false) {
                continue;
            }

            /** @var DOMElement $Category */
            foreach ($categories as $Category) {
                $entry = [
                    'searchdata' => [
                        'params' => [
                            'category' => $Category->getAttribute('name'),
                            'xmlFile' => $xmlFile
                        ],
                        'require' => 'package/quiqqer/backendsearch/bin/controls/builder/Settings'
                    ],
                    'icon' => $itemIcon,
                    'group' => self::TYPE_SETTINGS_CONTENT,
                    'filterGroup' => self::TYPE_SETTINGS_CONTENT,
                    'groupLabel' => $Locale->get('quiqqer/system', 'settings')
                ];

                $searchStringParts = [];

                /** @var DOMNode $Child */
                foreach ($Category->childNodes as $Child) {
                    if ($Child->nodeName == '#text') {
                        continue;
                    }

                    if ($Child->nodeName == 'title' || $Child->nodeName == 'text') {
                        $nodeText = $this->toStringValue(DOMUtils::getTextFromNode($Child));
                        $entry['title'] = $itemText . ' - ' . $nodeText;
                        $entry['description'] = $descPrefix . ' -> ' . $nodeText;
                        $searchStringParts[] = $nodeText;
                        continue;
                    }

                    if ($Child->nodeName == 'settings') {
                        /** @var DOMNode $SettingChild */
                        foreach ($Child->childNodes as $SettingChild) {
                            if ($SettingChild->nodeName == 'title' || $SettingChild->nodeName == 'text') {
                                $searchStringParts[] = $this->toStringValue(
                                    DOMUtils::getTextFromNode($SettingChild)
                                );
                                continue;
                            }

                            if ($SettingChild->nodeName == 'description') {
                                $searchStringParts[] = $this->toStringValue(
                                    DOMUtils::getTextFromNode($SettingChild)
                                );
                                continue;
                            }

                            if ($SettingChild->hasChildNodes()) {
                                foreach ($SettingChild->childNodes as $SettingInputChild) {
                                    if ($SettingInputChild->nodeName == 'title' || $SettingInputChild->nodeName == 'text') {
                                        $searchStringParts[] = $this->toStringValue(
                                            DOMUtils::getTextFromNode($SettingInputChild)
                                        );
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

    /**
     * @param array<mixed>|string $value
     */
    protected function toStringValue(array | string $value): string
    {
        if (is_array($value)) {
            $parts = array_map(static function ($part): string {
                return is_scalar($part) ? (string)$part : '';
            }, $value);

            return implode(' ', array_filter($parts));
        }

        return $value;
    }
}
