<?php

namespace QUI\BackendSearch\Provider;

use QUI;
use QUI\BackendSearch\ProviderInterface;
use QUI\Database\Exception;

class Sites implements ProviderInterface
{
    const FILTER_SITES = 'sites';

    /**
     * Build the cache
     *
     * @return void
     */
    public function buildCache(): void
    {
    }

    /**
     * Execute a search
     *
     * @param string $search
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     *
     * @throws Exception
     * @throws QUI\Exception
     */
    public function search(string $search, array $params = []): array
    {
        $filterGroups = isset($params['filterGroups']) && is_array($params['filterGroups'])
            ? $params['filterGroups']
            : [];

        if (!in_array(self::FILTER_SITES, $filterGroups)) {
            return [];
        }

        $projects = QUI::getProjectManager()->getProjectList();
        $results = [];

        foreach ($projects as $Project) {
            $siteIds = $Project->getSitesIds([
                'where' => [
                    'active' => -1
                ],
                'where_or' => [
                    'title' => [
                        'type' => '%LIKE%',
                        'value' => $search
                    ],
                    'name' => [
                        'type' => '%LIKE%',
                        'value' => $search
                    ],
                    'id' => $search
                ],
                'limit' => isset($params['limit']) ? (int)$params['limit'] : null
            ]);

            $projectName = $Project->getName();
            $projectLang = $Project->getLang();
            $groupLabel = QUI::getLocale()->get(
                'quiqqer/backendsearch',
                'search.provider.sites.group.label',
                [
                    'projectName' => $projectName,
                    'projectLang' => $projectLang
                ]
            );

            $group = 'project-' . $projectName . '-' . $projectLang;

            foreach ($siteIds as $row) {
                $siteId = $row['id'];
                $Site = $Project->get($siteId);

                $results[] = [
                    'id' => $projectName . '-' . $projectLang . '-' . $siteId,
                    'title' => $Site->getAttribute('title') . ' (#' . $Site->getId() . ')',
                    'description' => $Site->getUrlRewritten(),
                    'icon' => 'fa fa-file-o',
                    'groupLabel' => $groupLabel,
                    'group' => $group
                ];
            }
        }

        return $results;
    }

    /**
     * Return a search entry
     *
     * @param string|integer $id
     * @return array<string,mixed>
     */
    public function getEntry(string | int $id): array
    {
        $data = explode('-', (string)$id);

        return [
            'searchdata' => json_encode([
                'require' => 'package/quiqqer/backendsearch/bin/controls/provider/Sites',
                'params' => [
                    'projectName' => $data[0],
                    'projectLang' => $data[1],
                    'siteId' => $data[2]
                ]
            ])
        ];
    }

    /**
     * Get all available search groups of this provider.
     * Search results can be filtered by these search groups.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getFilterGroups(): array
    {
        return [
            [
                'group' => self::FILTER_SITES,
                'label' => [
                    'quiqqer/backendsearch',
                    'search.provider.sites.filter.sites.label'
                ]
            ]
        ];
    }
}
