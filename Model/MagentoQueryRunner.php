<?php
declare(strict_types=1);

namespace StoreOnCall\Agent\Model;

use Magento\Framework\App\ResourceConnection;

class MagentoQueryRunner implements QueryRunnerInterface
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function fetchAll(string $sql, array $bind = []): array
    {
        if (!preg_match('/^\s*SELECT\s/i', $sql)) {
            throw new \InvalidArgumentException('StoreOnCall only runs SELECT queries.');
        }
        return $this->resource->getConnection()->fetchAll($sql, $bind);
    }

    public function table(string $name): string
    {
        return $this->resource->getTableName($name);
    }

    public function tableExists(string $name): bool
    {
        return $this->resource->getConnection()->isTableExists($this->table($name));
    }
}
