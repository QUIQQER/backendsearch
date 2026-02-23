<?php

namespace QUITests\BackendSearch;

use QUI\BackendSearch\ProviderInterface;

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
