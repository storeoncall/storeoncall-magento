# Changelog

## 0.1.1 (20 November 2026)

- A lasting problem (for example the plan has ended, or the store key was replaced) is written to the Magento log once when it starts, instead of every 5 minutes.

## 0.1.0 (18 November 2026)

First public version.

- Every 5 minutes, through Magento cron, sends a small read-only report: cron and indexer status, queue sizes, order and payment counts per hour, unsent order emails, cache status, installed extension versions and which setting paths changed.
- Commands: `storeoncall:connect`, `storeoncall:status`, `storeoncall:preview`, `storeoncall:send` and `storeoncall:verify`.
- `storeoncall:verify` for deploy scripts: asks StoreOnCall to check the store now (opens, pages, search, checkout reaches payment), waits for the answer and exits 1 if a check fails.
- Once an hour: catalog counts (enabled, visible products with no price or no category) and the SKUs of best-sellers of the last 30 days that are out of stock. Reads the current version of each product on Adobe Commerce with content staging.
- Hourly data-feed signals for any ERP or PIM: last product change time, a stock fingerprint (total quantity and items in stock) and orders waiting over 2 hours in the new state.
- Adobe Commerce Cloud: reads the key from the `STOREONCALL_KEY` environment variable.
- Never selects customer, address or payment columns.
