<?php
declare(strict_types=1);

namespace StoreOnCall\Agent\Model\Collector;

use StoreOnCall\Agent\Model\QueryRunnerInterface;

/**
 * Everything the agent reads from the database. Every query returns counts,
 * statuses, job codes or totals only. No customer, address or order detail
 * columns are ever selected.
 */
class SqlCollector
{
    public function __construct(private readonly QueryRunnerInterface $db)
    {
    }

    private static function iso(?string $mysqlUtc): ?string
    {
        if ($mysqlUtc === null || $mysqlUtc === '' || str_starts_with($mysqlUtc, '0000')) {
            return null;
        }
        return (new \DateTimeImmutable($mysqlUtc, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    private static function sqlTime(\DateTimeImmutable $t): string
    {
        return $t->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function cron(\DateTimeImmutable $now): array
    {
        $t = $this->db->table('cron_schedule');
        $hourAgo = self::sqlTime($now->modify('-1 hour'));
        $nowSql = self::sqlTime($now);
        $row = $this->db->fetchAll(
            "SELECT
                MAX(CASE WHEN status = 'success' THEN finished_at END) AS last_success,
                MAX(executed_at) AS last_run,
                SUM(CASE WHEN status = 'pending' AND scheduled_at <= :now THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) AS running,
                SUM(CASE WHEN status = 'error' AND scheduled_at >= :hour THEN 1 ELSE 0 END) AS errors,
                SUM(CASE WHEN status = 'missed' AND scheduled_at >= :hour2 THEN 1 ELSE 0 END) AS missed,
                SUM(CASE WHEN status = 'running' AND executed_at < :stuck THEN 1 ELSE 0 END) AS stuck
             FROM {$t}",
            ['now' => $nowSql, 'hour' => $hourAgo, 'hour2' => $hourAgo, 'stuck' => $hourAgo]
        )[0] ?? [];
        $failed = $this->db->fetchAll(
            "SELECT DISTINCT job_code FROM {$t} WHERE status = 'error' AND scheduled_at >= :hour ORDER BY job_code",
            ['hour' => $hourAgo]
        );
        return [
            'last_success_at' => self::iso($row['last_success'] ?? null),
            'last_run_at' => self::iso($row['last_run'] ?? null),
            'pending' => (int)($row['pending'] ?? 0),
            'running' => (int)($row['running'] ?? 0),
            'errors_last_hour' => (int)($row['errors'] ?? 0),
            'missed_last_hour' => (int)($row['missed'] ?? 0),
            'stuck_running' => (int)($row['stuck'] ?? 0),
            'failed_jobs' => array_slice(array_map(static fn ($r) => (string)$r['job_code'], $failed), 0, 50),
        ];
    }

    public function indexers(): array
    {
        $out = [];
        $states = $this->db->fetchAll('SELECT indexer_id, status, updated FROM ' . $this->db->table('indexer_state'));
        $views = [];
        if ($this->db->tableExists('mview_state')) {
            foreach ($this->db->fetchAll('SELECT view_id, mode, version_id FROM ' . $this->db->table('mview_state')) as $v) {
                $views[(string)$v['view_id']] = $v;
            }
        }
        foreach ($states as $s) {
            $id = (string)$s['indexer_id'];
            $view = $views[$id] ?? null;
            $schedule = $view !== null && $view['mode'] === 'enabled';
            $backlog = 0;
            if ($schedule && $this->db->tableExists($id . '_cl')) {
                $max = $this->db->fetchAll('SELECT MAX(version_id) AS v FROM ' . $this->db->table($id . '_cl'))[0]['v'] ?? 0;
                $backlog = max(0, (int)$max - (int)$view['version_id']);
            }
            $out[] = [
                'id' => $id,
                'status' => (string)$s['status'],
                'mode' => $schedule ? 'schedule' : 'realtime',
                'backlog' => $backlog,
                'updated_at' => self::iso($s['updated'] ?? null),
            ];
        }
        return $out;
    }

    public function queues(): array
    {
        if (!$this->db->tableExists('queue_message_status') || !$this->db->tableExists('queue')) {
            return [];
        }
        // Status codes: 2 new, 3 in progress, 5 retry required, 6 error.
        $rows = $this->db->fetchAll(
            'SELECT q.name AS name,
                    SUM(CASE WHEN s.status IN (2, 3, 5) THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN s.status = 6 THEN 1 ELSE 0 END) AS errors
             FROM ' . $this->db->table('queue_message_status') . ' s
             JOIN ' . $this->db->table('queue') . ' q ON q.id = s.queue_id
             GROUP BY q.name'
        );
        return array_map(static fn ($r) => ['name' => (string)$r['name'], 'pending' => (int)$r['pending'], 'errors' => (int)$r['errors']], $rows);
    }

    /** Orders and base-currency revenue per hour for the last 3 hours, zero-filled. */
    public function orders(\DateTimeImmutable $now): array
    {
        $utc = $now->setTimezone(new \DateTimeZone('UTC'));
        $start = $utc->setTime((int)$utc->format('H'), 0)->modify('-2 hours');
        $rows = $this->db->fetchAll(
            'SELECT SUBSTR(created_at, 1, 13) AS h, COUNT(*) AS n, SUM(base_grand_total) AS revenue
             FROM ' . $this->db->table('sales_order') . ' WHERE created_at >= :since GROUP BY SUBSTR(created_at, 1, 13)',
            ['since' => self::sqlTime($start)]
        );
        $byHour = [];
        foreach ($rows as $r) {
            $byHour[(string)$r['h']] = $r;
        }
        $out = [];
        for ($i = 0; $i < 3; $i++) {
            $h = $start->modify("+{$i} hours");
            $key = $h->format('Y-m-d H');
            $out[] = [
                'hour' => $h->format('Y-m-d\TH:00:00\Z'),
                'count' => (int)($byHour[$key]['n'] ?? 0),
                'revenue' => round((float)($byHour[$key]['revenue'] ?? 0), 2),
            ];
        }
        return $out;
    }

    /**
     * Orders per payment method per hour, and how many are stuck in pending payment
     * or cancelled. Orders under 30 minutes old are left out: they may still be paying.
     */
    public function payments(\DateTimeImmutable $now): array
    {
        $rows = $this->db->fetchAll(
            "SELECT SUBSTR(o.created_at, 1, 13) AS h, p.method AS method, COUNT(*) AS n,
                    SUM(CASE WHEN o.state IN ('pending_payment', 'canceled') THEN 1 ELSE 0 END) AS problems
             FROM " . $this->db->table('sales_order') . ' o
             JOIN ' . $this->db->table('sales_order_payment') . ' p ON p.parent_id = o.entity_id
             WHERE o.created_at >= :since AND o.created_at <= :cutoff
             GROUP BY SUBSTR(o.created_at, 1, 13), p.method',
            ['since' => self::sqlTime($now->modify('-3 hours')), 'cutoff' => self::sqlTime($now->modify('-30 minutes'))]
        );
        return array_map(static fn ($r) => [
            'hour' => str_replace(' ', 'T', (string)$r['h']) . ':00:00Z',
            'method' => (string)$r['method'],
            'orders' => (int)$r['n'],
            'problem_orders' => (int)$r['problems'],
        ], $rows);
    }

    /**
     * Orders from the last day, older than 15 minutes, whose confirmation email was never sent.
     * Orders still waiting for payment or cancelled are left out: Magento only emails those once paid.
     */
    public function unsentOrderEmails(\DateTimeImmutable $now): int
    {
        $row = $this->db->fetchAll(
            'SELECT COUNT(*) AS n FROM ' . $this->db->table('sales_order') . '
             WHERE created_at >= :since AND created_at <= :cutoff AND send_email = 1 AND (email_sent IS NULL OR email_sent = 0)
               AND state NOT IN (\'pending_payment\', \'canceled\')',
            ['since' => self::sqlTime($now->modify('-24 hours')), 'cutoff' => self::sqlTime($now->modify('-15 minutes'))]
        );
        return (int)($row[0]['n'] ?? 0);
    }

    /** Configuration paths changed since the given time. Values are never read. */
    public function configChanges(?\DateTimeImmutable $since): array
    {
        if ($since === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT path, updated_at FROM ' . $this->db->table('core_config_data') . ' WHERE updated_at > :since ORDER BY updated_at LIMIT 200',
            ['since' => self::sqlTime($since)]
        );
        return array_map(static fn ($r) => ['path' => (string)$r['path'], 'at' => self::iso((string)$r['updated_at'])], $rows);
    }

    /**
     * Catalog health, read hourly: how many enabled, visible products have no
     * price or a price of zero, how many are in no category, and which of the
     * best-selling products of the last 30 days are out of stock (SKUs only).
     * Default scope values only. On Adobe Commerce with content staging the
     * current version of each product is read.
     */
    public function catalog(\DateTimeImmutable $now, bool $staging = false): array
    {
        $types = $this->db->fetchAll(
            "SELECT a.attribute_id AS id, a.attribute_code AS code FROM " . $this->db->table('eav_attribute') . " a
             JOIN " . $this->db->table('eav_entity_type') . " t ON t.entity_type_id = a.entity_type_id
             WHERE t.entity_type_code = 'catalog_product' AND a.attribute_code IN ('status', 'visibility', 'price')"
        );
        $attr = [];
        foreach ($types as $r) {
            $attr[(string)$r['code']] = (int)$r['id'];
        }
        if (count($attr) < 3) {
            return [];
        }
        $link = $staging ? 'row_id' : 'entity_id';
        $e = $this->db->table('catalog_product_entity');
        $int = $this->db->table('catalog_product_entity_int');
        $dec = $this->db->table('catalog_product_entity_decimal');
        $live = "FROM {$e} e
             JOIN {$int} st ON st.{$link} = e.{$link} AND st.attribute_id = :st AND st.store_id = 0 AND st.value = 1
             JOIN {$int} vi ON vi.{$link} = e.{$link} AND vi.attribute_id = :vi AND vi.store_id = 0 AND vi.value IN (2, 3, 4)";
        $bind = ['st' => $attr['status'], 'vi' => $attr['visibility']];
        if ($staging) {
            $live .= ' WHERE e.created_in <= :ts1 AND e.updated_in > :ts2';
            $bind += ['ts1' => $now->getTimestamp(), 'ts2' => $now->getTimestamp()];
        } else {
            $live .= ' WHERE 1 = 1';
        }

        $products = (int)($this->db->fetchAll("SELECT COUNT(*) AS n {$live}", $bind)[0]['n'] ?? 0);
        $zero = (int)($this->db->fetchAll(
            "SELECT COUNT(*) AS n " . str_replace(' WHERE ', " LEFT JOIN {$dec} pr ON pr.{$link} = e.{$link} AND pr.attribute_id = :pr AND pr.store_id = 0 WHERE ", $live) . "
             AND e.type_id IN ('simple', 'virtual', 'downloadable') AND (pr.value IS NULL OR pr.value <= 0)",
            $bind + ['pr' => $attr['price']]
        )[0]['n'] ?? 0);
        $noCategory = (int)($this->db->fetchAll(
            "SELECT COUNT(*) AS n {$live} AND NOT EXISTS (SELECT 1 FROM " . $this->db->table('catalog_category_product') . " c WHERE c.product_id = e.entity_id)",
            $bind
        )[0]['n'] ?? 0);

        // Best-sellers: top 20 by quantity ordered in the last 30 days. Filtered on the order's date,
        // which Magento indexes, so stores with years of orders do not read every order line ever placed.
        $top = $this->db->fetchAll(
            "SELECT i.product_id, SUM(i.qty_ordered) AS q FROM " . $this->db->table('sales_order') . " o
             JOIN " . $this->db->table('sales_order_item') . " i ON i.order_id = o.entity_id
             WHERE o.created_at >= :since AND i.parent_item_id IS NULL AND i.product_id IS NOT NULL
               AND i.product_type IN ('simple', 'configurable', 'virtual', 'downloadable')
             GROUP BY i.product_id ORDER BY q DESC, i.product_id LIMIT 20",
            ['since' => self::sqlTime($now->modify('-30 days'))]
        );
        $out = [];
        if ($top) {
            $ids = array_map(static fn ($r) => (int)$r['product_id'], $top);
            // The product's current SKU (an order keeps the SKU it had then, which may since have changed).
            $rows = $this->db->fetchAll(
                'SELECT s.product_id, p.sku FROM ' . $this->db->table('cataloginventory_stock_item') . ' s
                 JOIN ' . $this->db->table('catalog_product_entity') . ' p ON p.entity_id = s.product_id
                 WHERE s.is_in_stock = 0 AND s.stock_id = 1 AND s.product_id IN (' . implode(',', $ids) . ')'
            );
            $oos = [];
            foreach ($rows as $r) {
                $oos[(int)$r['product_id']] = (string)$r['sku'];
            }
            foreach ($top as $r) {
                $id = (int)$r['product_id'];
                if (isset($oos[$id]) && count($out) < 10 && !in_array($oos[$id], $out, true)) {
                    $out[] = substr($oos[$id], 0, 64);
                }
            }
        }
        return [
            'products' => $products,
            'zero_price' => $zero,
            'no_category' => $noCategory,
            'bestsellers_checked' => count($top),
            'bestsellers_out_of_stock' => $out,
            'feeds' => $this->safeFeeds($now),
        ];
    }

    private function safeFeeds(\DateTimeImmutable $now): array
    {
        try {
            return $this->feeds($now);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Data feeds, whatever ERP or PIM sends them: when products last changed, a fingerprint of
     * stock levels (total quantity and how many items are in stock), and how many orders have
     * waited over 2 hours in the "new" state (pending), which an ERP usually picks up.
     * Counts and times only.
     */
    public function feeds(\DateTimeImmutable $now): array
    {
        $updated = $this->db->fetchAll('SELECT MAX(updated_at) AS t FROM ' . $this->db->table('catalog_product_entity'))[0]['t'] ?? null;
        $stock = $this->db->fetchAll(
            'SELECT COALESCE(SUM(qty), 0) AS q, COALESCE(SUM(is_in_stock), 0) AS s FROM ' . $this->db->table('cataloginventory_stock_item') . ' WHERE stock_id = 1'
        )[0] ?? ['q' => 0, 's' => 0];
        $waiting = $this->db->fetchAll(
            "SELECT COUNT(*) AS n FROM " . $this->db->table('sales_order') . " WHERE state = 'new' AND created_at < :old AND created_at >= :week",
            ['old' => self::sqlTime($now->modify('-2 hours')), 'week' => self::sqlTime($now->modify('-7 days'))]
        )[0]['n'] ?? 0;
        return [
            'products_updated_at' => self::iso($updated === null ? null : (string)$updated),
            'stock_qty' => round((float)$stock['q'], 4),
            'stock_in_stock' => (int)$stock['s'],
            'orders_waiting' => (int)$waiting,
        ];
    }
}
