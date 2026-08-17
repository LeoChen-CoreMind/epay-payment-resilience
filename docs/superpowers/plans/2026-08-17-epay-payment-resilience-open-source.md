# Epay Payment Resilience Open-Source Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish the two completed Epay payment-reliability fixes as a source-applied patch kit that never redistributes complete Epay or bundled SDK files.

**Architecture:** Ship six repository-original runtime/migration modules under `src/`, one focused test under `tests/`, a minimal unified patch for existing Epay paths, and a TSV manifest containing exact original and final SHA-256 values. A strict Bash installer stages, patches, lints, verifies, backs up, atomically installs, and automatically rolls back without running the migration or restarting Supervisor.

**Tech Stack:** PHP 7.4+, Bash, Git unified patches, SHA-256, GitHub Actions, GitHub CLI, Supervisor, PDO/MySQL.

## Global Constraints

- Public repository: `LeoChen-CoreMind/epay-payment-resilience`, branch `main`, release `v1.0.0`.
- Supported baseline: Epay database version `2055`, source commit `a4d0f0421cfc` from `https://github.com/maajiko/Epay`.
- Do not track any complete pre-existing Epay or bundled SDK file.
- Do not track `config.php`, database data, logs, credentials, keys, certificates, deployment archives, or local absolute paths.
- Pending-order window stays exactly 8 minutes.
- Normal Alipay bill lookback stays exactly 180 seconds.
- Recovery windows stay exactly 240 seconds; cycle budget stays exactly 120 seconds.
- Keep one serial Supervisor worker with `numprocs=1`.
- Unknown baselines and symbolic-link target paths are rejected without an override.
- Installer backups are outside the web root and every handled partial install rolls back automatically.
- MIT applies only to repository-original work; patch context and target files retain upstream rights.
- GitHub publication must use a clean root history that never contained the removed full-file Overlay.

---

## File Map

Create or retain:

- `src/plugins/alipaycode/inc/AlipayCodeReconciler.php`: linear trade matching.
- `src/plugins/alipaycode/inc/AlipayCodePaginator.php`: guarded complete pagination.
- `src/plugins/alipaycode/inc/AlipayCodeRecoveryStore.php`: durable recovery state.
- `src/plugins/alipaycode/inc/AlipayCodeWindowQueue.php`: fixed recovery-window queue.
- `src/install/AlipayCodeIndexPlanner.php`: exact index inspection/planning.
- `src/install/alipaycode_performance.php`: idempotent migration CLI.
- `tests/alipaycode_reconciler_test.php`: focused unit/integration-style test.
- `patches/epay-2055.patch`: minimal changes to existing local Epay files.
- `manifests/epay-2055.tsv`: `path`, original SHA-256, final SHA-256.
- `scripts/install.sh`: transactional source-applied installer.
- `.github/workflows/verify.yml`: lint, tests, pinned-baseline install tests, scope/history audit.
- `README.md`, `README.zh-CN.md`, `docs/*`, `NOTICE`, `CHANGELOG.md`, `SECURITY.md`, `LICENSE`, `SHA256SUMS`.

Remove:

- `overlay/` in full.
- `third_party/` in full.

---

### Task 1: Build the Copyright-Safe Payload

**Files:**
- Create: `src/**`, `tests/alipaycode_reconciler_test.php`, `patches/epay-2055.patch`, `manifests/epay-2055.tsv`
- Delete: `overlay/**`, `third_party/**`

- [ ] Copy only the six files absent from the pinned upstream commit from `overlay/` to `src/`.
- [ ] Move the focused test to `tests/` and update its `require` paths to `../src/`.
- [ ] In a disposable clone of `a4d0f0421cfc`, copy only the twenty locally modified existing files, then generate `patches/epay-2055.patch` with `git diff --ignore-cr-at-eol`.
- [ ] Confirm the patch changes exactly the twenty approved paths and contains no `config.php`, credential, local path, complete file addition, or binary payload.
- [ ] Record each existing target's original and post-patch SHA-256 in `manifests/epay-2055.tsv`, sorted by path.
- [ ] Delete `overlay/` and `third_party/` only after the patch, payload, and manifest are independently verified.
- [ ] Run `php -l` on all PHP under `src/` and `tests/`, then run `php tests/alipaycode_reconciler_test.php`.

Expected: six original runtime/migration files and one test remain; no complete upstream file is tracked.

### Task 2: Implement the Transactional Installer

**Files:**
- Rewrite: `scripts/install.sh`

- [ ] Parse exactly `EPAY_ROOT BACKUP_BASE`; reject extra flags and missing tools (`git`, `php`, `realpath`, `sha256sum`, `mktemp`).
- [ ] Validate package checksums before reading or changing target paths.
- [ ] Parse `manifests/epay-2055.tsv`; reject unsafe relative paths, missing regular files, target symlinks, symlink parent components, and resolved parents outside the Epay root.
- [ ] Classify each existing target hash as `baseline`, `patched`, or `unknown`; reject `unknown` before creating a backup.
- [ ] Classify each `src/` destination as `missing`, `identical`, or `unknown`; reject an unknown existing destination.
- [ ] Create a private unique backup and staging directory outside the web root.
- [ ] Copy local target files into staging, apply the patch only to baseline paths with `git apply --include`, and copy the six original modules into staging.
- [ ] Lint every staged PHP file and verify every final hash against the manifest or source payload.
- [ ] Back up every pre-existing destination, record created files, atomically install staged files, and verify each installed hash.
- [ ] Roll back installed and created files after `ERR`, `INT`, `TERM`, or `HUP`.
- [ ] Print migration and Supervisor commands without executing them.

