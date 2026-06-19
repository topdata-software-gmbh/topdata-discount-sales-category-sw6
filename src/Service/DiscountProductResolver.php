<?php declare(strict_types=1);

namespace Topdata\TopdataDiscountSalesCategorySW6\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;

class DiscountProductResolver
{
    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @return string[] hex-encoded UUIDs
     */
    public function getDiscountedProductIds(): array
    {
        $now = (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT);

        $streamIds = $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(d.discount_product_stream_id))
             FROM prems_discount_category_base d
             WHERE d.active = 1
               AND (d.valid_from IS NULL OR d.valid_from <= :now)
               AND (d.valid_until IS NULL OR d.valid_until >= :now)
               AND d.discount_product_stream_id IS NOT NULL',
            ['now' => $now],
            ['now' => ParameterType::STRING]
        );

        if (empty($streamIds)) {
            return [];
        }

        return $this->connection->fetchFirstColumn(
            'SELECT DISTINCT LOWER(HEX(m.product_id))
             FROM product_stream_mapping m
             WHERE m.product_stream_id IN (:streamIds)
               AND m.product_version_id = :versionId',
            [
                'streamIds' => Uuid::fromHexToBytesList($streamIds),
                'versionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ],
            [
                'streamIds' => ArrayParameterType::STRING,
                'versionId' => ParameterType::STRING,
            ]
        );
    }
}
