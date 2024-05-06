<?php

/**
 * This file contains QUI\BackendSearch\Search
 */

namespace QUI\BackendSearch;

use PDO;
use QUI;
use QUI\Database\Exception;

/**
 * Class Search
 *
 * @package QUI\Workspace
 */
class Search
{
    /**
     * @var null|Search
     */
    protected static ?Search $Instance = null;

    /**
     * @return Search
     */
    public static function getInstance(): Search
    {
        if (is_null(self::$Instance)) {
            self::$Instance = new self();
        }

        return self::$Instance;
    }

    /**
     * Execute the search
     *
     * @param string $string - search string
     * @param array $params - search query params
     *
     * @return array
     */
    public function search(string $string, array $params = []): array
    {
        $DesktopSearch = Builder::getInstance();
        $string = trim($string);

        $sql = "SELECT * FROM " . $DesktopSearch->getTable();
        $where = [
            '`search` LIKE :search',
            '`lang` = \'' . QUI::getUserBySession()->getLang() . '\''
        ];
        $binds = [
            'search' => [
                'value' => '%' . $string . '%',
                'type' => PDO::PARAM_STR
            ]
        ];

        $where = array_merge($where, $DesktopSearch->getWhereConstraint($params['filterGroups']));

        if (!empty($params['group'])) {
            $where[] = '`group` = :group';
            $binds['group'] = [
                'value' => $params['group'],
                'type' => PDO::PARAM_STR
            ];

            $groupFilter = true;
        }

        if (!empty($params['filterGroups']) && is_array($params['filterGroups'])) {
            $where[] = '`filterGroup` IN (\'' . implode("','", $params['filterGroups']) . '\')';
        }

        $sql .= " WHERE " . implode(' AND ', $where);

        if (!empty($params['limit'])) {
            $sql .= " LIMIT " . (int)$params['limit'] * 3;
        } else {
            $limit = (int)QUI::getConfig('etc/search.ini.php')->get('general', 'maxResultsPerGroup');
            $params['limit'] = $limit;  // set limit parameter for provider search

            $sql .= " LIMIT " . $limit;
        }

        $PDO = QUI::getDataBase()->getPDO();
        $Stmt = $PDO->prepare($sql);

        foreach ($binds as $var => $bind) {
            $Stmt->bindValue(':' . $var, $bind['value'], $bind['type']);
        }

        try {
            $Stmt->execute();
            $result = $Stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $Exception) {
            QUI\System\Log::addError(
                self::class . ' :: search -> ' . $Exception->getMessage()
            );

            return [];
        }

        // get group counts
//        $countResult = QUI::getDataBase()->fetch(array(
//            'select' => array(
//                'group',
//                'COUNT(`group`)'
//            ),
//            'from'   => $DesktopSearch->getTable(),
//            'group'  => 'group'
//        ));
//
//        $groupCounts = array();
//
//        foreach ($countResult as $row) {
//            $groupCounts[$row['group']] = $row['COUNT(`group`)'];
//        }

        /* @var $Provider ProviderInterface */
        foreach ($DesktopSearch->getProvider() as $Provider) {
            try {
                $providerResult = $Provider->search($string, $params);
            } catch (\Exception $Exception) {
                QUI\System\Log::addError(
                    self::class . ' :: search -> ' . $Exception->getMessage()
                );

                continue;
            }

            if (empty($providerResult)) {
                continue;
            }

            foreach ($providerResult as $key => $product) {
                $product['provider'] = get_class($Provider);
                $providerResult[$key] = $product;
            }

            $result = array_merge($result, $providerResult);
        }

        // filter duplicates
        $ids = [];

        $result = array_filter($result, function ($data) use (&$ids) {
            if (isset($ids[$data['id']])) {
                return false;
            }

            $ids[$data['id']] = true;
            return true;
        });

        return array_values($result);

//        $groups = array();
//
//        foreach ($result as $row) {
//            $group = $row['group'];
//
//            if (!isset($groups[$group])) {
//                $groups[$group] = array(
//                    'count' => 0
//                );
//            }
//
//            $groups[$group]['count']++;
//        }
//
//        $searchResult = array(
//            'entries' => $result,
//            'groups'  => $groups
//        );

        // if specific group was requested -> do not limit results
//        if ($groupFilter) {
//            return $searchResult;
//        }

//        // max limit per group
//        $groupCount = array();
//        $groupLimit = (int)QUI::getConfig('etc/search.ini.php')->get('general', 'maxResultsPerGroup');
//
//        foreach ($result as $k => $row) {
//            $group = $row['group'];
//
//            if (!isset($groupCount[$group])) {
//                $groupCount[$group] = 0;
//            }
//
//            if ($groupCount[$group] >= $groupLimit) {
//                unset($result[$k]);
//            }
//
//            $groupCount[$group]++;
//        }
//
//        return $result;
    }

    /**
     * Return one search cache entry
     *
     * @param string $id
     * @return array
     * @throws Exception
     */
    public function getEntry(string $id): array
    {
        $result = QUI::getDataBase()->fetch([
            'from' => Builder::getInstance()->getTable(),
            'where' => [
                'id' => $id
            ],
            'limit' => 1
        ]);

        return $result[0] ?? [];
    }
}
