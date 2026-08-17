# Alipay Code High-Throughput Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Keep the single Alipay code reconciliation worker responsive under burst order volume while preventing missed bill pages and duplicate financial processing.

**Architecture:** A pure `AlipayCodeReconciler` builds a pending-order hash map and matches each bill detail in constant time. The existing worker owns API pagination, defers financial processing until every page succeeds, then serially calls the unchanged notification flow. Database indexes serve the two exact pending-order query shapes, with an idempotent CLI migration for existing installations.

**Tech Stack:** PHP 7.4+, PDO/MySQL, existing Alipay SDK, standalone PHP assertions, Supervisor single-process worker.

## Global Constraints

- Keep one Supervisor process and `numprocs=1`.
- Keep pending orders at `INTERVAL 8 MINUTE`.
- Keep the Alipay bill lookback at `time()-180`.
- Keep order financial processing serial.
- Preserve `processNotify()` and persisted-status verification.
- Do not add connection pooling, persistent PDO, multiple consumers, or a queue.
- Account-log page size is 2,000 and the hard guard is 100 pages.
- Any page failure aborts financial processing for the entire cycle and preserves a per-channel recovery record in `pre_cache`.
- This workspace has no `.git` directory, so commit steps are intentionally omitted.

---

### Task 1: Pure Linear-Time Reconciler

**Files:**
- Create: `plugins/alipaycode/inc/AlipayCodeReconciler.php`
- Create: `tests/alipaycode_reconciler_test.php`

**Interfaces:**
- Consumes: pending rows shaped as `['trade_no' => string, 'realmoney' => numeric-string]`; Alipay details containing `trans_memo` and `trans_amount`.
- Produces: `AlipayCodeReconciler::buildPendingMap(array $orders): array` and `AlipayCodeReconciler::matchDetails(array &$pendingByTradeNo, array $details): array`.
- Match entries are shaped as `['trade_no' => string, 'detail' => array]`.

- [ ] **Step 1: Write the standalone failing test**

Create `tests/alipaycode_reconciler_test.php` with a small assertion helper and cases for empty inputs, normalized amount equality, amount mismatch, unrelated memos, duplicate details, and matches split across two calls:

```php
<?php
require __DIR__.'/../plugins/alipaycode/inc/AlipayCodeReconciler.php';

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message.PHP_EOL);
        fwrite(STDERR, 'Expected: '.var_export($expected, true).PHP_EOL);
        fwrite(STDERR, 'Actual: '.var_export($actual, true).PHP_EOL);
        exit(1);
    }
}

$empty = AlipayCodeReconciler::buildPendingMap([]);
assertSameValue([], $empty, 'Empty pending list should create an empty map');

$pending = AlipayCodeReconciler::buildPendingMap([
    ['trade_no' => '2026081712000012345', 'realmoney' => '1'],
    ['trade_no' => '2026081712000067890', 'realmoney' => '2.50'],
]);

$firstPage = AlipayCodeReconciler::matchDetails($pending, [
    ['trans_memo' => '请勿添加备注-2026081712000012345', 'trans_amount' => '1.00', 'alipay_order_no' => 'A1'],
    ['trans_memo' => '请勿添加备注-2026081712000012345', 'trans_amount' => '1.0', 'alipay_order_no' => 'A1-DUP'],
    ['trans_memo' => '请勿添加备注-2026081712000067890', 'trans_amount' => '2.49', 'alipay_order_no' => 'BAD-AMOUNT'],
    ['trans_memo' => 'other', 'trans_amount' => '2.50', 'alipay_order_no' => 'OTHER'],
]);
assertSameValue(['2026081712000012345'], array_column($firstPage, 'trade_no'), 'First page should contain one deduplicated match');

$secondPage = AlipayCodeReconciler::matchDetails($pending, [
    ['trans_memo' => '请勿添加备注-2026081712000067890', 'trans_amount' => '2.500', 'alipay_order_no' => 'A2'],
]);
assertSameValue(['2026081712000067890'], array_column($secondPage, 'trade_no'), 'Second page should match the remaining order');
assertSameValue([], $pending, 'Matched orders should be removed from the pending map');

echo "alipaycode_reconciler_test: OK\n";
```

