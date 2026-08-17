# Payment Worker and HTTP Timeout Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent payment workers and HTTP requests from remaining alive indefinitely after database or network failures.

**Architecture:** Keep payment protocol behavior intact while bounding every relevant cURL operation. Make the sole long-running payment worker fail fast only on database connection/query failure so Supervisor recreates its process-level state.

**Tech Stack:** PHP CLI, PDO MySQL, PHP cURL, Supervisor.

## Global Constraints

- Keep pending-order reconciliation at 8 minutes.
- Keep Alipay account-log reconciliation at 3 minutes.
- Use a 5-second connection timeout.
- Use a 20-second total timeout for shared helpers and shared SDKs.
- Use a 30-second total timeout for private payment clients.
- Do not change request signing, certificates, response parsing, retry behavior, or TLS verification behavior.
- Do not change global PDO error mode.
- The workspace has no `.git` metadata, so no commits can be created.

---

### Task 1: Alipay Code Worker Self-Recovery

**Files:**
- Modify: `plugins/alipaycode/server.php`

**Interfaces:**
- Consumes: global `PdoHelper $DB`, `AlipayBillService`, `processNotify()`.
- Produces: a CLI worker that exits nonzero on database failure and logs timestamped polling outcomes.

- [ ] **Step 1: Preserve the reconciliation contract**

Keep these exact expressions:

```php
addtime>=DATE_SUB(NOW(), INTERVAL 8 MINUTE)
$start_time = date('Y-m-d H:i:s', time()-180);
```

- [ ] **Step 2: Distinguish database failure from an empty result**

Immediately after `getAll()`, add a strict failure branch:

```php
if ($list === false) {
    fwrite(STDERR, '['.date('Y-m-d H:i:s').'] 数据库查询失败：'.$DB->error().PHP_EOL);
    exit(1);
}
```

Only run the existing empty-list branch after this check.

- [ ] **Step 3: Bound iteration failures and normalize logs**

Wrap the iteration body in `try { ... } catch (\Throwable $e)`. API failures write a timestamped message to STDERR and continue to the existing wait point. Database list-query failure remains an explicit `exit(1)`. Prefix normal output with `[Y-m-d H:i:s]`.

- [ ] **Step 4: Normalize amount comparison without changing identifiers**

Replace loose raw amount comparison with two-decimal string comparison:

```php
return $v['trade_no'] === $trade_no
    && number_format((float)$v['realmoney'], 2, '.', '') === number_format((float)$money, 2, '.', '');
```

- [ ] **Step 5: Verify Task 1**

After `processNotify()`, re-read `pre_order.status`. Exit on a failed read, retry later when status remains below 1, and print the success log only for persisted `status >= 1`.

Run:

```powershell
php -l plugins\alipaycode\server.php
rg -n "INTERVAL 8 MINUTE|time\(\)-180|=== false|catch\s*\(\\Throwable|STDERR" plugins\alipaycode\server.php
```

Expected: PHP reports no syntax errors; both original windows and the new strict failure/error handling appear.

---

### Task 2: Shared HTTP Helpers and Payment SDKs

**Files:**
- Modify: `includes/functions.php`
- Modify: `includes/vendor/cccyun/alipay-sdk/src/Aop/AopClient.php`
- Modify: `includes/vendor/cccyun/wechatpay-sdk/src/BaseService.php`
- Modify: `includes/vendor/cccyun/wechatpay-sdk/src/V3/BaseService.php`
- Modify: `includes/vendor/cccyun/wechatpay-sdk/src/JsApiTool.php`
- Modify: `includes/vendor/cccyun/qqpay-sdk/src/BaseService.php`

**Interfaces:**
- Consumes: existing cURL handles and each method's current timeout argument where present.
- Produces: the same return values/exceptions with bounded connection and execution time.

- [ ] **Step 1: Harden `curl_get()`**

Before `curl_exec()`, retain its existing 5-second total timeout and add:

```php
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_NOSIGNAL, true);
```

- [ ] **Step 2: Harden `get_curl()`**

Before `curl_exec()`, add:

```php
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
curl_setopt($ch, CURLOPT_NOSIGNAL, true);
```

- [ ] **Step 3: Harden both Alipay SDK execution paths**

Add the same 5-second connect, 20-second total, and `CURLOPT_NOSIGNAL` settings to the redirect cURL block and the protected `curl()` method in `AopClient.php`.

- [ ] **Step 4: Harden WeChat and QQ SDK execution paths**

For SDK methods with `$second` or `$timeout`, preserve that total timeout and add:

```php
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $second));
curl_setopt($ch, CURLOPT_NOSIGNAL, true);
```

Use `min(5, $timeout)` in the V3 method. In `JsApiTool.php`, add a 5-second connect timeout and `CURLOPT_NOSIGNAL` beside its existing 6-second total timeout.

- [ ] **Step 5: Verify Task 2**

