# Stage Navigation Design (sidebar expose, PSJB/UTJ page, tabs, badges, mobile)

Date: 2026-11-18 · Scope: staging pilot only (`PRJ-JPR-KWS`, `PRJ-MGL-KLG`), no schema changes.

## Intent

Expose the seven existing operational stages directly in the sidebar, in pipeline order,
with Indonesian labels, so an admin can move through the day's work without first
opening a project. Every stage page gets an **Antrean** tab (eligible work, reusing the
existing eligibility queries and authorization) and a **Riwayat** tab (recorded stage
events, paginated by booking so one booking's events stay together). A dedicated
**PSJB / UTJ** page replaces the current create-only entry point.

## Decisions (approved)

- Tabs are `?tab=antrean|riwayat` on the existing stage routes; no new per-stage routes
  except `psjb.index` (GET `/projects/{project}/psjb`) and the selector
  (`GET /stages/select?stage=<key>`).
- Badges are request-time counts from the same queries each Antrean tab uses,
  before pagination; no caching at pilot scale.
- Riwayat content is stage-event history per booking (BI records, pemberkasan
  revisions, bank submissions, PPJB/Akad/BAST records, booking transitions), each row
  linked to `bookings.show`; no data is duplicated into a second store.
- PSJB Antrean lists ACTIVE bookings whose current stage is PSJB and routes KPR to
  BI Checking and CASH to Pemberkasan via existing eligibility; blocked rows show a
  reason and, when the user can access it, a link to the import-conflict detail page.
  Shared block-reason logic lives in one service, not per view.
- When no project context is selected, a stage menu item opens a URL-based authorized
  project selector (`?stage=<key>&tab=<tab>`), which lists all projects the user can
  access and explains availability for gated ones (Sheets-managed, non-pilot, or
  conflict gate off); selection continues straight to the intended stage.
- Project context is preserved: on portfolio pages the sidebar carries a `?project=`
  filter into stage URLs; inside a project it stays in the URL segment. Tab, filter,
  and pagination context is preserved with `withQueryString()`/query merges.
- Mobile sidebar is an off-canvas drawer toggled by a hamburger button: keyboard
  accessible, `aria-expanded`/`aria-controls`, closes on Escape and on menu selection.
  Styling stays in `public/css/app.css` (Vite build remains blocked).
- Antrean tables keep the current unpaginated rendering (queue scale is small);
  Riwayat paginates at 20 bookings per page.
- Existing per-stage authorization is untouched; write eligibility and allocation
  guards are already re-checked inside the workflow services on submit.

## Non-goals

- No changes to eligibility rules, workflow services, or the conflict module logic.
- No session persistence of the selected project (URL stays the single source).
- No new staging migration; no production cutover.

## Layout order

1. Dashboard, Proyek, Stok Kavling
2. PSJB / UTJ, BI Checking, Pemberkasan, Proses Bank, TTD PPJB, Akad, BAST (badges)
3. Semua Booking, Konflik Data (gate+pilot visibility as today), Rekonsiliasi, Riwayat Impor

## Build choices (my calls)

- `Project::isOperationalStaging()` plus a `Project::stagingPilotCodes()` helper
  centralize the repeated `laravel + pilot code` check for new code only.
- One `StageQueue` service owns: `psjbQueue()`, `queueCounts()`, `blockReason()`.
- One `BuildsStageHistory` controller concern normalizes each stage's events into a
  single shape rendered by a shared `partials/stage-history.blade.php`.
- A `StageBadgeComposer` view composer feeds badge counts to the layout.
- Nav labels: "Semua Booking", "Konflik Data", "Riwayat Impor" per the requested list;
  page content of the conflict module keeps its existing "Konflik Impor" wording.