- [ ] **Step 2: Run the test and confirm the helper is missing**

Run: `php tests/alipaycode_reconciler_test.php`

Expected: FAIL because `plugins/alipaycode/inc/AlipayCodeReconciler.php` does not exist.

- [ ] **Step 3: Implement the pure helper**

Create an ASCII-compatible PHP 7.4 class with these exact rules:

```php
<?php

class AlipayCodeReconciler
{
    public static function normalizeAmount($amount)
    {
        return number_format((float)$amount, 2, '.', '');
    }

    public static function buildPendingMap(array $orders)
    {
        $pending = [];
        foreach ($orders as $order) {
            if (!isset($order['trade_no'], $order['realmoney'])) {
                continue;
            }
            $tradeNo = (string)$order['trade_no'];
            $pending[$tradeNo] = self::normalizeAmount($order['realmoney']);
        }
        return $pending;
    }

    public static function matchDetails(array &$pendingByTradeNo, array $details)
    {
        $matches = [];
        foreach ($details as $detail) {
            if (!isset($detail['trans_memo'], $detail['trans_amount'])) {
                continue;
            }
            $tradeNo = trim(str_replace('请勿添加备注-', '', (string)$detail['trans_memo']));
            if (!isset($pendingByTradeNo[$tradeNo])) {
                continue;
            }
            if ($pendingByTradeNo[$tradeNo] !== self::normalizeAmount($detail['trans_amount'])) {
                continue;
            }
            $matches[] = ['trade_no' => $tradeNo, 'detail' => $detail];
            unset($pendingByTradeNo[$tradeNo]);
        }
        return $matches;
    }
}
```

- [ ] **Step 4: Run focused tests and lint**

Run:

```powershell
php tests/alipaycode_reconciler_test.php
php -l plugins/alipaycode/inc/AlipayCodeReconciler.php
php -l tests/alipaycode_reconciler_test.php
```

Expected: test prints `alipaycode_reconciler_test: OK`; both lint commands report no syntax errors.

---

### Task 2: Paginated Worker and Cycle Metrics

**Files:**
- Modify: `plugins/alipaycode/server.php`
- Uses: `plugins/alipaycode/inc/AlipayCodeReconciler.php`

**Interfaces:**
- Consumes: `AlipayBillService::accountlogQuery($startTime, $endTime, $pageNo, 2000)` responses.
- Produces: an in-memory list of unique match entries from Task 1; one summary log per cycle that had pending orders.

- [ ] **Step 1: Load the reconciler and initialize active-cycle metrics**

Immediately after `include("../../includes/common.php");`, require the helper. At the start of each loop, use `microtime(true)` and counters for pending rows, details, matches, pages, query duration, processing duration, and result status.

```php
require_once __DIR__.'/inc/AlipayCodeReconciler.php';

$cycleStartedAt = microtime(true);
$pendingCount = 0;
$detailCount = 0;
$matchCount = 0;
$pageCount = 0;
$billQuerySeconds = 0.0;
$processingSeconds = 0.0;
$cycleResult = 'idle';
```

- [ ] **Step 2: Replace cross-product matching with guarded pagination**

Keep the pending SQL at `INTERVAL 8 MINUTE`. When pending rows exist, build the map, fix one time range for the whole cycle, and query pages 1 through at most 100. Validate that every response is an array with an array `detail_list`. Accumulate matches page-by-page but do not call `processNotify()` inside the pagination loop.

The loop termination logic is exact:

```php
$pageSize = 2000;
$maxPages = 100;
$expectedPages = null;
$matches = [];
$paginationComplete = false;

for ($pageNo = 1; $pageNo <= $maxPages; $pageNo++) {
    $queryStartedAt = microtime(true);
    $result = $aop->accountlogQuery($start_time, $end_time, $pageNo, $pageSize);
    $billQuerySeconds += microtime(true) - $queryStartedAt;

    if (!is_array($result) || !isset($result['detail_list']) || !is_array($result['detail_list'])) {
        throw new RuntimeException('支付宝账务明细返回格式异常');
    }

    $pageCount++;
    $details = $result['detail_list'];
    $detailCount += count($details);
    $matches = array_merge($matches, AlipayCodeReconciler::matchDetails($pendingMap, $details));

    if ($expectedPages === null && isset($result['total_size']) && is_numeric($result['total_size']) && (int)$result['total_size'] >= 0) {
        $expectedPages = max(1, (int)ceil((int)$result['total_size'] / $pageSize));
        if ($expectedPages > $maxPages) {
            throw new RuntimeException('支付宝账务明细超过单轮分页上限');
        }
    }

    if (count($details) < $pageSize || ($expectedPages !== null && $pageNo >= $expectedPages)) {
        $paginationComplete = true;
        break;
    }
}

if (!$paginationComplete) {
    throw new RuntimeException('支付宝账务明细分页未正常结束');
}
```

Wrap this full pagination block in its own `try/catch`. On exception, log the API error, set `$cycleResult = 'api_error'`, clear `$matches`, and skip all financial processing for the cycle.

- [ ] **Step 3: Process complete-cycle matches serially**

Move the existing order load, `processNotify()`, persisted status query, and result logging into a serial loop over `$matches`. Read `$trade_no` and `$item` from each match entry. Preserve these existing failure semantics:

- database read/status failures log to STDERR and `exit(1)` so Supervisor reconnects;
- `processNotify()` exceptions are logged, then persisted status is still checked;
- status below 1 is retried in a later cycle;
- an exception for one downstream notification does not prevent later matched orders from running.

Measure only this loop with `$processingStartedAt = microtime(true)` and set `$processingSeconds` afterward.

- [ ] **Step 4: Emit one performance summary for each active cycle**

At the bottom of the outer loop, before sleeping, log only when `$pendingCount > 0`:

```php
alipayCodeWorkerLog(sprintf(
    '本轮统计 result=%s pending=%d details=%d matched=%d pages=%d bill_query=%.3fs processing=%.3fs total=%.3fs peak_memory=%.2fMiB',
    $cycleResult,
    $pendingCount,
    $detailCount,
    $matchCount,
    $pageCount,
    $billQuerySeconds,
    $processingSeconds,
    microtime(true) - $cycleStartedAt,
    memory_get_peak_usage(true) / 1048576
));
```

Set `$cycleResult` to `ok` after complete pagination and processing. Set `$matchCount = count($matches)` before processing so the metric remains correct if a later order callback throws.

- [ ] **Step 5: Run focused regression checks**

Run:

```powershell
php -l plugins/alipaycode/server.php
php tests/alipaycode_reconciler_test.php
rg -n "INTERVAL 8 MINUTE|time\(\)-180|pageSize = 2000|maxPages = 100|processNotify|SELECT status" plugins/alipaycode/server.php
rg -n "array_filter" plugins/alipaycode/server.php
```

Expected: lint and test pass; the first search shows every required invariant; the final search returns no matches.

---

### Task 3: Installation Indexes and Idempotent Production Migration

**Files:**
- Modify: `install/install.sql`
- Create: `install/alipaycode_performance.php`

**Interfaces:**
- Consumes: the configured `$dbconfig` and `lib\PdoHelper` prefix substitution.
- Produces: indexes named `channel_status_addtime` and `channel_sub_status_addtime` on the configured order table.

- [ ] **Step 1: Add both indexes for new installations**

Add these definitions after the existing `date` key in `pre_order`:

```sql
 KEY `channel_status_addtime` (`channel`,`status`,`addtime`),
 KEY `channel_sub_status_addtime` (`channel`,`subchannel`,`status`,`addtime`)
```

- [ ] **Step 2: Create a CLI-only idempotent migration**

Create `install/alipaycode_performance.php`. It must reject non-CLI execution, load `config.php` and `PdoHelper.php`, query existing indexes, and add only missing definitions:

```php
<?php
if (PHP_SAPI !== 'cli') {
    exit("This migration can only be run in CLI mode\n");
}

require __DIR__.'/../config.php';
require_once __DIR__.'/../includes/lib/PdoHelper.php';

$DB = new \lib\PdoHelper($dbconfig);
$rows = $DB->getAll('SHOW INDEX FROM pre_order');
if ($rows === false) {
    fwrite(STDERR, 'Failed to inspect order indexes: '.$DB->error().PHP_EOL);
    exit(1);
}

$existing = [];
foreach ($rows as $row) {
    if (isset($row['Key_name'])) {
        $existing[$row['Key_name']] = true;
    }
}

$indexes = [
    'channel_status_addtime' => '(`channel`,`status`,`addtime`)',
    'channel_sub_status_addtime' => '(`channel`,`subchannel`,`status`,`addtime`)',
];

foreach ($indexes as $name => $columns) {
    if (isset($existing[$name])) {
        echo "Index {$name} already exists\n";
        continue;
    }
    if ($DB->exec("ALTER TABLE pre_order ADD INDEX `{$name}` {$columns}") === false) {
        fwrite(STDERR, "Failed to add index {$name}: ".$DB->error().PHP_EOL);
        exit(1);
    }
    echo "Added index {$name}\n";
}

echo "Alipay code performance migration complete\n";
```

- [ ] **Step 3: Lint and statically verify the migration**

Run:

```powershell
php -l install/alipaycode_performance.php
rg -n "channel_status_addtime|channel_sub_status_addtime" install/install.sql install/alipaycode_performance.php
```

Expected: lint passes and both index names appear in both files. Do not execute the migration on the local machine because `config.php` has no production credentials.

---

### Task 4: Full Verification and Deployment Handoff

**Files:**
- Verify all files changed by Tasks 1-3.

**Interfaces:**
- Consumes: completed implementation and local PHP 8.5 CLI.
- Produces: syntax/test evidence and exact production deployment commands.

- [ ] **Step 1: Run all local verification**

Run:

```powershell
php tests/alipaycode_reconciler_test.php
php -l plugins/alipaycode/inc/AlipayCodeReconciler.php
php -l plugins/alipaycode/server.php
php -l install/alipaycode_performance.php
rg -n "array_filter" plugins/alipaycode/server.php
rg -n "INTERVAL 8 MINUTE|time\(\)-180" plugins/alipaycode/server.php
```

Expected: test and lint pass; `array_filter` has no matches; both time-window expressions remain present.

- [ ] **Step 2: Review the final diff manually**

Because the workspace is not a Git repository, compare each changed file directly against the plan. Confirm no unrelated files changed and no credentials were added.

- [ ] **Step 3: Provide production rollout and verification commands**

After uploading the changed files, run:

```bash
cd /www/wwwroot/pay.leochen.cyou
php install/alipaycode_performance.php
supervisorctl restart pay:*
supervisorctl status pay:*
tail -f /www/server/panel/plugin/supervisor/log/pay.out.log
```

Then verify the active query plan with the site's real channel ID:

```sql
EXPLAIN SELECT trade_no,realmoney
FROM pay_order
WHERE channel=1 AND status=0
  AND addtime>=DATE_SUB(NOW(), INTERVAL 8 MINUTE);
```

Expected: Supervisor reports `RUNNING`; cycle summaries continue every active cycle; `EXPLAIN.key` is `channel_status_addtime` for a normal channel or `channel_sub_status_addtime` when the subchannel predicate is present.

---

### Review Fixes: Durable Recovery and Migration Verification

- [x] Persist the start timestamp and pending-order map before each Alipay query.
- [x] Merge current pending orders into a failed cycle and retain the earliest recovery start.
- [x] Clear recovery state only after complete pagination and persisted status checks.
- [x] Validate stable `total_size`, page metadata, page limits, and cumulative counts.
- [x] Log recovery/window/processed/failed/retry metrics and mark partial cycles.
- [x] Make database connection failures exit non-zero in the index migration.
- [x] Verify exact index column order, add missing indexes in one DDL, and verify afterward.
- [x] Add standalone tests for recovery persistence and index planning.
- [x] Replace the growing recovery range with a durable queue of fixed 240-second minute-bucket windows.
- [x] Add a 120-second cycle budget and requeue unstarted matches with their original window.
- [x] Test same-minute merging, later-window queuing, cross-window cleanup, and fixed-window retries.