Expected: install is idempotent, rejects unknown local edits, and never blindly replaces a complete file.

### Task 3: Add Installer and Patch Verification

**Files:**
- Rewrite: `.github/workflows/verify.yml`

- [ ] Pin checkout/setup actions by full commit SHA and keep `contents: read`.
- [ ] Test PHP 7.4, 8.2, and 8.5 lint plus `tests/alipaycode_reconciler_test.php`.
- [ ] Clone `maajiko/Epay`, checkout `a4d0f0421cfc`, and assert its required baseline hashes match the manifest.
- [ ] Run a normal install and verify all twenty patched files and six new files.
- [ ] Run a second install and verify it is idempotent with a distinct backup.
- [ ] Change existing `plugins/alipaycode/inc/AlipayCodeReconciler.php`; assert install refuses it before target mutation.
- [ ] Change one baseline target; assert install refuses it before target mutation.
- [ ] Replace `target/includes` with a symlink to an external directory; assert refusal and prove the external sentinel file is unchanged.
- [ ] Inject a write-phase failure after at least one replacement and prove automatic rollback restores the baseline and removes created paths.
- [ ] Verify the 8-minute, 180-second, 240-second, and 120-second invariants in the patch/payload.
- [ ] Verify every patched `curl_exec()` path has both connection and total timeouts after applying the patch.
- [ ] Verify `SHA256SUMS`, approved paths, no complete Epay files, no excluded files, and no sensitive pattern in any Git commit.

Expected: CI exercises the real pinned baseline without storing it in this repository.

### Task 4: Rewrite Public Documentation and Notices

**Files:**
- Rewrite: `README.md`, `README.zh-CN.md`, `docs/FIXES.md`, `docs/INSTALL.md`, `docs/OPERATIONS.md`, `NOTICE`, `CHANGELOG.md`, `SECURITY.md`

- [ ] Replace every Overlay/full-file-replacement statement with source-applied patch-kit language.
- [ ] State that operators supply their own compatible Epay `2055` source and unknown baselines are rejected.
- [ ] Document exact install, migration, Supervisor, monitoring, and rollback commands.
- [ ] Explain the two fixes and preserve the 8-minute/3-minute business-window statement.
- [ ] Explain that MIT covers original modules/tooling/docs only; upstream patch context and target files retain upstream rights.
- [ ] Retain the global `Payment::processOrder()` known limitation without claiming zero order loss.
- [ ] Scan documentation for the production domain, local paths, old `overlay/` instructions, and absolute reliability claims.

Expected: documentation accurately describes the source-applied model and both fixes.

### Task 5: Final Local Audit and Review

**Files:**
- Regenerate: `SHA256SUMS`
- Verify: entire repository

- [ ] Generate sorted SHA-256 entries for `src/`, `tests/`, `patches/`, `manifests/`, and `scripts/`.
- [ ] Run Bash syntax, PHP lint, focused tests, patch checks, installer success/idempotency/refusal/symlink/fault tests, and rollback rehearsal.
- [ ] Run the 10,000-by-10,000 benchmark and record elapsed time and peak memory.
- [ ] Run sensitive-data and full-file scope scans.
- [ ] Invoke `requesting-code-review`; resolve all Critical and Important findings.
- [ ] Invoke `verification-before-completion`; rerun affected checks after the final change.

Expected: clean, evidence-backed release candidate.

### Task 6: Replace the Unpublished Git History

**Files:**
- Rewrite: local Git history only

- [ ] Confirm no remote exists and no current commit was published.
- [ ] Create a clean orphan `main` root containing only the reviewed source-applied package.
- [ ] Verify `git rev-list --all` exposes only the clean history and `git grep` across every commit finds no removed Overlay, production domain, or local user path.
- [ ] Preserve the old local history only in an untracked local backup reference if needed for recovery; delete that reference before publication and repeat the history scan.

Expected: GitHub can never receive the earlier complete-file Overlay through any ref.

### Task 7: Publish Repository and Release

**Remote:**
- Create: `https://github.com/LeoChen-CoreMind/epay-payment-resilience`
- Create: tag/release `v1.0.0`

- [ ] Reconfirm authenticated GitHub identity and repository name availability.
- [ ] Create the public repository and push only clean `main`.
- [ ] Set description to `Source-applied reliability and reconciliation fixes for Epay payment workers.`
- [ ] Add topics: `epay`, `php`, `alipay`, `payment`, `supervisor`, `reconciliation`, `reliability`.
- [ ] Enable private vulnerability reporting through the repository security API.
- [ ] Wait for the `Verify` workflow and require success.
- [ ] Tag the exact successful commit as annotated `v1.0.0`.
- [ ] Build a Git archive ZIP and independent SHA-256 file, inspect its file list, and publish both assets.
- [ ] Download the published assets and verify the checksum and exclusion contract.

Expected: public repository, green Actions, verified `v1.0.0` release, and no redistributed complete Epay file.