Run `php -l` for all six files. Then inspect every `curl_exec()` block in those files and confirm it has a total timeout plus a connection timeout.

---

### Task 3: Private Payment Clients Missing Timeouts

**Files:**
- Modify: `plugins/alipayg/inc/AlipayGlobalClient.php`
- Modify: `includes/lib/QC.php`
- Modify: `plugins/chinaums/inc/Build.class.php`
- Modify: `plugins/kuaiqian/inc/PayApp.class.php`
- Modify: `plugins/lakala/inc/LakalaClient.php`
- Modify: `plugins/stripe/inc/StripeClient.php`
- Modify: `plugins/ysepay/inc/YsepayClient.php`
- Modify: `plugins/yseqt/inc/YseqtClient.php`
- Modify: `user/qrlogin.php`
- Modify: `includes/vendor/fgrosse/phpasn1/lib/ASN1/OID.php`

**Interfaces:**
- Consumes: each client's existing cURL request function.
- Produces: unchanged response/error contracts with a 5-second connect bound, a 30-second total bound for payment clients, and a 20-second total bound for lightweight auxiliary requests.

- [ ] **Step 1: Add the timeout policy to every cURL block**

Before each `curl_exec()` in these files, ensure the same handle has:

```php
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_NOSIGNAL, true);
```

`PayApp.class.php` has two independent cURL blocks; update both.

Use a 20-second total timeout instead for `user/qrlogin.php` and the ASN.1 OID web lookup.

- [ ] **Step 2: Preserve client contracts**

Do not add retries or alter the existing `curl_errno()`, exception, false-return, HTTP status, signature, certificate, body, header, or response parsing logic.

- [ ] **Step 3: Verify Task 3**

Run `php -l` on all eight files. Search each file for `curl_exec`, `CURLOPT_CONNECTTIMEOUT`, and `CURLOPT_TIMEOUT`; every execution block must contain all three timeout settings.

---

### Task 4: Payment Status Refresh

**Files:**
- Modify: `getshop.php`
- Modify: `plugins/alipaycode/inc/qrcode.page.php`

**Interfaces:**
- Consumes: `pre_order.status` and the existing `{code, backurl}` JSON contract.
- Produces: non-cacheable status responses and immediate foreground/resume polling.

- [ ] **Step 1: Disable status-response caching**

Add `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`, `Pragma: no-cache`, and `Expires: 0` headers to `getshop.php`.

- [ ] **Step 2: Make polling single-flight and cache-free**

Add a request-in-progress flag, `cache: false`, a 5-second AJAX timeout, and a timestamp query parameter. Schedule the next request one second after each unsuccessful response.

- [ ] **Step 3: Refresh immediately when the page returns to the foreground**

Call the status checker on `focus`, `pageshow`, and when `document.visibilityState` becomes `visible`. Redirect successful orders from a proper callback.

- [ ] **Step 4: Verify refresh behavior statically**

Run `php -l` on both files and confirm the no-cache headers, single-flight flag, one-second poll, request timeout, and foreground listeners are present.

---

### Task 5: Repository-Wide Verification

**Files:**
- Verify all files modified by Tasks 1-3.

**Interfaces:**
- Consumes: completed worker and HTTP timeout changes.
- Produces: syntax and static-analysis evidence for deployment.

- [ ] **Step 1: Run PHP syntax checks**

Enumerate the changed PHP paths from Tasks 1-3 and run `php -l` on each. Expected: every file reports `No syntax errors detected`.

- [ ] **Step 2: Rescan direct cURL usage**

Run:

```powershell
$files = rg -l "curl_exec\s*\(" plugins includes\lib includes\functions.php -g "*.php" -g "!includes/vendor/**"
foreach ($file in $files) {
    $hasTimeout = Select-String -LiteralPath $file -Pattern 'CURLOPT_TIMEOUT' -Quiet
    if (-not $hasTimeout) { $file }
}
```

Expected: no file is reported. Manually account for files containing multiple independent cURL blocks.

- [ ] **Step 3: Verify shared vendor SDK blocks**

Search `includes/vendor/cccyun` for `curl_exec`, `CURLOPT_TIMEOUT`, and `CURLOPT_CONNECTTIMEOUT`. Expected: every direct execution block in Alipay, WeChat, and QQ SDKs has both bounds.

- [ ] **Step 4: Verify business windows**

Run:

```powershell
rg -n "INTERVAL 8 MINUTE|time\(\)-180|INTERVAL 30 MINUTE|time\(\)-1800" plugins\alipaycode\server.php
```

Expected: the 8-minute and 180-second expressions are present; 30-minute and 1800-second expressions are absent.

- [ ] **Step 5: Record deployment instructions**

After uploading the changed files:

```bash
supervisorctl reread
supervisorctl update
supervisorctl restart pay
supervisorctl status pay
```

Expected: `pay` reaches `RUNNING`, and timestamped polling messages appear in `pay.out.log` or failures in `pay.err.log`.
