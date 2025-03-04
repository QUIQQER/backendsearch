<?php

namespace QUI\BackendSearch\Provider;

use Exception;
use PDO;
use QUI;
use QUI\BackendSearch\ProviderInterface;
use QUI\Permissions\Permission;

/**
 * Class UsersAndGroups
 *
 * Search QUIQQER users and groups
 *
 * @package QUI\BackendSearch\Provider
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
     * @param array $params
     * @return array
     */
    public function search(string $search, array $params = []): array
    {
        if (
            isset($params['filterGroups'])
            && is_array($params['filterGroups'])
            && !in_array(self::FILTER_USERS_GROUPS, $params['filterGroups'])
        ) {
            return [];
        }

        $results = [];
        $PDO = QUI::getDataBase()->getPDO();
        $Locale = QUI::getLocale();

        // users
        if (Permission::hasPermission('quiqqer.admin.users.edit')) {
            $Users = QUI::getUsers();

            $sql = "SELECT users.id, users.uuid, users.username FROM ";
            $sql .= " `" . $Users->table() . "`, `" . $Users->tableAddress() . "` address";

            $where = [];

            // users table
            $where[] = "users.`uuid` LIKE :search";
            $where[] = "users.`id` LIKE :search";
            $where[] = "users.`username` LIKE :search";
            $where[] = "users.`firstname` LIKE :search";
            $where[] = "users.`lastname` LIKE :search";
            $where[] = "users.`email` LIKE :search";

            // users_address table
            $where[] = "address.`firstname` LIKE :search";
            $where[] = "address.`lastname` LIKE :search";
            $where[] = "address.`mail` LIKE :search";
            $where[] = "address.`company` LIKE :search";
            $where[] = "address.`street_no` LIKE :search";
            $where[] = "address.`zip` LIKE :search";
            $where[] = "address.`city` LIKE :search";

            $sql .= " WHERE " . implode(" OR ", $where);

            if (isset($params['limit'])) {
                $sql .= " LIMIT " . (int)$params['limit'];
            }

            $Stmt = $PDO->prepare($sql);

            // bind
            $Stmt->bindValue(':search', '%' . $search . '%');
            $error = false;

            try {
                $Stmt->execute();
                $result = $Stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $Exception) {
                QUI\System\Log::addError(
                    self::class . ' :: search (users) -> ' . $Exception->getMessage()
                );

                $error = true;
            }

            $groupLabel = $Locale->get(
                'quiqqer/backendsearch',
                'search.builder.group.label.users'
            );

            if (!$error) {
                foreach ($result as $row) {
                    $results[] = [
                        'id' => 'u' . $row['id'],
                        'title' => $row['username'],
                        'icon' => 'fa fa-user',
                        'group' => 'users',
                        'groupLabel' => $groupLabel
                    ];
                }
            }
        }

        // groups
        if (!Permission::hasPermission('quiqqer.admin.groups.edit')) {
            return $results;
        }

        try {
            $result = QUI::getDataBase()->fetch([
                'select' => [
                    'id',
                    'name'
                ],
                'from' => QUI::getGroups()->table(),
                'where_or' => [
                    'id' => [
                        'type' => '%LIKE%',
                        'value' => $search
                    ],
                    'name' => [
                        'type' => '%LIKE%',
                        'value' => $search
                    ]
                ]
            ]);
        } catch (Exception $Exception) {
            QUI\System\Log::addError(
                self::class . ' :: search (groups) -> ' . $Exception->getMessage()
            );

            return $results;
        }

        $groupLabel = $Locale->get(
            'quiqqer/backendsearch',
            'search.builder.group.label.groups'
        );

        foreach ($result as $row) {
            $results[] = [
                'id' => 'g' . $row['id'],
                'title' => $row['name'],
                'icon' => 'fa fa-users',
                'group' => 'groups',
                'groupLabel' => $groupLabel
            ];
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
     * @return array
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
