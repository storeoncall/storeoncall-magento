<?php
declare(strict_types=1);

// Plugin tests without a Magento install: the collectors run their real SQL
// against SQLite copies of the Magento tables they read.
// Usage: php tests/run.php            (runs assertions)
//        php tests/run.php --emit     (prints a heartbeat JSON for the server-side contract test)

$root = dirname(__DIR__);
require $root . '/Model/QueryRunnerInterface.php';
require $root . '/Model/EnvironmentInterface.php';
require $root . '/Model/Collector/SqlCollector.php';
require $root . '/Model/HeartbeatBuilder.php';
require __DIR__ . '/SqliteRunner.php';

use StoreOnCall\Agent\Model\Collector\SqlCollector;
use StoreOnCall\Agent\Model\EnvironmentInterface;
use StoreOnCall\Agent\Model\HeartbeatBuilder;

final class FakeEnv implements EnvironmentInterface
{
    public function magentoVersion(): string { return '2.4.7-p3'; }
    public function edition(): string { return 'Community'; }
    public function mode(): string { return 'production'; }
    public function asyncEmail(): bool { return true; }
    public function baseUrl(): string { return 'https://demo.example/'; }
    public function timezone(): string { return 'Asia/Kolkata'; }
    public function baseCurrency(): string { return 'INR'; }
    public function cacheTypes(): array { return [['type' => 'config', 'enabled' => true, 'invalidated' => false], ['type' => 'full_page', 'enabled' => true, 'invalidated' => true]]; }
    public function modules(): array { return [['name' => 'amasty/module-checkout', 'version' => '2.1.0'], ['name' => 'Acme_Custom', 'version' => 'app-code']]; }
    public function deployedVersion(): ?string { return '1731900000'; }
}

$now = new DateTimeImmutable('2026-11-18 10:40:00', new DateTimeZone('UTC'));
$t = static fn (string $mod) => $now->modify($mod)->format('Y-m-d H:i:s');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(<<<SQL
CREATE TABLE cron_schedule (schedule_id INTEGER PRIMARY KEY, job_code TEXT, status TEXT, messages TEXT, created_at TEXT, scheduled_at TEXT, executed_at TEXT, finished_at TEXT);
CREATE TABLE indexer_state (state_id INTEGER PRIMARY KEY, indexer_id TEXT, status TEXT, updated TEXT, hash_config TEXT);
CREATE TABLE mview_state (state_id INTEGER PRIMARY KEY, view_id TEXT, mode TEXT, status TEXT, updated TEXT, version_id INTEGER);
CREATE TABLE catalog_product_price_cl (version_id INTEGER PRIMARY KEY, entity_id INTEGER);
CREATE TABLE queue (id INTEGER PRIMARY KEY, name TEXT);
CREATE TABLE queue_message_status (id INTEGER PRIMARY KEY, queue_id INTEGER, message_id INTEGER, updated_at TEXT, status INTEGER, number_of_trials INTEGER);
CREATE TABLE sales_order (entity_id INTEGER PRIMARY KEY, state TEXT, status TEXT, created_at TEXT, base_grand_total REAL, send_email INTEGER, email_sent INTEGER,
  customer_email TEXT, customer_firstname TEXT, customer_lastname TEXT, remote_ip TEXT);
CREATE TABLE sales_order_payment (entity_id INTEGER PRIMARY KEY, parent_id INTEGER, method TEXT, additional_information TEXT);
CREATE TABLE core_config_data (config_id INTEGER PRIMARY KEY, scope TEXT, scope_id INTEGER, path TEXT, value TEXT, updated_at TEXT);
SQL);

