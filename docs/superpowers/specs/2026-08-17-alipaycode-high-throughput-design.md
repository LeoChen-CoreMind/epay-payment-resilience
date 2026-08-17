# Alipay Code High-Throughput Reconciliation

## Goal

Keep the Alipay code-payment reconciliation worker responsive when many orders are created or paid in the same period, without introducing multiple workers or duplicate financial processing.

## Confirmed Bottlenecks

- The worker has one PDO connection; there is no application connection pool in this process.
- The pending-order query has no matching composite index and can scan a large `pre_order` table every three seconds.
- Every Alipay detail currently runs `array_filter()` across every pending order, producing `O(pending orders * bill details)` work.
- Only the first 2,000 Alipay details are queried.
- Matched orders are processed serially, so synchronous merchant callbacks and notices contribute directly to cycle duration.

## Scope

- Keep one Supervisor process.
- Keep the pending-order window at 8 minutes.
- Keep the Alipay account-log window at 3 minutes.
- Preserve the existing payment state transition, balance processing, callbacks, and frontend refresh behavior.
- Add database indexes, linear-time matching, account-log pagination, and cycle metrics.
- Do not add a connection pool, persistent PDO connections, multiple consumers, or a new payment queue.

## Database Indexes

Add these indexes to the order table:

```sql
KEY `channel_status_addtime` (`channel`, `status`, `addtime`),
KEY `channel_sub_status_addtime` (`channel`, `subchannel`, `status`, `addtime`)
```

The first supports normal channel polling. The second supports subchannel polling without scanning unrelated subchannels. New installations receive both indexes in `install/install.sql`. A separate idempotent CLI PHP migration validates the configured table prefix, uses exception-mode PDO, verifies each existing index's columns and order, adds all missing indexes in one `ALTER TABLE`, and verifies the final definitions. Connection, inspection, DDL, or verification failures return a non-zero exit code.

## Reconciliation Algorithm

Create a focused `AlipayCodeReconciler` helper with pure operations:

- Normalize an amount to a two-decimal string.
- Build a map keyed by `trade_no` from pending order rows.
- Match a page of Alipay details by direct map lookup plus normalized amount equality.
- Remove a matched order from the map so duplicate details cannot enqueue duplicate processing in one cycle.

This changes matching complexity from `O(N*M)` to `O(N+M)` while preserving trade-number and amount verification.

The worker queries account-log pages with a page size of 2,000. It validates a non-negative integer `total_size`, stable totals across pages, optional returned page metadata, per-page limits, and final cumulative counts. Conflicting or malformed metadata aborts the cycle. A hard 100-page guard prevents a non-terminating response. When every pending order has already matched, pagination may stop early because no additional page can affect the cycle.

Before querying Alipay, the worker persists a per-channel queue of fixed windows in `pre_cache`. Each minute bucket creates one exact 240-second interval from `bucket-180` through `bucket+60` and merges all currently pending orders into that interval. Failed API windows retain the same start, end, and pending map; newer minute buckets continue entering the queue instead of expanding the failed interval. Successful orders are removed from overlapping later windows, while orders whose persisted state did not update are requeued with their original fixed interval. This state survives a Supervisor restart during querying or processing.

Each worker cycle has a 120-second wall-clock budget. The paginator checks the budget before starting another API page. The processing loop requeues all unstarted matches when the budget is reached. With bounded network-operation timeouts, this keeps orders created during a busy cycle inside the next normal 180-second Alipay lookback as well as the confirmed 8-minute database window.

After all pages are fetched and matched, the worker processes each unique match with the existing `processNotify()` flow and persisted-status verification. Processing remains serial to avoid duplicate balance changes.

## Observability

Each active cycle logs one summary containing:

- pending order count;
- queried bill-detail count;
- matched order count;
- processed, failed, and retry order counts;
- page count;
- recovery mode and effective window length;
- queued recovery-window count;
- bill-query duration;
- order-processing duration;
- total cycle duration;
- peak memory in MiB.

API failures and database failures retain their existing STDERR behavior. Database failures exit for Supervisor reconnection. Individual downstream notification errors do not prevent later matched orders from being processed.

## Testing

Add a standalone PHP test for the pure reconciler helper covering:

- empty inputs;
- exact trade-number and amount matches;
- different two-decimal string representations;
- amount mismatch rejection;
- duplicate bill details producing one match;
- unrelated details being ignored;
- matching across multiple pages using the remaining pending map.
- recovery-state save/load/clear and corrupt-state rejection;
- missing, correct, and conflicting index definitions.

Run `php -l` on every changed PHP file. Static checks confirm the 8-minute and 180-second expressions remain unchanged, both index definitions exist, and the worker no longer uses `array_filter()`.

## Deployment

Run the idempotent index migration once on the production database before restarting the worker. Verify the query plan uses one of the new indexes, then restart the existing single `pay` Supervisor group. No additional process or process-name change is required.
