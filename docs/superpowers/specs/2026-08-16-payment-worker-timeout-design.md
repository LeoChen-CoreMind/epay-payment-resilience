# Payment Worker and HTTP Timeout Hardening

## Goal

Prevent payment requests and the Alipay code-payment worker from remaining alive while making no progress after a database or network failure.

## Scope

- Keep the existing reconciliation windows unchanged:
  - pending orders: 8 minutes
  - Alipay account log: 3 minutes
- Make database failures in `plugins/alipaycode/server.php` observable and recoverable through Supervisor.
- Add bounded connect and total timeouts to shared payment HTTP clients and private clients currently missing them.
- Make the browser order-status check bypass caches and refresh immediately after returning from a payment app.
- Preserve request parameters, signatures, certificates, response parsing, and current TLS verification behavior.

## Worker Behavior

`plugins/alipaycode/server.php` will distinguish an empty query result from a failed query. A failed database query writes a timestamped error to STDERR and exits with a nonzero status so Supervisor creates a fresh process and database connection.

The worker will catch `Throwable` around each polling iteration. Network/API failures will be logged without leaving the process blocked; fatal database failures will terminate the process. Normal no-order polling remains on the current three-second cadence.

All worker log messages will include timestamps. Order and transaction amount matching will retain the existing identifiers and normalize amounts to two decimal places. The query windows and page size remain unchanged.

After `processNotify()` returns, the worker will re-read the order status. It reports payment success only when the persisted status is at least 1; a failed read exits for reconnection, while an unchanged status remains eligible for the next polling cycle.

## HTTP Timeout Policy

- Connect timeout: 5 seconds.
- Total request timeout: 20 seconds for shared lightweight requests and Alipay SDK calls.
- Total request timeout: 30 seconds for private payment clients where certificate negotiation or international endpoints may take longer.
- Enable `CURLOPT_NOSIGNAL` where available to make sub-minute cURL timeouts reliable in CLI processes.

The policy applies to:

- `curl_get()` and `get_curl()` in `includes/functions.php`.
- CCCYun Alipay, WeChat v2/v3, and QQ Pay SDK request methods.
- Direct clients found without a timeout: Alipay Global, QQ Connect, China UMS, Kuaiqian, Lakala, Stripe, Ysepay, and Yseqt.
- Remaining direct cURL fallbacks without bounds: QR login and ASN.1 OID web lookup.

## Error Handling

- Preserve each client's existing exception or false-return contract.
- A timeout follows the same path as any existing cURL error.
- Do not globally change PDO error mode because that would alter unrelated web request behavior.
- Do not add automatic retries to payment-creating API calls, avoiding duplicate submissions for non-idempotent endpoints.

## Payment Status Refresh

`getshop.php` will return no-cache headers so an unpaid response cannot be reused after the worker marks an order paid. The Alipay code-payment page will use a single in-flight status request, poll once per second, and trigger an immediate check on `focus`, `pageshow`, and visibility restoration. A successful status response redirects through a real timeout callback rather than assigning `window.location.href` while constructing the timer.

## Verification

- Run `php -l` on every changed PHP file.
- Scan all `curl_exec()` call sites and confirm each request block has a total timeout.
- Confirm the worker checks database query failure with strict `=== false` before the empty-list branch.
- Confirm the pending-order and account-log windows remain 8 minutes and 3 minutes.
- Confirm the Alipay code-payment page disables AJAX caching and has foreground/resume status checks.

## Deployment

After deploying the changed files, reload and restart the Supervisor program. Existing Supervisor `autorestart=true` then handles deliberate worker exits caused by database connection failures.