// Catalog tables, as on Magento Open Source (entity_id) or Adobe Commerce with staging (row_id).
$catalogTables = static function (PDO $pdo, string $link): void {
    $staging = $link === 'row_id' ? 'row_id INTEGER PRIMARY KEY, entity_id INTEGER, created_in INTEGER, updated_in INTEGER,' : 'entity_id INTEGER PRIMARY KEY,';
    $pdo->exec(<<<SQL
CREATE TABLE eav_entity_type (entity_type_id INTEGER PRIMARY KEY, entity_type_code TEXT);
CREATE TABLE eav_attribute (attribute_id INTEGER PRIMARY KEY, entity_type_id INTEGER, attribute_code TEXT);
CREATE TABLE catalog_product_entity ({$staging} type_id TEXT, sku TEXT, updated_at TEXT);
CREATE TABLE catalog_product_entity_int (value_id INTEGER PRIMARY KEY, attribute_id INTEGER, store_id INTEGER, {$link} INTEGER, value INTEGER);
CREATE TABLE catalog_product_entity_decimal (value_id INTEGER PRIMARY KEY, attribute_id INTEGER, store_id INTEGER, {$link} INTEGER, value REAL);
CREATE TABLE catalog_category_product (entity_id INTEGER PRIMARY KEY, category_id INTEGER, product_id INTEGER);
CREATE TABLE IF NOT EXISTS sales_order (entity_id INTEGER PRIMARY KEY, state TEXT, created_at TEXT);
CREATE TABLE sales_order_item (item_id INTEGER PRIMARY KEY, order_id INTEGER, parent_item_id INTEGER, product_id INTEGER, product_type TEXT, sku TEXT, qty_ordered REAL, created_at TEXT);
CREATE TABLE cataloginventory_stock_item (item_id INTEGER PRIMARY KEY, product_id INTEGER, stock_id INTEGER, qty REAL, is_in_stock INTEGER);
INSERT INTO eav_entity_type VALUES (4, 'catalog_product'), (3, 'catalog_category');
INSERT INTO eav_attribute VALUES (97, 4, 'status'), (99, 4, 'visibility'), (77, 4, 'price'), (45, 3, 'status');
SQL);
};
$catalogTables($pdo, 'entity_id');

