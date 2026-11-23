# StoreOnCall agent for Magento 2 / Adobe Commerce

The Magento 2 and Adobe Commerce plugin for [StoreOnCall](https://storeoncall.com), store monitoring for Adobe Commerce, Shopify Plus and BigCommerce. Setup guide: [storeoncall.com/get-started](https://storeoncall.com/get-started).

Read-only store health monitoring. Every 5 minutes, Magento cron sends StoreOnCall a small report: cron and indexer status, queue sizes, order and payment counts per hour, unsent order email counts, cache status, installed extension versions, and which configuration paths changed. Once an hour it adds catalog counts: enabled, visible products with no price or no category, and the SKUs of any of the 20 best-sellers (last 30 days) that are out of stock; plus data-feed signals: the last product change time, total stock quantity and items in stock, and the number of orders waiting over 2 hours in the new state.

**It never sends customer data**: no names, emails, addresses, phone numbers, IPs or payment details. Only counts, statuses, job codes and totals. Run `bin/magento storeoncall:preview` to see exactly what would be sent.

## Install

```bash
composer require storeoncall/module-agent
bin/magento module:enable StoreOnCall_Agent
bin/magento setup:upgrade
bin/magento cache:flush
bin/magento storeoncall:connect <your store key>
```

Adobe Commerce Cloud: add the package with Composer, commit `composer.json` and `composer.lock`, set the environment variable `STOREONCALL_KEY`, and deploy.

## Commands

| Command | What it does |
| --- | --- |
| `storeoncall:connect <key>` | Checks the key with StoreOnCall, saves it (encrypted) and sends the first report |
| `storeoncall:status` | Shows the key in use, the last report and whether StoreOnCall can be reached |
| `storeoncall:preview` | Prints the exact report, without sending it |
| `storeoncall:send` | Sends a report now |
| `storeoncall:verify` | After a deployment: asks StoreOnCall to check the store now (opens, pages, search, checkout) and waits. Exit 0 when it passes, 1 when a check fails. `--note`, `--timeout`, `--strict` |

## Safety

- Read-only: only `SELECT` queries; nothing in the store is changed.
- Outbound HTTPS only, every 5 minutes, with short timeouts. Errors are logged and never interrupt cron.
- No customer, address or payment columns are ever selected.

## Remove

```bash
bin/magento module:disable StoreOnCall_Agent
composer remove storeoncall/module-agent
bin/magento setup:upgrade
```

## Tests

`php tests/run.php` runs the collectors' real SQL against SQLite copies of the Magento tables they read. It has not yet been run inside a real Magento installation.
