<?php
declare(strict_types=1);

namespace StoreOnCall\Agent\Model;

/**
 * Read-only database access used by the collectors. Only SELECT statements are run.
 */
interface QueryRunnerInterface
{
    /** @return array<int, array<string, mixed>> */
    public function fetchAll(string $sql, array $bind = []): array;

    /** Real table name, including any table prefix. */
    public function table(string $name): string;

    public function tableExists(string $name): bool;
}
