<?php

namespace QUITests\BackendSearch;

use QUI\BackendSearch\Builder;

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