$ins = static function (PDO $pdo, string $table, array $row): void {
    $cols = array_keys($row);
    $pdo->prepare("INSERT INTO {$table} (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
};

// Cron: one success 2 minutes ago, 3 errors from one job, 12 missed, one stuck for 2 hours, 4 pending due.
$ins($pdo, 'cron_schedule', ['job_code' => 'catalog_index_refresh_price', 'status' => 'success', 'scheduled_at' => $t('-3 minutes'), 'executed_at' => $t('-3 minutes'), 'finished_at' => $t('-2 minutes')]);
for ($i = 0; $i < 3; $i++) {
    $ins($pdo, 'cron_schedule', ['job_code' => 'sales_send_order_emails', 'status' => 'error', 'messages' => 'SMTP error for jane@customer.example', 'scheduled_at' => $t("-{$i}0 minutes")]);
}
for ($i = 0; $i < 12; $i++) {
    $ins($pdo, 'cron_schedule', ['job_code' => 'indexer_update_all_views', 'status' => 'missed', 'scheduled_at' => $t('-' . (5 + $i) . ' minutes')]);
}
$ins($pdo, 'cron_schedule', ['job_code' => 'consumers_runner', 'status' => 'running', 'scheduled_at' => $t('-2 hours'), 'executed_at' => $t('-2 hours')]);
for ($i = 0; $i < 4; $i++) {
    $ins($pdo, 'cron_schedule', ['job_code' => 'newsletter_send_all', 'status' => 'pending', 'scheduled_at' => $t('-1 minutes')]);
}
$ins($pdo, 'cron_schedule', ['job_code' => 'future_job', 'status' => 'pending', 'scheduled_at' => $t('+10 minutes')]);

// Indexers: price on schedule with a 1200-row backlog; stock invalid.
$ins($pdo, 'indexer_state', ['indexer_id' => 'catalog_product_price', 'status' => 'valid', 'updated' => $t('-1 hours')]);
$ins($pdo, 'indexer_state', ['indexer_id' => 'cataloginventory_stock', 'status' => 'invalid', 'updated' => $t('-2 hours')]);
$ins($pdo, 'mview_state', ['view_id' => 'catalog_product_price', 'mode' => 'enabled', 'status' => 'idle', 'version_id' => 300]);
$pdo->exec('WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM n WHERE x < 1500) INSERT INTO catalog_product_price_cl (version_id, entity_id) SELECT x, x FROM n');

// Queues.
$ins($pdo, 'queue', ['id' => 1, 'name' => 'inventory.reservations.update']);
for ($i = 0; $i < 5; $i++) {
    $ins($pdo, 'queue_message_status', ['queue_id' => 1, 'message_id' => $i, 'status' => $i < 3 ? 2 : 6]);
}

// Orders: 2 hours ago 3 orders; 1 hour ago 2 orders (one stuck in pending payment); this hour 1 order 5 min old.
$orders = [
    [$t('-100 minutes'), 'complete', 'checkmo', 1, 1], [$t('-95 minutes'), 'processing', 'stripe_payments', 1, 1], [$t('-90 minutes'), 'processing', 'stripe_payments', 1, 1],
    [$t('-60 minutes'), 'pending_payment', 'stripe_payments', 1, 0], [$t('-50 minutes'), 'processing', 'checkmo', 1, 0], [$t('-5 minutes'), 'new', 'checkmo', 1, 0],
];
foreach ($orders as $i => [$at, $state, $method, $send, $sent]) {
    $ins($pdo, 'sales_order', ['entity_id' => $i + 1, 'state' => $state, 'status' => $state, 'created_at' => $at, 'base_grand_total' => 100.5, 'send_email' => $send, 'email_sent' => $sent,
        'customer_email' => "buyer{$i}@customer.example", 'customer_firstname' => 'Jane', 'customer_lastname' => 'Doe', 'remote_ip' => '203.0.113.9']);
    $ins($pdo, 'sales_order_payment', ['parent_id' => $i + 1, 'method' => $method, 'additional_information' => '{"cc_last4":"4242"}']);
}

// Config: one change since the last report.
$ins($pdo, 'core_config_data', ['scope' => 'default', 'scope_id' => 0, 'path' => 'payment/stripe_payments/active', 'value' => '0', 'updated_at' => $t('-4 minutes')]);
$ins($pdo, 'core_config_data', ['scope' => 'default', 'scope_id' => 0, 'path' => 'web/secure/base_url', 'value' => 'https://demo.example/', 'updated_at' => $t('-30 days')]);

// Products: [id, type, status, visibility, price (null = none), in a category, in stock].
$products = [
    [1, 'simple', 1, 4, 30.0, true, true],        // fine
    [2, 'simple', 1, 4, 0.0, true, true],         // zero price
    [3, 'simple', 1, 4, null, false, true],       // no price and no category
    [4, 'configurable', 1, 4, null, true, false], // configurable parent: no own price is normal; best-seller, out of stock
    [5, 'simple', 1, 1, 0.0, false, true],        // not visible on its own: ignored
    [6, 'simple', 2, 4, 0.0, false, true],        // disabled: ignored
    [7, 'simple', 1, 4, 10.0, true, false],       // out of stock but last sold 4 months ago
    [8, 'simple', 1, 4, 5.0, true, true],         // best-seller in stock
    [9, 'simple', 1, 1, 12.0, false, false],      // child of 4, sold through it
];
foreach ($products as [$id, $type, $status, $vis, $price, $cat, $stock]) {
    $ins($pdo, 'catalog_product_entity', ['entity_id' => $id, 'type_id' => $type, 'sku' => sprintf('MB-%02d', $id), 'updated_at' => $t('-' . (10 * $id) . ' minutes')]);
    $ins($pdo, 'catalog_product_entity_int', ['attribute_id' => 97, 'store_id' => 0, 'entity_id' => $id, 'value' => $status]);
    $ins($pdo, 'catalog_product_entity_int', ['attribute_id' => 99, 'store_id' => 0, 'entity_id' => $id, 'value' => $vis]);
    if ($price !== null) {
        $ins($pdo, 'catalog_product_entity_decimal', ['attribute_id' => 77, 'store_id' => 0, 'entity_id' => $id, 'value' => $price]);
    }
    if ($cat) {
        $ins($pdo, 'catalog_category_product', ['category_id' => 3, 'product_id' => $id]);
    }
    $ins($pdo, 'cataloginventory_stock_item', ['product_id' => $id, 'stock_id' => 1, 'qty' => $stock ? 5.5 : 0, 'is_in_stock' => $stock ? 1 : 0]);
}
$ins($pdo, 'sales_order_item', ['order_id' => 1, 'parent_item_id' => null, 'product_id' => 4, 'product_type' => 'configurable', 'sku' => 'MB-04', 'qty_ordered' => 9, 'created_at' => $t('-3 days')]);
$ins($pdo, 'sales_order_item', ['order_id' => 1, 'parent_item_id' => 1, 'product_id' => 9, 'product_type' => 'simple', 'sku' => 'MB-09', 'qty_ordered' => 9, 'created_at' => $t('-3 days')]);
$ins($pdo, 'sales_order_item', ['order_id' => 2, 'parent_item_id' => null, 'product_id' => 1, 'product_type' => 'simple', 'sku' => 'MB-01', 'qty_ordered' => 5, 'created_at' => $t('-2 days')]);
$ins($pdo, 'sales_order_item', ['order_id' => 3, 'parent_item_id' => null, 'product_id' => 8, 'product_type' => 'simple', 'sku' => 'MB-08', 'qty_ordered' => 2, 'created_at' => $t('-1 days')]);
$ins($pdo, 'sales_order', ['entity_id' => 99, 'state' => 'complete', 'status' => 'complete', 'created_at' => $t('-120 days'), 'base_grand_total' => 500, 'send_email' => 0, 'email_sent' => 0]);
$ins($pdo, 'sales_order_item', ['order_id' => 99, 'parent_item_id' => null, 'product_id' => 7, 'product_type' => 'simple', 'sku' => 'MB-07', 'qty_ordered' => 50, 'created_at' => $t('-120 days')]);
// Renamed since it sold: the current SKU is reported, not the one on the order.
$ins($pdo, 'sales_order_item', ['order_id' => 2, 'parent_item_id' => null, 'product_id' => 4, 'product_type' => 'configurable', 'sku' => 'OLD-SKU-04', 'qty_ordered' => 1, 'created_at' => $t('-2 days')]);

$builder = new HeartbeatBuilder(new SqlCollector(new SqliteRunner($pdo)), new FakeEnv());
$hb = $builder->build($now, $now->modify('-5 minutes'));
$hbHourly = $builder->build($now, $now->modify('-1 hour'));
$json = json_encode($hb, JSON_UNESCAPED_SLASHES);

if (in_array('--emit', $argv, true)) {
    // The server contract test also checks the hourly catalog part.
    echo json_encode($hb + ['catalog' => $hbHourly['catalog']], JSON_UNESCAPED_SLASHES);
    exit(0);
}

// Adobe Commerce with content staging: the old version (price 0) has ended; the current one has a price.
$stagePdo = new PDO('sqlite::memory:');
$stagePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$catalogTables($stagePdo, 'row_id');
$ts = $now->getTimestamp();
foreach ([[10, 1, $ts - 9000, $ts - 100, 0.0], [11, 1, $ts - 100, 2147483647, 20.0]] as [$row, $id, $from, $to, $price]) {
    $ins($stagePdo, 'catalog_product_entity', ['row_id' => $row, 'entity_id' => $id, 'created_in' => $from, 'updated_in' => $to, 'type_id' => 'simple', 'sku' => 'EE-1']);
    $ins($stagePdo, 'catalog_product_entity_int', ['attribute_id' => 97, 'store_id' => 0, 'row_id' => $row, 'value' => 1]);
    $ins($stagePdo, 'catalog_product_entity_int', ['attribute_id' => 99, 'store_id' => 0, 'row_id' => $row, 'value' => 4]);
    $ins($stagePdo, 'catalog_product_entity_decimal', ['attribute_id' => 77, 'store_id' => 0, 'row_id' => $row, 'value' => $price]);
}
$ins($stagePdo, 'catalog_category_product', ['category_id' => 3, 'product_id' => 1]);
$staged = (new SqlCollector(new SqliteRunner($stagePdo)))->catalog($now, true);

// Feeds on their own: orders waiting in "new" for over 2 hours within the last 7 days.
$feedPdo = new PDO('sqlite::memory:');
$feedPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$feedPdo->exec('CREATE TABLE sales_order (entity_id INTEGER PRIMARY KEY, state TEXT, created_at TEXT); CREATE TABLE catalog_product_entity (entity_id INTEGER PRIMARY KEY, updated_at TEXT); CREATE TABLE cataloginventory_stock_item (item_id INTEGER PRIMARY KEY, stock_id INTEGER, qty REAL, is_in_stock INTEGER)');
foreach ([['new', '-3 hours'], ['new', '-6 days'], ['new', '-8 days'], ['new', '-30 minutes'], ['processing', '-5 hours']] as [$st, $ago]) {
    $ins($feedPdo, 'sales_order', ['state' => $st, 'created_at' => $t($ago)]);
}
$ins($feedPdo, 'cataloginventory_stock_item', ['stock_id' => 2, 'qty' => 99, 'is_in_stock' => 1]);
$feeds = (new SqlCollector(new SqliteRunner($feedPdo)))->feeds($now);

$fails = 0;
$check = static function (string $name, bool $ok) use (&$fails): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . PHP_EOL;
    if (!$ok) {
        $fails++;
    }
};

