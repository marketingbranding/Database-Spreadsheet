# Stage Navigation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose all seven operational stages in the sidebar (Indonesian labels, badges, project-context preservation), add a PSJB/UTJ stage page, and give every stage page Antrean/Riwayat tabs plus a mobile hamburger drawer.

**Architecture:** Reuse the existing eligibility queries and controllers. New `StageQueue` service (PSJB queue, block reasons, badge counts), new `BuildsStageHistory` concern + shared partials for tabs/history, a `StageSelectController` project selector, and a `StageBadgeComposer` view composer. Sidebar/hamburger changes live in `layouts/app.blade.php` + `public/css/app.css`.

**Tech Stack:** Laravel Blade, static CSS (`public/css/app.css`), PHPUnit feature tests, Playwright for browser verification.

**Spec:** `docs/superpowers/specs/2026-11-18-stage-navigation-design.md`

## Global Constraints

- Staging pilot only: pilot codes `['PRJ-JPR-KWS', 'PRJ-MGL-KLG']`, `management_mode === Project::MANAGEMENT_LARAVEL`, and `$user->canAccessProject()` everywhere stage access is granted.
- Indonesian UI labels everywhere new UI is added.
- Badge counts use the exact same queries as each Antrean tab, before pagination.
- Riwayat paginates by booking (20/page) so one booking's events stay together.
- No schema/migration changes; no changes to eligibility rules or workflow services.
- Mobile sidebar: keyboard-accessible button, `aria-expanded` + `aria-controls`, closes on Escape and menu selection.
- Styling in `public/css/app.css` only (Vite build blocked); JS may only be an inline script in the layout.

## Review Focus

- Cross-project leakage: a MGL admin selecting a JPR stage through the selector or a crafted `?project=` must get 403 — pinned in StageNavigationTest.
- Badge/queue consistency: badge count equals what the Antrean tab renders for each of the seven stages — pinned per stage in StageNavigationTest and PsjbStageTest.
- Riwayat integrity: repeated bank submissions and pemberkasan revisions all render, grouped under one booking per page — pinned in StageHistoryTest.
- Block-reason correctness: disputed lot shows reason + conflict link only when the user can access the conflict module; no link when the gate is off — pinned in PsjbStageTest.
- Query cost: badge composer adds 7 counts per page with project context; verify with the query log that page render stays small (no N+1, no per-row extra queries) — verified in Task 7.

---

### Task 1: `StageQueue` service + `Project::isOperationalStaging()`

**Files:**
- Create: `app/Services/Operations/StageQueue.php`
- Modify: `app/Models/Project.php` (add helpers at end of class)
- Test: `tests/Feature/StageQueueTest.php`

**Interfaces:**
- Produces:
  - `Project::stagingPilotCodes(): array` → `['PRJ-JPR-KWS','PRJ-MGL-KLG']`
  - `Project::isOperationalStaging(): bool` → laravel mode + code in pilot list
  - `StageQueue::psjbQueue(Project $project): Eloquent\Builder` → ACTIVE + stage PSJB, with(`lot`,`buyer`,`psjb`), ordered by `booking_date,id`
  - `StageQueue::psjbQueueCount(Project $project): int`
  - `StageQueue::queueCounts(Project $project, PsjbWorkflow $workflow): array<string,int>` → keys `psjb, bi, pemberkasan, bank, ppjb, akad, bast` (`psjb` from `psjbQueueCount`, others from the existing `eligible*Bookings` queries via `->count()`)
  - `StageQueue::blockReason(User $user, Booking $booking, bool $canViewConflicts, PsjbWorkflow $workflow): ?array{reason:string, conflict:?ImportConflict}`

`blockReason` returns in priority order: open allocation dispute on the booking's lot
(`ImportConflict::openAllocationDisputesForLot($booking->project_id, $booking->lot_id)->first()`,
reason `Kavling memiliki konflik alokasi yang belum selesai.`) → user not branch admin
(`Aksi memerlukan admin cabang.`) → CASH booking not in `eligiblePemberkasanBookings`
(`Booking belum memenuhi syarat Pemberkasan.`) → `null`.

- [ ] **Step 1: Write the failing test** — `StageQueueTest` (extends `tests/TestCase` with the existing staging-user fixture pattern from `PsjbWorkflowTest`): (a) `stagingPilotCodes`/`isOperationalStaging` true for MGL pilot laravel project, false for sheets-mode project; (b) `psjbQueue` returns only ACTIVE+PSJB bookings (create one ACTIVE/PSJB, one MUNDUR/PSJB, one ACTIVE/BI_CHECKING); (c) `queueCounts` matches the individual eligibility counts for the same fixtures; (d) `blockReason` returns the dispute reason + conflict for a booking whose lot has an open `STOCK_DISAGREEMENT` conflict, null for a clean CASH booking.
- [ ] **Step 2: Run test** — `php artisan test tests/Feature/StageQueueTest.php` → FAIL (class not found).
- [ ] **Step 3: Implement** the service and model helpers.
- [ ] **Step 4: Run test** → PASS.
- [ ] **Step 5: Commit** — `feat: add stage queue service and project staging helpers`

