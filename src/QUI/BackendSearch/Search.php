<?php

/**
 * This file contains QUI\BackendSearch\Search
 */

namespace QUI\BackendSearch;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Query\QueryBuilder;
use QUI;
use QUI\Database\Exception;
use QUI\Utils\Doctrine as DoctrineUtils;

/**
 * Class Search
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
     * @param array<string,mixed> $params - search query params
     *
     * @return array<int,array<string,mixed>>
     * @throws QUI\Exception
     * @throws Exception
     */
    public function search(string $string, array $params = []): array
    {
        $DesktopSearch = Builder::getInstance();
        $string = trim($string);
        $limit = $this->getResultLimit($params);
        $requestedGroup = isset($params['group']) && is_string($params['group'])
            ? trim($params['group'])
            : '';
        $filterGroups = isset($params["filterGroups"]) && is_array($params["filterGroups"])
            ? array_values(array_filter($params["filterGroups"], "is_string"))
            : [];

        $Connection = QUI::getDataBaseConnection();
        $QueryBuilder = $Connection->createQueryBuilder();

        $QueryBuilder
            ->from(DoctrineUtils::quoteIdentifier($DesktopSearch->getTable()))
            ->where(DoctrineUtils::quoteIdentifier("search") . " LIKE :search")
            ->andWhere(DoctrineUtils::quoteIdentifier("lang") . " = :lang")
            ->setParameter("search", "%" . $string . "%")
            ->setParameter("lang", QUI::getUserBySession()->getLang());

        foreach ($DesktopSearch->getWhereConstraint($filterGroups) as $constraint) {
            $QueryBuilder->andWhere($constraint);
        }

        if (!empty($filterGroups)) {
            $QueryBuilder
                ->andWhere(DoctrineUtils::quoteIdentifier("filterGroup") . " IN (:filterGroups)")
                ->setParameter("filterGroups", $filterGroups, ArrayParameterType::STRING);
        }

        try {
            $result = $this->getCachedResults(
                $QueryBuilder,
                $requestedGroup,
                $limit
            );
        } catch (DbalException $Exception) {
            QUI\System\Log::addError(
                self::class . " :: search -> " . $Exception->getMessage()
            );

            return [];
        }

        $providers = $DesktopSearch->getProvider();

        if ($providers instanceof ProviderInterface) {
            $providers = [$providers];
        }

        $providerParams = $params;
        $providerParams['limit'] = $limit + 1;

        /* @var ProviderInterface $Provider */
        foreach ($providers as $Provider) {
            try {
                $providerResult = $Provider->search($string, $providerParams);
            } catch (\Exception $Exception) {
                QUI\System\Log::addError(
                    self::class . " :: search -> " . $Exception->getMessage()
                );

                continue;
            }

            if (empty($providerResult)) {
                continue;
            }

            foreach ($providerResult as $key => $product) {
                $product["provider"] = get_class($Provider);
                $providerResult[$key] = $product;
            }

            $result = array_merge($result, $providerResult);
        }

        $ids = [];

        $result = array_filter($result, function (array $data) use (&$ids): bool {
            if (!isset($data["id"])) {
                return true;
            }

            if (isset($ids[$data["id"]])) {
                return false;
            }

            $ids[$data["id"]] = true;
            return true;
        });

        $result = $this->limitResultsByGroup(
            array_values($result),
            $limit,
            $requestedGroup
        );

        return array_map(
            $this->prepareResultIcon(...),
            $result
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function getResultLimit(array $params): int
    {
        if (isset($params['limit']) && is_numeric($params['limit'])) {
            return max(1, (int)$params['limit']);
        }

        return max(
            1,
            (int)QUI::getConfig('etc/search.ini.php')->get('general', 'maxResultsPerGroup')
        );
    }

    /**
     * Load one additional cache entry per group to determine whether more
     * matching results exist without loading the complete result set.
     *
     * @return array<int, array<string, mixed>>
     * @throws DbalException
     */
    private function getCachedResults(
        QueryBuilder $QueryBuilder,
        string $requestedGroup,
        int $limit
    ): array {
        $groupColumn = DoctrineUtils::quoteIdentifier('group');

        if ($requestedGroup !== '') {
            $groups = [$requestedGroup];
        } else {
            $GroupQueryBuilder = clone $QueryBuilder;
            $groups = $GroupQueryBuilder
                ->select($groupColumn)
                ->groupBy($groupColumn)
                ->orderBy($groupColumn, 'ASC')
                ->executeQuery()
                ->fetchFirstColumn();
        }

        $result = [];

        foreach ($groups as $group) {
            if (!is_scalar($group)) {
                continue;
            }

            $GroupResultQueryBuilder = clone $QueryBuilder;
            $groupResult = $GroupResultQueryBuilder
                ->select('*')
                ->andWhere($groupColumn . ' = :resultGroup')
                ->setParameter('resultGroup', (string)$group)
                ->orderBy(DoctrineUtils::quoteIdentifier('id'), 'ASC')
                ->setMaxResults($limit + 1)
                ->executeQuery()
                ->fetchAllAssociative();

            $result = array_merge($result, $groupResult);
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $result
     * @return array<int, array<string, mixed>>
     */
    private function limitResultsByGroup(
        array $result,
        int $limit,
        string $requestedGroup
    ): array {
        $resultsByGroup = [];

        foreach ($result as $entry) {
            $group = isset($entry['group']) && is_scalar($entry['group'])
                ? (string)$entry['group']
                : '';

            if ($requestedGroup !== '' && $group !== $requestedGroup) {
                continue;
            }

            $entry['group'] = $group;
            $resultsByGroup[$group][] = $entry;
        }

        $limitedResult = [];

        foreach ($resultsByGroup as $groupResult) {
            $hasMore = count($groupResult) > $limit;

            foreach (array_slice($groupResult, 0, $limit) as $entry) {
                $entry['groupHasMore'] = $hasMore;
                $limitedResult[] = $entry;
            }
        }

        return $limitedResult;
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function prepareResultIcon(array $entry): array
    {
        if (!isset($entry['icon']) || !is_string($entry['icon'])) {
            return $entry;
        }

        $icon = trim($entry['icon']);

        if (
            !preg_match(
                '~\.(?:avif|bmp|gif|ico|jpe?g|png|svg|webp)(?:[?#].*)?$~i',
                $icon
            )
        ) {
            return $entry;
        }

        $entry['icon'] = '';
        $entry['iconUrl'] = $icon;

        return $entry;
    }

    /**
     * Return one search cache entry
     *
     * @param string|int $id
     * @return array<string,mixed>
     * @throws Exception
     */
    public function getEntry(string | int $id): array
    {
        try {
            $result = QUI::getDataBaseConnection()
                ->createQueryBuilder()
                ->select("*")
                ->from(DoctrineUtils::quoteIdentifier(Builder::getInstance()->getTable()))
                ->where(DoctrineUtils::quoteIdentifier("id") . " = :id")
                ->setParameter("id", $id)
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
        } catch (DbalException $Exception) {
            throw new Exception($Exception->getMessage(), $Exception->getCode());
        }

        return is_array($result) ? $result : [];
    }
}
