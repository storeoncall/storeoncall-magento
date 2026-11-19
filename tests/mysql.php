<?php
declare(strict_types=1);

// Real-database test: runs the plugin's SQL against MySQL or MariaDB tables built from
// Magento's own db_schema.xml files, with a table prefix, in both PDO prepare modes.
// Skips (exit 0) when SOC_MYSQL_DSN is not set.
// Usage: SOC_MYSQL_DSN="mysql:host=127.0.0.1;port=3307;dbname=magento" SOC_MYSQL_USER=soc SOC_MYSQL_PASS=soc SOC_PREFIX=m2_ php tests/mysql.php [--emit]

$root = dirname(__DIR__);
require $root . '/Model/QueryRunnerInterface.php';
require $root . '/Model/EnvironmentInterface.php';
require $root . '/Model/Collector/SqlCollector.php';
require $root . '/Model/HeartbeatBuilder.php';

use StoreOnCall\Agent\Model\Collector\SqlCollector;
use StoreOnCall\Agent\Model\EnvironmentInterface;
use StoreOnCall\Agent\Model\HeartbeatBuilder;
use StoreOnCall\Agent\Model\QueryRunnerInterface;

final class PdoMysqlRunner implements QueryRunnerInterface
{
    public function __construct(private readonly PDO $pdo, private readonly string $prefix) {}
    public function fetchAll(string $sql, array $bind = []): array
    {
        if (!preg_match('/^\s*SELECT\s/i', $sql)) {
            throw new InvalidArgumentException('only SELECT');
        }
        $st = $this->pdo->prepare($sql);
        $st->execute($bind);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
    public function table(string $name): string { return $this->prefix . $name; }
    public function tableExists(string $name): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $st->execute([$this->table($name)]);
        return (bool)$st->fetchColumn();
    }
}

final class FakeEnv implements EnvironmentInterface
{
    public function magentoVersion(): string { return '2.4.8-p3'; }
    public function edition(): string { return 'Community'; }
    public function mode(): string { return 'production'; }
    public function asyncEmail(): bool { return true; }
    public function baseUrl(): string { return 'https://demo.example/'; }
    public function timezone(): string { return 'UTC'; }
    public function baseCurrency(): string { return 'USD'; }
    public function cacheTypes(): array { return [['type' => 'full_page', 'enabled' => true, 'invalidated' => false]]; }
    public function modules(): array { return [['name' => 'Magento_Catalog', 'version' => '2.4.8']]; }
    public function deployedVersion(): ?string { return '1763400000'; }
}

