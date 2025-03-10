<?php

namespace QUI\BackendSearch\Provider;

use Exception;
use PDO;
use QUI;
use QUI\BackendSearch\ProviderInterface;

class Media implements ProviderInterface
{
    /**
     * Build the cache
     */
    public function buildCache(): void
    {
    }

    /**
     * Execute a search
     *
     * @param string $search
     * @param array $params
     * @return array
     */
    public function search(string $search, array $params = []): array
    {
        $filter = array_flip($params['filterGroups']);

        $projects = QUI::getProjectManager()->getProjectList();
        $results = [];

        // if no groups are selected, return empty result list
        if (
            !isset($filter['file'])
            && !isset($filter['image'])
            && !isset($filter['folder'])
        ) {
            return $results;
        }

        $where = [
            '(`title` LIKE :search OR `mime_type` LIKE :search)'
        ];

        $whereOr = [];

        if (isset($filter['file'])) {
            $whereOr[] = '`type` = \'file\'';
        }

        if (isset($filter['image'])) {
            $whereOr[] = '`type` = \'image\'';
        }

        if (isset($filter['folder'])) {
            $whereOr[] = '`type` = \'folder\'';
        }

        $where[] = '(' . implode(' OR ', $whereOr) . ')';

        $PDO = QUI::getDataBase()->getPDO();

        foreach ($projects as $Project) {
            $Media = $Project->getMedia();

            $sql = "SELECT id,title,file,type FROM " . $Media->getTable();
            $sql .= " WHERE " . implode(' AND ', $where);

            if (isset($params['limit'])) {
                $sql .= " LIMIT " . (int)$params['limit'];
            }

            $Stmt = $PDO->prepare($sql);

            // bind
            $Stmt->bindValue(':search', '%' . $search . '%');

            try {
                $Stmt->execute();
                $result = $Stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $Exception) {
                QUI\System\Log::addError(
                    self::class . ' :: search -> ' . $Exception->getMessage()
                );

                continue;
            }

            $projectName = $Project->getName();

            foreach ($result as $row) {
                $groupLabel = QUI::getLocale()->get(
                    'quiqqer/backendsearch',
                    'search.provider.media.group.label',
                    [
                        'projectName' => $projectName
                    ]
                );

                $icon = match ($row['type']) {
                    'file' => 'fa fa-file-text-o',
                    'folder' => 'fa fa-folder-o',
                    default => 'fa fa-picture-o',
                };

                $results[] = [
                    'id' => $projectName . '-' . $row['id'],
                    'title' => $row['title'],
                    'description' => $row['file'],
                    'icon' => $icon,
                    'groupLabel' => $groupLabel,
                    'group' => $projectName . '-media'
                ];
            }
        }

        return $results;
    }

    /**
     * Return a search entry
     *
     * @param integer $id
     * @return array
     */
    public function getEntry(int $id): array
    {
        $data = explode('-', (string)$id);

        return [
            'searchdata' => json_encode([
                'require' => 'package/quiqqer/backendsearch/bin/controls/provider/Media',
                'params' => [
                    'project' => $data[0],
                    'id' => $data[1]
                ]
            ])
        ];
    }

    /**
     * Get all available search groups of this provider.
     * Search results can be filtered by these search groups.
     *
     * @return array
     */
    public function getFilterGroups(): array
    {
        return [
            [
                'group' => 'folder',
                'label' => [
                    'quiqqer/backendsearch',
                    'search.provider.media.filter.folder.label'
                ]
            ],
            [
                'group' => 'image',
                'label' => [
                    'quiqqer/backendsearch',
                    'search.provider.media.filter.image.label'
                ]
            ],
            [
                'group' => 'file',
                'label' => [
                    'quiqqer/backendsearch',
                    'search.provider.media.filter.file.label'
                ]
            ]
        ];
    }
}
