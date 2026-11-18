# Web Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add safe web import with dry-run preview and queued apply.

**Architecture:** Extend existing `ImportController` and import views. Keep `ProjectImporter` and `ImportBatch` as canonical domain paths. Cache a signed dry-run payload briefly, then dispatch `RunProjectImport` with project ID and checksum; job reauthorizes and records final status.

**Tech Stack:** Laravel Blade, Laravel queue/cache/signature, existing importer, PHPUnit/Pest feature tests.

**Spec:** `docs/superpowers/specs/2026-11-18-web-import-design.md`

## Global Constraints

- Never accept spreadsheet ID from browser.
- Authorize project on every endpoint and job boundary.
- Dry-run must not commit operational changes.
- Apply must run in queue, not HTTP request.
- Preserve existing CLI importer and batch detail behavior.
- No new dependency.

## Review Focus

- Unauthorized project ID must fail before dispatch.
- Expired, altered, or reused dry-run token must not apply.
- Queue retry must not duplicate imported records.
- Job failure must mark batch failed without leaking credentials.
- Branch admin must not see or trigger another branch project.

---

### Task 1: Define web import contract tests

**Files:**
- Modify: `tests/Feature/ImportControllerTest.php` or create if absent.
- Inspect: `app/Models/ImportBatch.php`, existing import migrations/views.

**Interfaces:**
- Produces expected routes, authorization, dry-run response, apply response, and job dispatch contract.

- [ ] Write failing tests for:
  - accessible project renders import form;
  - inaccessible project cannot dry-run or apply;
  - dry-run returns report and does not persist operational rows;
  - valid apply dispatches job and redirects to batch detail;
  - invalid/expired/reused dry-run token is rejected.
- [ ] Run `php artisan test tests/Feature/ImportControllerTest.php`; expected RED for missing routes/actions.

### Task 2: Add queued import job

**Files:**
- Create: `app/Jobs/RunProjectImport.php`.
- Modify: `app/Models/ImportBatch.php` only if status/failure accessors are missing.
- Test: `tests/Unit/RunProjectImportTest.php` or feature job tests.

**Interfaces:**
- `RunProjectImport::dispatch(int $projectId, string $dryRunChecksum, int $requestedBy): PendingDispatch`.
- Job loads project, verifies checksum payload, invokes `ProjectImporter`, and updates batch status.

- [ ] Write failing job tests for successful execution, authorization/checksum mismatch, retry/idempotency, and failure status.
- [ ] Run focused job tests; expected RED.
- [ ] Implement job with `ShouldQueue`, bounded retries/backoff, safe failure message, and existing reader factory.
- [ ] Run focused job tests; expected GREEN.

### Task 3: Add controller endpoints and token handling

**Files:**
- Modify: `app/Http/Controllers/ImportController.php`.
- Modify: `routes/web.php`.
- Test: `tests/Feature/ImportControllerTest.php`.

**Interfaces:**
- `dryRun(Request $request): RedirectResponse` at `POST /imports/dry-run`.
- `apply(Request $request): RedirectResponse` at `POST /imports/apply`.
- Token payload includes user ID, project ID, source, report checksum, and expiry.

- [ ] Add validation and authorization tests first if not already present.
- [ ] Implement dry-run using existing reader/importer and cache signed payload with short TTL.
- [ ] Implement apply token validation, atomic token claim, batch creation, and job dispatch.
- [ ] Run feature tests; expected GREEN.

### Task 4: Add web UI

**Files:**
- Modify: `resources/views/imports/index.blade.php`.
- Modify: `resources/views/imports/show.blade.php`.
- Modify: `public/css/app.css` only where needed.

**Interfaces:**
- Project select, Dry Run button, preview counts/issues, Apply Import button, status badge, batch detail link.

- [ ] Add accessible form and preview markup with CSRF tokens and escaped report values.
- [ ] Add apply disabled state unless valid preview exists.
- [ ] Add queued/running/completed/failed status and refresh link.
- [ ] Run feature view assertions and focused tests.

### Task 5: Browser and full verification

**Files:**
- No new files.

- [ ] Run `php artisan test tests/Feature/ImportControllerTest.php`.
- [ ] Run `vendor\\bin\\pint --test app/Jobs/RunProjectImport.php app/Http/Controllers/ImportController.php tests/Feature/ImportControllerTest.php resources/views/imports/index.blade.php resources/views/imports/show.blade.php`.
- [ ] Run browser check as HQ and branch admin: project scope, dry-run preview, apply queue status, batch detail, no console errors.
- [ ] Run `php artisan test`.
- [ ] Verify queue worker command documented: `php artisan queue:work`.
