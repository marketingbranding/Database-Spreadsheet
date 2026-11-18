# Filter Periode Popover Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace period select/date inputs with a responsive, keyboard-accessible Bulanan/Kuartalan/Custom popover while preserving existing URL filter behavior.

**Architecture:** Keep `period`, `from`, and `to` as ordinary GET fields so existing controllers and metrics stay unchanged. Render one period trigger and popover in the shared Blade partial; use small vanilla JavaScript for temporary state, tab selection, month/quarter selection, apply/cancel, and reset-safe display. Add CSS to existing stylesheet only.

**Tech Stack:** Laravel Blade, vanilla JavaScript, existing CSS, PHPUnit/Pest-compatible Laravel feature tests, Pint.

**Spec:** `docs/superpowers/specs/2026-11-18-period-filter-design.md`

## Global Constraints

- No new dependency or frontend build step.
- Preserve URL parameters `period`, `from`, and `to`.
- Do not submit or mutate URL before **Terapkan**.
- **Reset** clears all query parameters and returns period to `ytd`.
- Custom uses native date inputs; no custom calendar implementation.
- Existing pages with `showPeriod=false` keep current filter behavior and do not render period controls.
- All controls remain keyboard accessible and usable at mobile width.

## Review Focus

- Existing `custom` URL with only one date: display valid fallback without losing submitted values.
- Month or quarter selection: derive exact inclusive `from`/`to` dates, including leap-year February.
- Cancel after unsaved changes: active URL-backed selection and trigger label remain unchanged.
- Reset from a filtered URL: link removes every query parameter and renders `Tahun berjalan`.
- Narrow viewport and keyboard navigation: no horizontal overflow; tabs, choices, Batal, Terapkan, and close behavior are reachable by keyboard.

---

### Task 1: Lock period-filter behavior with feature tests

**Files:**
- Modify: `tests/Feature/FilterBarTest.php`

**Interfaces:**
- Consumes: existing dashboard GET filter contract.
- Produces: regression coverage for rendered controls, URL-backed labels, and page variants.

- [ ] **Step 1: Write failing tests**

Add tests that assert:

- dashboard renders one period trigger and the three tab labels;
- dashboard with `period=custom&from=2026-09-01&to=2026-09-30` renders the active custom summary and hidden/submit fields with those values;
- dashboard with `period=2026&from=2026-10-01&to=2026-12-31` renders the period controls without auto-submit handlers;
- `/inventory` still omits all period controls because `showPeriod=false`;
- filter actions contain exactly one `Terapkan` and one `Reset`.

- [ ] **Step 2: Run focused tests to verify RED**

Run: `php artisan test tests/Feature/FilterBarTest.php`

Expected: FAIL because current select/date markup does not contain popover tabs and trigger summary.

- [ ] **Step 3: Keep assertions behavior-focused**

Assert visible labels and submitted field values, not exact CSS ordering or JavaScript implementation details.

---

### Task 2: Build shared period popover markup and state contract

**Files:**
- Modify: `resources/views/partials/filters.blade.php`

**Interfaces:**
- Consumes: `$filters['period']`, `request('from')`, `request('to')`, `$showPeriod`.
- Produces: `period-filter` markup with named GET fields `period`, `from`, and `to`, plus stable `data-*` hooks for vanilla JS.

- [ ] **Step 1: Replace current period select/date block**

Render a single accessible button showing the active period summary. Render a hidden popover containing:

- tab buttons `Bulanan`, `Kuartalan`, `Custom`;
- year select and 12 month buttons in Bulanan;
- year select and Q1–Q4 buttons in Kuartalan;
- native `date` inputs labeled `Dari tanggal` and `Sampai tanggal` in Custom;
- selected-period summary;
- `Batal` and `Terapkan` actions.

Keep form controls named `period`, `from`, and `to`; only the final form submit applies the staged values.

- [ ] **Step 2: Render URL-backed initial state**

Map current request values to the popover: `ytd` uses current year-to-date, `2025`/`2026` use full year, `all` uses all-time label, and `custom` uses submitted dates. Use `CarbonImmutable` in the view only for display/derived initial values already available from the request; do not change controller logic.

- [ ] **Step 3: Add inline vanilla JS scoped to `.period-filter`**

Implement temporary state with these behaviors:

- open/close trigger and popover;
- switch tabs without submitting;
- month selection sets `period=custom`, first/last day of selected month;
- quarter selection sets `period=custom`, quarter start/end dates;
- Custom edits update staged dates;
- Batal restores state captured at open and closes;
- Terapkan copies staged values to form fields and closes, leaving normal form submit to the shared filter action;
- Escape and outside click close without applying;
- update button and summary text after apply.

Use native buttons, labels, and `aria-expanded`, `aria-controls`, `aria-selected`, and a dialog-like role/state where needed. Do not trap focus with custom code unless existing project patterns require it.

---

### Task 3: Style desktop and mobile period popover

**Files:**
- Modify: `public/css/app.css`

**Interfaces:**
- Consumes: classes/data hooks from Task 2.
- Produces: visual layout matching supplied references and responsive behavior.

- [ ] **Step 1: Add period trigger/popover styles**

Use existing tokens, borders, radius, typography, and button colors. Make popover positioned relative to filter control, with tabs, active underline, summary footer, and visible focus rings.

- [ ] **Step 2: Add month/quarter grids**

Use four columns for months on desktop and two columns for quarters. Use a clear selected state that is not color-only: selected button remains labeled and has focus/selected styling.

- [ ] **Step 3: Add mobile rules**

At existing mobile breakpoint, make popover fit viewport width, stack controls, preserve readable touch targets, and prevent document horizontal overflow. Keep filter action buttons usable.

---

### Task 4: Verify behavior and visual output

**Files:**
- No new files.

**Interfaces:**
- Consumes: Tasks 1–3.
- Produces: tested period filter across supported pages and viewport sizes.

- [ ] **Step 1: Run focused tests**

Run: `php artisan test tests/Feature/FilterBarTest.php`

Expected: PASS.

- [ ] **Step 2: Run formatting check**

Run: `vendor\\bin\\pint --test tests/Feature/FilterBarTest.php resources/views/partials/filters.blade.php public/css/app.css`

Expected: PASS.

- [ ] **Step 3: Verify browser interactions**

Use existing local server `http://localhost:8001` when available. Check dashboard at approximately `1440x900` and `390x844`:

- open period trigger;
- choose November 2026 in Bulanan, click Terapkan, confirm URL dates and KPI;
- choose Q4 2026 in Kuartalan, click Batal, confirm prior filter remains;
- choose Custom dates, apply, confirm URL;
- click Reset, confirm clean URL and `Tahun berjalan`;
- inspect console for errors and document scroll width for mobile.

- [ ] **Step 4: Run full verification**

Run: `php artisan test`

Expected: all tests pass with no skipped tests. Report final test count and Pint result.
