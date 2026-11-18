# Custom Date-Range Picker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace native Custom date inputs with a two-month vanilla-JS range calendar matching the supplied reference.

**Architecture:** Keep existing Blade period popover and GET contract. Replace only Custom panel inputs with a calendar shell; vanilla JS owns temporary calendar view and selected range, then writes hidden `from`/`to` fields on apply. Existing controller and metrics remain unchanged.

**Tech Stack:** Laravel Blade, vanilla JavaScript, existing CSS, Laravel feature tests, Pint.

**Spec:** `docs/superpowers/specs/2026-11-18-custom-date-range-picker-design.md`

## Global Constraints

- No new dependency or frontend build step.
- Preserve `period=custom`, `from`, and `to` URL parameters.
- Do not mutate active filter values before **Terapkan**.
- Use exact `YYYY-MM-DD` internal values.
- Custom picker must support dates across displayed months and leap-year February.
- Existing Bulanan, Kuartalan, Reset, and pages with `showPeriod=false` remain functional.

## Review Focus

- Range crossing December/January: navigation and date comparison must keep correct year.
- Leap-year February: calendar must render February 29 only in leap years.
- Clicking an earlier second date: starts a new range instead of producing reversed dates.
- Incomplete range: Terapkan must not submit invalid empty/partial range.
- Mobile width: two calendars must not create horizontal document overflow; calendar buttons remain usable.

---

### Task 1: Add regression contract tests

**Files:**
- Modify: `tests/Feature/FilterBarTest.php`

**Interfaces:**
- Consumes: existing period popover response.
- Produces: rendered contract for custom calendar controls and hidden GET fields.

- [ ] **Step 1: Write failing tests**

Add tests asserting dashboard Custom markup includes calendar navigation, two calendar containers, `Dari tanggal`, `Sampai tanggal`, hidden `from`/`to` values, and calendar accessibility hooks. Add a test that `/inventory` still omits period/calendar controls.

- [ ] **Step 2: Run focused tests**

Run: `php artisan test tests/Feature/FilterBarTest.php`

Expected: FAIL because Custom currently renders native date inputs only.

---

### Task 2: Replace Custom markup with calendar shell

**Files:**
- Modify: `resources/views/partials/filters.blade.php`

**Interfaces:**
- Consumes: active `from`, `to`, and current period state.
- Produces: custom panel hooks: `[data-range-picker]`, `[data-range-prev]`, `[data-range-next]`, `[data-range-months]`, `[data-range-from]`, `[data-range-to]`, `[data-range-summary]`, and `[data-range-error]`.

- [ ] **Step 1: Add two-month calendar markup**

Replace Custom native date inputs with two compact read-only date displays/inputs, previous/next buttons, a two-calendar container, range summary, validation message, Batal, and Terapkan. Keep hidden form fields as the only named `from`/`to` inputs.

- [ ] **Step 2: Implement calendar rendering in existing period script**

Add state `{ viewStart, from, to }`. Render each month using buttons for every visible date, including adjacent-month filler days if needed. Use component-date arithmetic, not date-string parsing that can shift timezone. Add `aria-label`, `aria-pressed`, and classes for selected/start/end/in-range.

- [ ] **Step 3: Implement range selection**

First click sets `from` and clears `to`; second click on same/later date sets `to`; second click earlier than `from` starts a new range. Update both date displays and summary without touching hidden active values.

- [ ] **Step 4: Implement navigation and validation**

Previous/next shifts `viewStart` by one month. Terapkan rejects incomplete range, exposes a visible message, and does not submit. Valid range sets `period=custom`, hidden `from`/`to`, closes picker, and submits the existing form. Batal and Escape discard draft state.

- [ ] **Step 5: Run focused tests**

Run: `php artisan test tests/Feature/FilterBarTest.php`

Expected: PASS.

---

### Task 3: Style two-month range picker

**Files:**
- Modify: `public/css/app.css`

**Interfaces:**
- Consumes: Task 2 calendar classes/data hooks.
- Produces: reference-like desktop and mobile layout.

- [ ] **Step 1: Add calendar grid styles**

Style month headings, weekday row, 7-column date grid, filler days, selected endpoints, and in-range highlight using existing color tokens and visible focus states.

- [ ] **Step 2: Add date display and navigation styles**

Style compact From/To fields, arrow buttons, summary footer, validation message, and action buttons to match existing popover.

- [ ] **Step 3: Add mobile rules**

At mobile breakpoint, use a single-column calendar layout or compact two-column layout that fits 390px, with no horizontal overflow and touch-friendly date buttons.

---

### Task 4: Browser and full verification

**Files:**
- No new files.

**Interfaces:**
- Consumes: Tasks 1–3.
- Produces: verified picker behavior.

- [ ] **Step 1: Run focused test and Pint**

Run:

```text
php artisan test tests/Feature/FilterBarTest.php
vendor\\bin\\pint --test tests/Feature/FilterBarTest.php resources/views/partials/filters.blade.php public/css/app.css
```

Expected: PASS.

- [ ] **Step 2: Verify browser desktop**

At approximately 1440×900: open Custom, select `01/10/2026` and `31/12/2026`, verify cross-month highlight and apply URL. Verify earlier second click starts a new range, incomplete range shows error, and Batal preserves old filter.

- [ ] **Step 3: Verify browser mobile**

At approximately 390×844: open Custom, verify no horizontal overflow, calendar buttons are visible/focusable, and apply produces correct URL.

- [ ] **Step 4: Run full suite**

Run: `php artisan test`

Expected: all tests pass; report test count and Pint result.
