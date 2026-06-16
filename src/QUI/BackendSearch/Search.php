<?php

/**
 * This file contains QUI\BackendSearch\Search
 */

namespace QUI\BackendSearch;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception as DbalException;
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
        $filterGroups = isset($params["filterGroups"]) && is_array($params["filterGroups"])
            ? array_values(array_filter($params["filterGroups"], "is_string"))
            : [];

        $Connection = QUI::getDataBaseConnection();
        $QueryBuilder = $Connection->createQueryBuilder();

        $QueryBuilder
            ->select("*")
            ->from(DoctrineUtils::quoteIdentifier($DesktopSearch->getTable()))
            ->where(DoctrineUtils::quoteIdentifier("search") . " LIKE :search")
            ->andWhere(DoctrineUtils::quoteIdentifier("lang") . " = :lang")
            ->setParameter("search", "%" . $string . "%")
            ->setParameter("lang", QUI::getUserBySession()->getLang());

        foreach ($DesktopSearch->getWhereConstraint($filterGroups) as $constraint) {
            $QueryBuilder->andWhere($constraint);
        }

        if (!empty($params["group"])) {
            $QueryBuilder
                ->andWhere(DoctrineUtils::quoteIdentifier("group") . " = :group")
                ->setParameter("group", $params["group"]);
        }

        if (!empty($filterGroups)) {
            $QueryBuilder
                ->andWhere(DoctrineUtils::quoteIdentifier("filterGroup") . " IN (:filterGroups)")
                ->setParameter("filterGroups", $filterGroups, ArrayParameterType::STRING);
        }

        if (!empty($params["limit"])) {
            $QueryBuilder->setMaxResults((int)$params["limit"] * 3);
        } else {
            $limit = (int)QUI::getConfig("etc/search.ini.php")->get("general", "maxResultsPerGroup");
            $params["limit"] = $limit;
            $QueryBuilder->setMaxResults($limit);
        }

        try {
            $result = $QueryBuilder->executeQuery()->fetchAllAssociative();
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

        /* @var ProviderInterface $Provider */
        foreach ($providers as $Provider) {
            try {
                $providerResult = $Provider->search($string, $params);
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

        return array_values($result);
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
