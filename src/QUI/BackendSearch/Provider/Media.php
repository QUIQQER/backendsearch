<?php

namespace QUI\BackendSearch\Provider;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception as DbalException;
use QUI;
use QUI\BackendSearch\ProviderInterface;
use QUI\Utils\Doctrine as DoctrineUtils;

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
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public function search(string $search, array $params = []): array
    {
        $filterGroups = isset($params["filterGroups"]) && is_array($params["filterGroups"])
            ? $params["filterGroups"]
            : [];
        $filter = array_flip($filterGroups);

        $projects = QUI::getProjectManager()->getProjectList();
        $results = [];
        $types = [];

        if (isset($filter["file"])) {
            $types[] = "file";
        }

        if (isset($filter["image"])) {
            $types[] = "image";
        }

        if (isset($filter["folder"])) {
            $types[] = "folder";
        }

        if (empty($types)) {
            return $results;
        }

        $Connection = QUI::getDataBaseConnection();

        foreach ($projects as $Project) {
            $Media = $Project->getMedia();
            $QueryBuilder = $Connection->createQueryBuilder();

            $QueryBuilder
                ->select(
                    DoctrineUtils::quoteIdentifier("id"),
                    DoctrineUtils::quoteIdentifier("title"),
                    DoctrineUtils::quoteIdentifier("file"),
                    DoctrineUtils::quoteIdentifier("type")
                )
                ->from(DoctrineUtils::quoteIdentifier($Media->getTable()))
                ->where(
                    "(" . DoctrineUtils::quoteIdentifier("title") . " LIKE :search OR "
                    . DoctrineUtils::quoteIdentifier("mime_type") . " LIKE :search)"
                )
                ->andWhere(DoctrineUtils::quoteIdentifier("type") . " IN (:types)")
                ->setParameter("search", "%" . $search . "%")
                ->setParameter("types", $types, ArrayParameterType::STRING);

            if (isset($params["limit"])) {
                $QueryBuilder->setMaxResults((int)$params["limit"]);
            }

            try {
                $result = $QueryBuilder->executeQuery()->fetchAllAssociative();
            } catch (DbalException $Exception) {
                QUI\System\Log::addError(
                    self::class . " :: search -> " . $Exception->getMessage()
                );

                continue;
            }

            $projectName = $Project->getName();

            foreach ($result as $row) {
                $groupLabel = QUI::getLocale()->get(
                    "quiqqer/backendsearch",
                    "search.provider.media.group.label",
                    [
                        "projectName" => $projectName
                    ]
                );

                $icon = match ($row["type"]) {
                    "file" => "fa fa-file-text-o",
                    "folder" => "fa fa-folder-o",
                    default => "fa fa-picture-o",
                };

                $results[] = [
                    "id" => $projectName . "-" . $row["id"],
                    "title" => $row["title"],
                    "description" => $row["file"],
                    "icon" => $icon,
                    "groupLabel" => $groupLabel,
                    "group" => $projectName . "-media"
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
     * @return array<int,array<string,mixed>>
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