### Task 2: Stage tabs + shared Riwayat concern and partials

**Files:**
- Create: `app/Http/Controllers/Concerns/BuildsStageHistory.php`
- Create: `resources/views/partials/stage-tabs.blade.php`
- Create: `resources/views/partials/stage-history.blade.php`
- Modify: `public/css/app.css` (`.stage-tabs`, `.stage-history` list styles)
- Test: `tests/Feature/StageHistoryTest.php`

**Interfaces:**
- Produces:
  - `BuildsStageHistory::stageHistory(Project $project, string $stage): LengthAwarePaginator<array{booking: Booking, events: Collection}>` — paginates bookings by 20; `events` items are `['date' => ?Carbon, 'label' => string, 'detail' => string]`.
  - Stage-key → eager-load map inside the concern: `psjb` (`psjb`, `predecessor`, `successors`, `transition`), `bi` (`biCheck`), `pemberkasan` (`pemberkasan`), `bank` (`bankSubmissions`), `ppjb` (`ppjb`), `akad` (`akad`), `bast` (`bast`), always with `lot`, `buyer`.
  - `stage-tabs.blade.php` takes `string $active` (`antrean|riwayat`) and renders two links preserving all current query params except `tab` and `page`, `aria-current="page"` on the active one.
  - `stage-history.blade.php` takes the paginator and renders table `Kavling | Konsumen | Peristiwa (list with dates) | Status` plus `Lihat Riwayat` link to `bookings.show`; for `psjb` stage adds `Siklus` column with predecessor/successor booking links and transition type.

Event normalization per stage (match on stage key):
- `psjb`: one event per booking — label `PSJB dibuat`, date `psjb->psjb_date ?? booking_date`, detail `psjb_number`; plus one event per `transition` (`Ganti nama`/`Pindah kavling` + effective date); plus predecessor/successor cycle rows as links.
- `bi`: `biCheck` records → `BI Checking` + `check_date` + `decision`.
- `pemberkasan`: each `pemberkasan` revision → `Pemberkasan rev. N` + `received_date` + `status`.
- `bank`: each `bankSubmission` → `Pengajuan bank #seq` + `submitted_date` + `result ?? 'Menunggu'`.
- `ppjb`: `ppjb` → `PPJB ditandatangani` + `signed_date` + `ppjb_number`.
- `akad`: `akad` → `Akad` + `akad_date` + `akad_number`.
- `bast`: `bast` → `BAST` + `bast_date` + `bast_number`.
Bookings with no stage record still appear (they are the booking's history of not
having reached the stage); PSJB always has a booking row.

- [ ] **Step 1: Write the failing test** — `StageHistoryTest`: for a project fixture with (a) two bank submissions on one booking, (b) three pemberkasan revisions, (c) a GANTI_NAMA transition pair (predecessor + successor), assert: page 1 groups all events under the booking; `events` counts match (2 bank events, 3 pemberkasan events); the transition booking shows predecessor and successor ids; paginator is per-booking (e.g. 25 bookings → 2 pages).
- [ ] **Step 2: Run test** → FAIL.
- [ ] **Step 3: Implement** the concern and both partials.
- [ ] **Step 4: Run test** → PASS.
- [ ] **Step 5: Commit** — `feat: shared stage tabs and booking-grouped history`

### Task 3: PSJB / UTJ stage page

**Files:**
- Modify: `routes/web.php` (add `Route::get('/projects/{project}/psjb', [PsjbController::class, 'index'])->name('psjb.index');` above `psjb.create`)
- Modify: `app/Http/Controllers/PsjbController.php` (add `index`)
- Create: `resources/views/psjb/index.blade.php`
- Test: `tests/Feature/PsjbStageTest.php`

**Interfaces:**
- Consumes: `StageQueue::psjbQueue`, `StageQueue::blockReason`, `BuildsStageHistory::stageHistory('psjb')`, `stage-tabs` partial.
- Produces: `PsjbController::index(Request, Project): View` — authorization `canAccessProject` + `MANAGEMENT_LARAVEL` + pilot code (view-level, HQ allowed); passes `project`, `tab`, `bookings` (antrean, when `tab=antrean`), `history` (when `tab=riwayat`), `canCreatePsjb` (bool: role branch_admin + `app.env === 'staging'` + writes flag), `blocked` map `bookingId => ?array` from `blockReason` when `tab=antrean`.
- `psjb/index.blade.php`: title `PSJB / UTJ`; toolbar shows `Buat PSJB` button (route `psjb.create`) only when `canCreatePsjb`; Antrean tab renders the queue table `Kavling | Konsumen | Tanggal PSJB/UTJ | Metode | Sales | Tindakan` where the action cell is the KPR→`bi.edit` `Periksa BI` / CASH→`pemberkasan.edit` `Buka berkas` link, or the block reason text plus `Lihat konflik` link to `import-conflicts.show` when the row is blocked and the conflict is viewable; Riwayat tab renders `stage-history`.

