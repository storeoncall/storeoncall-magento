<?php
declare(strict_types=1);

use StoreOnCall\Agent\Model\QueryRunnerInterface;

/** Test double: runs the collectors' SQL against SQLite copies of Magento tables. */
final class SqliteRunner implements QueryRunnerInterface
{
    public function __construct(public readonly PDO $pdo)
    {
    }

    public function fetchAll(string $sql, array $bind = []): array
    {
        if (!preg_match('/^\s*SELECT\s/i', $sql)) {
            throw new InvalidArgumentException('only SELECT');
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($bind);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function table(string $name): string
    {
        return $name;
    }

    public function tableExists(string $name): bool
    {
        $st = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
        $st->execute([$name]);
        return (bool)$st->fetchColumn();
    }
}