if (!getenv('SOC_MYSQL_DSN')) {
    echo "skipped: SOC_MYSQL_DSN not set\n";
    exit(0);
}
$p = getenv('SOC_PREFIX') ?: '';
$pdo = new PDO(getenv('SOC_MYSQL_DSN'), getenv('SOC_MYSQL_USER'), getenv('SOC_MYSQL_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET time_zone = '+00:00'");
$pdo->exec("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$now = $now->setTime((int)$now->format('H'), 40, 0); // fixed minute so hour buckets are predictable
$t = static fn (string $mod) => $now->modify($mod)->format('Y-m-d H:i:s');
$ins = static function (string $table, array $row) use ($pdo, $p): void {
    $cols = array_keys($row);
    $pdo->prepare("INSERT INTO {$p}{$table} (`" . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
};

// Fresh tables each run, from Magento's own schema.
$ddl = str_replace('{{p}}', $p, (string)file_get_contents(__DIR__ . '/fixtures/magento-schema.sql'));
$ddl = preg_replace('/^--.*$/m', '', $ddl);
preg_match_all('/CREATE TABLE `([^`]+)`/', $ddl, $m);
foreach ($m[1] as $tb) {
    $pdo->exec("DROP TABLE IF EXISTS `{$tb}`");
}
foreach (array_filter(array_map('trim', explode(';', $ddl))) as $stmt) {
    $pdo->exec($stmt);
}
$pdo->exec("DROP TABLE IF EXISTS {$p}catalog_product_price_cl");
$pdo->exec("CREATE TABLE {$p}catalog_product_price_cl (version_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, entity_id INT UNSIGNED NOT NULL DEFAULT 0)");

// Cron.
$ins('cron_schedule', ['job_code' => 'indexer_reindex_all_invalid', 'status' => 'success', 'created_at' => $t('-4 minutes'), 'scheduled_at' => $t('-3 minutes'), 'executed_at' => $t('-3 minutes'), 'finished_at' => $t('-2 minutes')]);
for ($i = 0; $i < 3; $i++) {
    $ins('cron_schedule', ['job_code' => 'sales_send_order_emails', 'status' => 'error', 'messages' => 'SMTP error for jane@customer.example', 'created_at' => $t('-1 hours'), 'scheduled_at' => $t("-{$i}0 minutes")]);
}
for ($i = 0; $i < 12; $i++) {
    $ins('cron_schedule', ['job_code' => 'indexer_update_all_views', 'status' => 'missed', 'created_at' => $t('-1 hours'), 'scheduled_at' => $t('-' . (5 + $i) . ' minutes')]);
}
$ins('cron_schedule', ['job_code' => 'consumers_runner', 'status' => 'running', 'created_at' => $t('-3 hours'), 'scheduled_at' => $t('-2 hours'), 'executed_at' => $t('-2 hours')]);
for ($i = 0; $i < 4; $i++) {
    $ins('cron_schedule', ['job_code' => 'newsletter_send_all', 'status' => 'pending', 'created_at' => $t('-5 minutes'), 'scheduled_at' => $t('-1 minutes')]);
}
$ins('cron_schedule', ['job_code' => 'future_job', 'status' => 'pending', 'created_at' => $t('-1 minutes'), 'scheduled_at' => $t('+10 minutes')]);

// Indexers: price on schedule with a 1200-row backlog; stock invalid.
$ins('indexer_state', ['indexer_id' => 'catalog_product_price', 'status' => 'valid', 'updated' => $t('-1 hours'), 'hash_config' => 'x']);
$ins('indexer_state', ['indexer_id' => 'cataloginventory_stock', 'status' => 'invalid', 'updated' => $t('-2 hours'), 'hash_config' => 'x']);
$ins('mview_state', ['view_id' => 'catalog_product_price', 'mode' => 'enabled', 'status' => 'idle', 'updated' => $t('-1 hours'), 'version_id' => 300]);
$pdo->exec("INSERT INTO {$p}catalog_product_price_cl (entity_id) VALUES " . implode(",", array_map(static fn ($i) => "($i)", range(1, 1500))));

// Queues.
$ins('queue', ['id' => 1, 'name' => 'inventory.reservations.update']);
for ($i = 0; $i < 5; $i++) {
    $ins('queue_message_status', ['queue_id' => 1, 'message_id' => $i + 1, 'updated_at' => $t('-5 minutes'), 'status' => $i < 3 ? 2 : 6, 'number_of_trials' => 1]);
}

// Orders (now is HH:40): five in the previous clock hour (one pending payment), one in this hour (5 minutes old). Plus 2 old "new" orders waiting.
$orders = [
    [$t('-100 minutes'), 'complete', 'checkmo', 1, 1], [$t('-95 minutes'), 'processing', 'stripe_payments', 1, 1], [$t('-90 minutes'), 'processing', 'stripe_payments', 1, 1],
    [$t('-60 minutes'), 'pending_payment', 'stripe_payments', 1, 0], [$t('-50 minutes'), 'processing', 'checkmo', 1, 0], [$t('-5 minutes'), 'new', 'checkmo', 1, 0],
    [$t('-3 days'), 'new', 'banktransfer', 0, 0], [$t('-30 hours'), 'new', 'banktransfer', 0, 0],
];
foreach ($orders as $i => [$at, $state, $method, $send, $sent]) {
    $ins('sales_order', ['entity_id' => $i + 1, 'state' => $state, 'status' => $state, 'created_at' => $at, 'updated_at' => $at, 'base_grand_total' => 100.5, 'send_email' => $send, 'email_sent' => $sent,
        'customer_email' => "buyer{$i}@customer.example", 'customer_firstname' => 'Jane', 'customer_lastname' => 'Doe', 'remote_ip' => '203.0.113.9', 'increment_id' => (string)(100000 + $i), 'store_id' => 1]);
    $ins('sales_order_payment', ['parent_id' => $i + 1, 'method' => $method, 'additional_information' => '{"cc_last4":"4242"}']);
}

// Config: one change since the last report.
$ins('core_config_data', ['scope' => 'default', 'scope_id' => 0, 'path' => 'payment/stripe_payments/active', 'value' => '0', 'updated_at' => $t('-4 minutes')]);
$ins('core_config_data', ['scope' => 'default', 'scope_id' => 0, 'path' => 'web/secure/base_url', 'value' => 'https://demo.example/', 'updated_at' => $t('-30 days')]);

// Catalog: the same 9 products as the SQLite test.
$ins('eav_entity_type', ['entity_type_id' => 4, 'entity_type_code' => 'catalog_product', 'entity_model' => 'Magento\\Catalog\\Model\\ResourceModel\\Product']);
$ins('eav_entity_type', ['entity_type_id' => 3, 'entity_type_code' => 'catalog_category', 'entity_model' => 'Magento\\Catalog\\Model\\ResourceModel\\Category']);
foreach ([[97, 4, 'status', 'int'], [99, 4, 'visibility', 'int'], [77, 4, 'price', 'decimal'], [45, 3, 'status', 'int']] as [$id, $type, $code, $backend]) {
    $ins('eav_attribute', ['attribute_id' => $id, 'entity_type_id' => $type, 'attribute_code' => $code, 'backend_type' => $backend]);
}
$products = [[1, 'simple', 1, 4, 30.0, true, true], [2, 'simple', 1, 4, 0.0, true, true], [3, 'simple', 1, 4, null, false, true], [4, 'configurable', 1, 4, null, true, false],
    [5, 'simple', 1, 1, 0.0, false, true], [6, 'simple', 2, 4, 0.0, false, true], [7, 'simple', 1, 4, 10.0, true, false], [8, 'simple', 1, 4, 5.0, true, true], [9, 'simple', 1, 1, 12.0, false, false]];
foreach ($products as [$id, $type, $status, $vis, $price, $cat, $stock]) {
    $ins('catalog_product_entity', ['entity_id' => $id, 'attribute_set_id' => 4, 'type_id' => $type, 'sku' => sprintf('MB-%02d', $id), 'created_at' => $t('-30 days'), 'updated_at' => $t('-' . (10 * $id) . ' minutes')]);
    $ins('catalog_product_entity_int', ['attribute_id' => 97, 'store_id' => 0, 'entity_id' => $id, 'value' => $status]);
    $ins('catalog_product_entity_int', ['attribute_id' => 99, 'store_id' => 0, 'entity_id' => $id, 'value' => $vis]);
    if ($price !== null) {
        $ins('catalog_product_entity_decimal', ['attribute_id' => 77, 'store_id' => 0, 'entity_id' => $id, 'value' => $price]);
    }
    if ($cat) {
        $ins('catalog_category_product', ['category_id' => 3, 'product_id' => $id, 'position' => 0]);
    }
    $ins('cataloginventory_stock_item', ['product_id' => $id, 'stock_id' => 1, 'website_id' => 0, 'qty' => $stock ? 5.5 : 0, 'is_in_stock' => $stock ? 1 : 0]);
}
// An order line carries its order's date; product 7 last sold in an order 120 days ago. Product 4 was renamed since it sold.
$ins('sales_order', ['entity_id' => 99, 'state' => 'complete', 'status' => 'complete', 'created_at' => $t('-120 days'), 'updated_at' => $t('-120 days'), 'base_grand_total' => 500, 'increment_id' => '100099', 'store_id' => 1]);
$items = [[1, 4, 'configurable', 'OLD-SKU-04', 9, null, '-3 days'], [1, 9, 'simple', 'MB-09', 9, 1, '-3 days'], [2, 1, 'simple', 'MB-01', 5, null, '-2 days'], [3, 8, 'simple', 'MB-08', 2, null, '-1 days'], [99, 7, 'simple', 'MB-07', 50, null, '-120 days']];
foreach ($items as $k => [$order, $pid, $ptype, $sku, $qty, $parent, $ago]) {
    $ins('sales_order_item', ['item_id' => $k + 1, 'order_id' => $order, 'parent_item_id' => $parent, 'product_id' => $pid, 'product_type' => $ptype, 'sku' => $sku, 'qty_ordered' => $qty, 'created_at' => $t($ago), 'updated_at' => $t($ago), 'store_id' => 1]);
}

$fails = 0;
$check = static function (string $name, bool $ok) use (&$fails): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) {
        $fails++;
    }
};

foreach ([true, false] as $emulate) {
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulate);
    $mode = $emulate ? 'emulated' : 'native';
    $builder = new HeartbeatBuilder(new SqlCollector(new PdoMysqlRunner($pdo, $p)), new FakeEnv());
    $hb = $builder->build($now, null);
    if (in_array('--emit', $argv, true)) {
        echo json_encode($hb, JSON_UNESCAPED_SLASHES);
        exit(0);
    }
    $c = $hb['cron'];
    $check("[$mode] cron last success 2 minutes ago", $c['last_success_at'] === $now->modify('-2 minutes')->format('Y-m-d\TH:i:s\Z'));
    $check("[$mode] cron pending counts only due jobs", $c['pending'] === 4);
    $check("[$mode] cron errors, missed and stuck", $c['errors_last_hour'] === 3 && $c['missed_last_hour'] === 12 && $c['stuck_running'] === 1);
    $check("[$mode] failed job codes only", $c['failed_jobs'] === ['sales_send_order_emails']);
    $idx = array_column($hb['indexers'], null, 'id');
    $check("[$mode] price indexer on schedule with backlog 1200", ($idx['catalog_product_price']['mode'] ?? '') === 'schedule' && ($idx['catalog_product_price']['backlog'] ?? 0) === 1200);
    $check("[$mode] stock indexer invalid", ($idx['cataloginventory_stock']['status'] ?? '') === 'invalid');
    $check("[$mode] queue pending and errors", $hb['queues'] === [['name' => 'inventory.reservations.update', 'pending' => 3, 'errors' => 2]]);
    $check("[$mode] three hourly order buckets: 0, 5, 1 orders and revenue 502.5 in the middle hour", array_column($hb['orders'], 'count') === [0, 5, 1] && abs($hb['orders'][1]['revenue'] - 502.5) < 0.001);
    $check("[$mode] payments: pending payment is a problem; orders under 30 minutes left out", array_sum(array_column($hb['payments'], 'orders')) === 5 && array_sum(array_column($hb['payments'], 'problem_orders')) === 1);
    $check("[$mode] unsent order emails counted", $hb['emails']['unsent_order_emails'] === 1);
    $check("[$mode] first report sends no config changes", $hb['config_changes'] === []);
    $cat = $hb['catalog'] ?? [];
    $check("[$mode] catalog counts: 6 products, 2 zero price, 1 no category", ($cat['products'] ?? -1) === 6 && ($cat['zero_price'] ?? -1) === 2 && ($cat['no_category'] ?? -1) === 1);
    $check("[$mode] best-seller out of stock, by its current SKU", ($cat['bestsellers_out_of_stock'] ?? null) === ['MB-04'] && ($cat['bestsellers_checked'] ?? 0) === 3);
    $f = $cat['feeds'] ?? [];
    $check("[$mode] feeds: last product change, stock fingerprint, 2 orders waiting", ($f['products_updated_at'] ?? null) === $now->modify('-10 minutes')->format('Y-m-d\TH:i:s\Z') && ($f['stock_qty'] ?? null) == 33.0 && ($f['stock_in_stock'] ?? null) === 6 && ($f['orders_waiting'] ?? null) === 2);
    $json = json_encode($hb, JSON_UNESCAPED_SLASHES);
    $check("[$mode] no customer data anywhere", !preg_match('/customer\.example|Jane|Doe|203\.0\.113|4242/', $json));
}

// Config changes need a "last sent" time.
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$withSince = (new SqlCollector(new PdoMysqlRunner($pdo, $p)))->configChanges($now->modify('-5 minutes'));
$check('[native] config change since the last report, path only', $withSince === [['path' => 'payment/stripe_payments/active', 'at' => $now->modify('-4 minutes')->format('Y-m-d\TH:i:s\Z')]]);

echo $fails ? "\n{$fails} failed\n" : "\nAll MySQL checks passed\n";
exit($fails ? 1 : 0);