- [ ] **Step 1: Write the failing test** — `PsjbStageTest`: (a) branch admin sees ACTIVE/PSJB bookings with a `Periksa BI` link for a KPR row and `Buka berkas` for a CASH row; (b) a booking on a disputed lot shows the block reason and, with the gate enabled, a link to `import-conflicts.show`; (c) with the gate disabled the conflict link is absent; (d) HQ sees the queue but no `Buat PSJB` button; branch admin in staging with writes enabled sees it; (e) an unauthorized project (sheets mode) returns 403; (f) `?tab=riwayat` renders the history table including a transition booking's cycle links.
- [ ] **Step 2: Run test** → FAIL.
- [ ] **Step 3: Implement** route, controller method, view.
- [ ] **Step 4: Run test** → PASS.
- [ ] **Step 5: Commit** — `feat: PSJB/UTJ stage page with antrean and riwayat`

### Task 4: Tabs + Riwayat on the six existing stage pages

**Files:**
- Modify: `app/Http/Controllers/BiCheckController.php`, `PemberkasanController.php`, `ProsesBankController.php`, `TtdPpjbController.php`, `AkadController.php`, `BastController.php` (each `index` gains `tab`, passes existing antrean collection only for `antrean`, and `stageHistory(...)` for `riwayat`; uses the `BiCheckController::authorizeProject`-style existing private authorization untouched)
- Modify: `resources/views/bi/index.blade.php`, `pemberkasan/index.blade.php`, `proses-bank/index.blade.php`, `ttd-ppjb/index.blade.php`, `akad/index.blade.php`, `bast/index.blade.php` (each includes `stage-tabs`, wraps the existing table in the antrean branch, adds the riwayat branch with `stage-history`; headings become `Antrean <Tahap>`)
- Test: `tests/Feature/StageTabsTest.php`

**Interfaces:**
- Consumes: `BuildsStageHistory::stageHistory`, `stage-tabs` partial, existing `eligible*Bookings` queries unchanged.
- Produces: every stage index view accepts `?tab=antrean|riwayat` (default antrean) and preserves the project route param; pagination links on riwayat carry `tab=riwayat` via `withQueryString()` inside the partial.

- [ ] **Step 1: Write the failing test** — `StageTabsTest` covers all six stages in one data-provider loop: antrean renders the existing queue content for a seeded eligible booking (e.g. BI row shows `Periksa`), riwayat renders the stage's event label (e.g. BI row shows `BI Checking`, bank row shows `Pengajuan bank`, ppjb row shows `PPJB ditandatangani`, akad row shows `Akad`, bast row shows `BAST`, pemberkasan row shows `Pemberkasan rev.`), and an unknown `?tab=x` falls back to antrean. Authorization 403s for the wrong-branch admin are asserted once via BI.
- [ ] **Step 2: Run test** → FAIL.
- [ ] **Step 3: Implement** the six controllers and views.
- [ ] **Step 4: Run test** → PASS.
- [ ] **Step 5: Commit** — `feat: antrean/riwayat tabs on all operational stages`

### Task 5: Badge composer, sidebar restructure, project selector

**Files:**
- Create: `app/View/Composers/StageBadgeComposer.php`
- Create: `app/Http/Controllers/StageSelectController.php`
- Create: `resources/views/stages/select.blade.php`
- Modify: `app/Providers/AppServiceProvider.php` (register composer for `layouts.app`)
- Modify: `routes/web.php` (`Route::get('/stages/select', [StageSelectController::class, 'index'])->name('stages.select');`)
- Modify: `resources/views/layouts/app.blade.php` (nav groups, labels, badges, selector links)
- Test: `tests/Feature/StageNavigationTest.php`