$c = $hb['cron'];
$check('cron last success is 2 minutes ago', $c['last_success_at'] === '2026-11-18T10:38:00Z');
$check('cron pending counts only due jobs', $c['pending'] === 4);
$check('cron errors in the last hour', $c['errors_last_hour'] === 3);
$check('cron missed in the last hour', $c['missed_last_hour'] === 12);
$check('cron stuck running', $c['stuck_running'] === 1);
$check('failed job codes, not messages', $c['failed_jobs'] === ['sales_send_order_emails']);
$idx = array_column($hb['indexers'], null, 'id');
$check('price indexer on schedule with backlog 1200', $idx['catalog_product_price']['mode'] === 'schedule' && $idx['catalog_product_price']['backlog'] === 1200);
$check('stock indexer invalid', $idx['cataloginventory_stock']['status'] === 'invalid');
$check('queue pending and errors', $hb['queues'] === [['name' => 'inventory.reservations.update', 'pending' => 3, 'errors' => 2]]);
$check('three hourly order buckets', count($hb['orders']) === 3 && $hb['orders'][0]['hour'] === '2026-11-18T08:00:00Z');
$check('order counts per hour (08:00, 09:00, 10:00 UTC)', array_column($hb['orders'], 'count') === [0, 5, 1]);
$byMethod = [];
foreach ($hb['payments'] as $p) {
    $byMethod[$p['method']] = ($byMethod[$p['method']] ?? 0) + $p['problem_orders'];
}
$check('pending payment counted as a problem', ($byMethod['stripe_payments'] ?? 0) === 1);
$check('orders under 30 minutes old are left out of payment checks', array_sum(array_column($hb['payments'], 'orders')) === 5);
$check('unsent order emails (older than 15 minutes, not counting orders still waiting for payment)', $hb['emails']['unsent_order_emails'] === 1);
$check('config change path without value', $hb['config_changes'] === [['path' => 'payment/stripe_payments/active', 'at' => '2026-11-18T10:36:00Z']]);
$check('no customer data anywhere', !preg_match('/customer\.example|Jane|Doe|203\.0\.113|4242/', $json));
$check('modules hash present', strlen($hb['modules_hash']) === 40);
$check('catalog is read once an hour, not every 5 minutes', !isset($hb['catalog']) && isset($hbHourly['catalog']));
$cat = $hbHourly['catalog'] ?? [];
$check('enabled, visible products counted', ($cat['products'] ?? null) === 6);
$check('zero or missing price: simple products only, not configurable parents, hidden or disabled ones', ($cat['zero_price'] ?? null) === 2);
$check('products in no category', ($cat['no_category'] ?? null) === 1);
$check('best-sellers of the last 30 days, child rows left out', ($cat['bestsellers_checked'] ?? null) === 3);
$check('best-seller out of stock reported by SKU', ($cat['bestsellers_out_of_stock'] ?? null) === ['MB-04']);
$check('catalog part has counts and SKUs only', array_keys($cat) === ['products', 'zero_price', 'no_category', 'bestsellers_checked', 'bestsellers_out_of_stock', 'feeds']);
$f = $cat['feeds'] ?? [];
$check('feeds: last product change is the newest updated_at (10 minutes ago)', ($f['products_updated_at'] ?? null) === '2026-11-18T10:30:00Z');
$check('feeds: stock fingerprint is total quantity and items in stock', ($f['stock_qty'] ?? null) === 33.0 && ($f['stock_in_stock'] ?? null) === 6);
$check('feeds: no waiting orders in the main fixture', ($f['orders_waiting'] ?? null) === 0);
$check('feeds: orders waiting in the new state for over 2 hours, last 7 days only', $feeds['orders_waiting'] === 2);
$check('feeds: an empty catalog gives no product time, and other stock sources are ignored', $feeds['products_updated_at'] === null && $feeds['stock_qty'] === 0.0 && $feeds['stock_in_stock'] === 0);
$check('Adobe Commerce staging: only the current version of a product is read', ($staged['products'] ?? null) === 1 && ($staged['zero_price'] ?? null) === 0 && ($staged['no_category'] ?? null) === 0);

echo $fails ? "\n{$fails} failed\n" : "\nAll plugin checks passed\n";
exit($fails ? 1 : 0);
