<?php

namespace QUITests\BackendSearch;

use QUI\BackendSearch\Provider\SettingsCategories;

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