**Interfaces:**
- Consumes: `StageQueue::queueCounts`, `Project::isOperationalStaging`, `Project::visibleTo`.
- Produces:
  - Composer resolves the stage project: route `project` param if it is an operational-staging `Project`; else the `?project=` query param when it resolves to an operational-staging project the user can access; attaches `stageCounts` (array, null when no project) to all layout views.
  - Layout renders three groups (headings `Umum`, `Tahapan Operasional`, `Data`) in spec order with Indonesian labels `Semua Booking`, `Konflik Data`, `Riwayat Impor`; all seven stage links always visible; a stage link goes to `stages.select?stage=<key>` when there is no stage project, else to the project route with the badge `<span class="nav-badge">N</span>` when N > 0; on portfolio pages the stage links append `?project=<code>` from the resolved stage project; `Konflik Data` keeps its current visibility conditions (gate enabled + operational-staging stage project) but uses the resolved stage project; `Rekonsiliasi`/`Riwayat Impor` unchanged globally.
  - `StageSelectController::index(Request): View` — `stage` param validated against the allowlist `psjb|bi|pemberkasan|bank|ppjb|akad|bast` (unknown → 404); lists `Project::visibleTo(user)` with per-project `available` flag (`isOperationalStaging`) and a note for gated ones (`Dikelola melalui Sheets — tahapan operasional tidak tersedia.` for sheets mode, `Tahapan operasional belum aktif untuk proyek ini.` for non-pilot laravel); available projects link straight to `route($stageRoute, ['project' => $project, 'tab' => $tab])`.

- [ ] **Step 1: Write the failing test** — `StageNavigationTest`: (a) rendered sidebar for a branch admin on `/projects/PRJ-MGL-KLG/bi-checking` lists all seven stages in order with Indonesian labels and `nav-badge` counts matching `queueCounts`; (b) on `/dashboard?project=PRJ-MGL-KLG` the stage links point to `PRJ-MGL-KLG` routes; (c) with no project context they point to `stages.select?stage=...`; (d) `/stages/select?stage=bi` for HQ lists both pilot projects and a sheets-mode project marked unavailable, and the pilot link target is `bi.index`; (e) a branch admin sees only their own project; (f) `?project=` of an inaccessible project is ignored (no stage links for it); (g) unknown `?stage=` → 404; (h) `Konflik Data` hidden when the gate is disabled, shown on a pilot project page when enabled.
- [ ] **Step 2: Run test** → FAIL.
- [ ] **Step 3: Implement** composer, controller, view, layout.
- [ ] **Step 4: Run test** → PASS.
- [ ] **Step 5: Commit** — `feat: stage badges, grouped sidebar, authorized project selector`

### Task 6: Mobile hamburger sidebar

**Files:**
- Modify: `resources/views/layouts/app.blade.php` (toggle button in topbar, `id="sidebar-nav"` on nav, inline script at end of body)
- Modify: `public/css/app.css` (replace the `max-width: 900px` sidebar block with the off-canvas drawer, backdrop, `.menu-toggle` visibility, `.nav-badge` styles if not yet added)
- Test: `tests/Feature/StageNavigationTest.php` (markup assertions)

**Interfaces:**
- Markup contract: `<button class="menu-toggle" type="button" aria-expanded="false" aria-controls="sidebar-nav">Buka menu navigasi</button>`; nav has `id="sidebar-nav"`; open state sets `data-nav-open="true"` on `<body>` and `aria-expanded="true"` on the button; script toggles on button click, closes on Escape and on any click of a `nav a` inside the sidebar; backdrop button closes and is hidden when closed.

- [ ] **Step 1: Write the failing test** — assertions in `StageNavigationTest`: the layout contains the toggle button with `aria-expanded="false"`, `aria-controls="sidebar-nav"`, the nav `id="sidebar-nav"`, and the inline script referencing `data-nav-open` and `Escape`.
- [ ] **Step 2: Run test** → FAIL.
- [ ] **Step 3: Implement** markup, CSS, script.
- [ ] **Step 4: Run test** → PASS.
- [ ] **Step 5: Commit** — `feat: collapsible mobile sidebar`

### Task 7: Full verification

- [ ] **Step 1:** `php artisan test --compact` → all pass (expect ≥ 208 tests), note the total.
- [ ] **Step 2:** `vendor\bin\pint --test <changed files>` clean (new files only; `PsjbWorkflow.php` untouched this time).
- [ ] **Step 3:** Badge query-cost check: render one stage page with the query log enabled (temporary artisan tinker script counting queries for `bi.index` with project context) → ≤ ~15 queries, no duplicate per-row queries; record the number in the report.
- [ ] **Step 4:** Browser verification (Playwright, staging `.env` with the gate enabled, real fixture data): desktop 1440×900 — sidebar groups/order/labels/badges, all seven antrean tabs, PSJB KPR/CASH routing links, one riwayat tab per stage, active menu state, project preserved navigating dashboard→stage and stage→stage; mobile 390×844 — hamburger opens/closes, closes on menu select and Escape, no horizontal overflow, tabs usable. Console error-free.
- [ ] **Step 5:** Restore `.env` to `APP_ENV=local`, gate/writes flags removed; commit docs (`docs/psjb-staging-workflow.md` navigation section) and final commit `docs: stage navigation pilot notes`; report commit hash, suite totals, and remaining limitations.
