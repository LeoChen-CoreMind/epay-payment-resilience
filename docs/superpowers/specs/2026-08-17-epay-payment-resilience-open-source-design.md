# Epay Payment Resilience Open-Source Package

## Purpose

Publish the completed payment-reliability work at
`LeoChen-CoreMind/epay-payment-resilience` without redistributing the Epay
application or complete files copied from Epay and its bundled SDKs.

The first release is `v1.0.0`. The package name remains **Epay Payment
Resilience**.

## Selected Distribution Model

Use a source-applied patch kit:

- repository-original PHP modules are shipped under `src/`;
- changes to pre-existing Epay and bundled SDK files are shipped as a minimal
  unified patch under `patches/`;
- a manifest records the compatible upstream commit and the SHA-256 of every
  original and patched target file;
- `scripts/install.sh` applies the patch only to files in the operator's local
  Epay installation.

The repository must not contain a complete replacement copy of any existing
Epay or Tencent `QC.php` file. The patch contains only the context required to
describe the changes and is not represented as relicensing upstream material.

## Compatibility Baseline

The initial supported baseline is Epay database version `2055`, represented by
public source commit:

```text
repository: https://github.com/maajiko/Epay
commit: a4d0f0421cfc
```

The upstream repository is used only to identify and test the compatible
baseline. Its source is not copied into this repository. Installation fails
closed when a required local target does not match either the recorded
original hash or the recorded post-patch hash.

## Public Repository Layout

```text
epay-payment-resilience/
|-- .github/workflows/verify.yml
|-- docs/
|   |-- FIXES.md
|   |-- INSTALL.md
|   `-- OPERATIONS.md
|-- manifests/epay-2055.tsv
|-- patches/epay-2055.patch
|-- scripts/install.sh
|-- src/
|   |-- install/
|   |   |-- AlipayCodeIndexPlanner.php
|   |   `-- alipaycode_performance.php
|   `-- plugins/alipaycode/inc/
|       |-- AlipayCodePaginator.php
|       |-- AlipayCodeReconciler.php
|       |-- AlipayCodeRecoveryStore.php
|       `-- AlipayCodeWindowQueue.php
|-- tests/alipaycode_reconciler_test.php
|-- CHANGELOG.md
|-- LICENSE
|-- NOTICE
|-- README.md
|-- README.zh-CN.md
|-- SECURITY.md
`-- SHA256SUMS
```

## Patch Scope

The patch changes only the existing paths needed for the two documented
reliability fixes:

- Alipay code worker, bill query integration, browser status polling, and
  non-cacheable status responses;
- shared and payment-specific cURL timeout configuration;
- two order-table indexes for the exact pending-order query shapes.

The full list is recorded in `manifests/epay-2055.tsv`. No complete target file
is stored in the public repository.

## Fix 1: Payment Succeeds but the Page Does Not Refresh

The package bounds payment-related network waits, makes database query failure
terminate the long-running worker so Supervisor recreates process state,
rechecks persisted order status after notification processing, disables status
response caching, and uses single-flight browser polling with immediate
foreground refresh.

The result is prompt discovery of a successfully persisted payment without a
manual service restart being the normal recovery path.

## Fix 2: Burst Orders Stall Reconciliation or Miss Bill Pages

The package replaces cross-product matching with `O(N+M)` trade-number lookup,
uses guarded complete Alipay pagination, adds the two query indexes, persists
fixed 240-second recovery windows in `pre_cache`, and applies a 120-second cycle
budget with durable requeueing.

The configured business windows remain unchanged:

- pending Epay orders: 8 minutes;
- normal Alipay bill lookback: 180 seconds (3 minutes).

## Installer Transaction

`scripts/install.sh EPAY_ROOT BACKUP_BASE` performs these phases:

1. Verify package checksums, required tools, target layout, path safety, and the
   exact baseline/post-patch hashes from the manifest.
2. Copy all touched local files to a private unique backup outside the web root.
3. Build a staging tree from the operator's local files.
4. Apply the patch to baseline files in staging, skip already-patched files,
   copy original `src/` modules, lint staged PHP, and verify all final hashes.
5. Atomically replace target files one path at a time.
6. Automatically restore every touched path after a handled failure.

The installer never runs the database migration and never restarts Supervisor.
Unknown baselines are rejected; there is no override that permits blind
full-file replacement.

The installer rejects symbolic-link target files, symbolic-link parent
directories, backup paths inside the web root, and resolved target parents that
escape the Epay root.

## Testing

Local and GitHub verification must cover:

- PHP lint and the focused reconciliation/recovery/index tests;
- exact 8-minute, 180-second, 240-second, and 120-second invariants;
- successful patch application to the pinned baseline;
- idempotent reinstall on an already-patched tree;
- refusal of an unknown modified baseline;
- refusal when a target parent is a symbolic link to an external directory;
- unique backup creation, manual rollback, and automatic rollback after a
  mid-install fault;
- 10,000-order by 10,000-detail linear matching;
- connection and total timeout coverage for every changed `curl_exec()` path;
- repository and complete Git-history scans for sensitive values, local paths,
  archives, complete Epay files, and excluded deployment data.

## Documentation and Licensing

MIT applies to repository-original modules, tests, installer logic, and
documentation. `NOTICE` states that Epay/SDK patch context and target files
retain their upstream rights. No third-party license text is bundled unless a
redistributed file actually requires it.

Documentation must state that users supply their own compatible Epay source.
It must not claim that the complete Epay application is included or relicensed.

## Operations Contract

The supported runtime remains one serial Supervisor process with
`numprocs=1`, bounded rotating logs, process-group termination, and no
connection pool, persistent PDO connection, second consumer, or external
queue.

## Known Limitation

This release does not redesign the global `Payment::processOrder()` state
machine. That existing flow can mark an order paid before every downstream
financial side effect has completed. Documentation must distinguish this
cross-plugin limitation from worker liveness, pagination, durable recovery, and
browser refresh.

## Release Gates

Before publication:

1. Remove the old unpublished Overlay history and create a clean root history
   that has never contained complete Epay files or local identifiers.
2. Pass all local tests and a read-only code review.
3. Create the public repository, set description/topics, and enable private
   vulnerability reporting.
4. Require the GitHub Actions workflow to pass before tagging `v1.0.0`.
5. Build, publish, download, and independently verify the release ZIP and its
   SHA-256 file.

## Acceptance Criteria

The work is complete when the public repository and `v1.0.0` release exist,
contain only original modules and source-applied patch material, pass local and
GitHub verification, install and roll back safely on the pinned baseline, and
contain no complete Epay distribution, configuration, credential, key, local
absolute path, or historical copy of the removed Overlay.
