<?php

namespace QUI\BackendSearch\Provider;

use Doctrine\DBAL\Exception as DbalException;
use QUI;
use QUI\BackendSearch\ProviderInterface;
use QUI\Permissions\Permission;
use QUI\Utils\Doctrine as DoctrineUtils;

/**
 * Class UsersAndGroups
 *
 * Search QUIQQER users and groups
 */
class UsersAndGroups implements ProviderInterface
{
    const FILTER_USERS_GROUPS = 'usersGroups';

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
     */
    public function search(string $search, array $params = []): array
    {
        if (
            isset($params["filterGroups"])
            && is_array($params["filterGroups"])
            && !in_array(self::FILTER_USERS_GROUPS, $params["filterGroups"])
        ) {
            return [];
        }

        $results = [];
        $Locale = QUI::getLocale();
        $Connection = QUI::getDataBaseConnection();

        if (Permission::hasPermission("quiqqer.admin.users.edit")) {
            $Users = QUI::getUsers();
            $QueryBuilder = $Connection->createQueryBuilder();
            $userAlias = "users";
            $addressAlias = "address";
            $userColumn = static fn (string $column): string => $userAlias . "." . DoctrineUtils::quoteIdentifier($column);
            $addressColumn = static fn (string $column): string => $addressAlias . "." . DoctrineUtils::quoteIdentifier($column);
            $where = [
                $userColumn("uuid") . " LIKE :search",
                $userColumn("username") . " LIKE :search",
                $userColumn("firstname") . " LIKE :search",
                $userColumn("lastname") . " LIKE :search",
                $userColumn("email") . " LIKE :search",
                $addressColumn("firstname") . " LIKE :search",
                $addressColumn("lastname") . " LIKE :search",
                $addressColumn("mail") . " LIKE :search",
                $addressColumn("company") . " LIKE :search",
                $addressColumn("street_no") . " LIKE :search",
                $addressColumn("zip") . " LIKE :search",
                $addressColumn("city") . " LIKE :search"
            ];

            if (ctype_digit($search)) {
                $where[] = $userColumn("id") . " = :userId";
                $QueryBuilder->setParameter("userId", (int)$search);
            }

            $QueryBuilder
                ->select(
                    $userColumn("id"),
                    $userColumn("uuid"),
                    $userColumn("username")
                )
                ->distinct()
                ->from(DoctrineUtils::quoteIdentifier($Users->table()), $userAlias)
                ->leftJoin(
                    $userAlias,
                    DoctrineUtils::quoteIdentifier($Users->tableAddress()),
                    $addressAlias,
                    $userColumn("uuid") . " = " . $addressColumn("userUuid")
                )
                ->where("(" . implode(" OR ", $where) . ")")
                ->setParameter("search", "%" . $search . "%");

            if (isset($params["limit"])) {
                $QueryBuilder->setMaxResults((int)$params["limit"]);
            }

            try {
                $result = $QueryBuilder->executeQuery()->fetchAllAssociative();
            } catch (DbalException $Exception) {
                QUI\System\Log::addError(
                    self::class . " :: search (users) -> " . $Exception->getMessage()
                );

                $result = [];
            }

            $groupLabel = $Locale->get(
                "quiqqer/backendsearch",
                "search.builder.group.label.users"
            );

            foreach ($result as $row) {
                $results[] = [
                    "id" => "u" . $row["id"],
                    "title" => $row["username"],
                    "icon" => "fa fa-user",
                    "group" => "users",
                    "groupLabel" => $groupLabel
                ];
            }
        }

        if (!Permission::hasPermission("quiqqer.admin.groups.edit")) {
            return $results;
        }

        $QueryBuilder = $Connection->createQueryBuilder();
        $where = [DoctrineUtils::quoteIdentifier("name") . " LIKE :search"];

        if (ctype_digit($search)) {
            $where[] = DoctrineUtils::quoteIdentifier("id") . " = :groupId";
            $QueryBuilder->setParameter("groupId", (int)$search);
        }

        $QueryBuilder
            ->select(
                DoctrineUtils::quoteIdentifier("id"),
                DoctrineUtils::quoteIdentifier("name")
            )
            ->from(DoctrineUtils::quoteIdentifier(QUI::getGroups()->table()))
            ->where("(" . implode(" OR ", $where) . ")")
            ->setParameter("search", "%" . $search . "%");

        try {
            $result = $QueryBuilder->executeQuery()->fetchAllAssociative();
        } catch (DbalException $Exception) {
            QUI\System\Log::addError(
                self::class . " :: search (groups) -> " . $Exception->getMessage()
            );

            return $results;
        }

        $groupLabel = $Locale->get(
            "quiqqer/backendsearch",
            "search.builder.group.label.groups"
        );

        foreach ($result as $row) {
            $results[] = [
                "id" => "g" . $row["id"],
                "title" => $row["name"],
                "icon" => "fa fa-users",
                "group" => "groups",
                "groupLabel" => $groupLabel
            ];
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
        $type = mb_strtolower(mb_substr((string)$id, 0, 1));

        return [
            'searchdata' => [
                'require' => 'package/quiqqer/backendsearch/bin/controls/provider/UsersAndGroups',
                'params' => [
                    'id' => mb_substr((string)$id, 1),
                    'type' => $type === 'u' ? 'user' : 'group'
                ]
            ]
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
        $filterGroups = [];

        // add filters depending on permissions to edit users and/or groups
        if (
            Permission::hasPermission('quiqqer.admin.users.edit')
            || Permission::hasPermission('quiqqer.admin.groups.edit')
        ) {
            $filterGroups[] = [
                'group' => self::FILTER_USERS_GROUPS,
                'label' => [
                    'quiqqer/backendsearch',
                    'search.builder.filter.label.groups'
                ]
            ];
        }

        return $filterGroups;
    }
}
