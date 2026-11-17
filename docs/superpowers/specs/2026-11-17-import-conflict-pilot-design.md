# Project-scoped Import Conflict Pilot

## Status

Proposed for `PRJ-JPR-KWS` and `PRJ-MGL-KLG` staging only.

## Intent

Replace the current one-click `ImportIssue.resolved_at` behavior with an
auditable proposal and approval workflow for import conflicts. Branch admins
must be able to continue normal work on unaffected lots while unresolved
allocation disputes block new bookings only on their affected lots.

The module must preserve source evidence, exact Laravel booking cycles, signed
document links, prior HQ decisions, and Laravel-owned corrections across
reimports.

## Scope

### Supported resolution cases

1. Competing active claims, initially including Kuwasen M22.
2. Unlinked BI rows.
3. Stock disagreements, initially including Kuwasen L02 and M20.
4. Bank-reject issues are displayed but resolved through the existing booking
   decision workflow.
5. PPJB, Akad, and BAST conflicts accept evidence and HQ escalation only.

### Non-goals

- Production cutover or scheduled imports.
- Editing source Sheets.
- Automatically changing signed PPJB, Akad, or BAST records or their links.
- A general-purpose historical data editor.
- Allowing branch admins to approve their own proposals.

## Selected approach

Use a dedicated conflict aggregate rather than adding workflow state directly
to `import_issues`.

`ImportIssue` remains evidence from one import batch. A durable conflict record
tracks stable source identity, source fingerprint, exact booking IDs, affected
lots, status, and the active HQ decision. Separate proposals and events preserve
the full approval and execution history.

### Alternatives considered

1. **Extend `import_issues`.** Smaller, but every import currently closes open
   issues and inserts new rows. Proposal history and stable identity would be
   fragile.
2. **Dedicated conflict aggregate — selected.** More schema and service code,
   but import evidence stays immutable, duplicate proposals can be constrained,
   and prior decisions survive materially changed source rows.

## Trust boundaries and authorization

- Google Sheets values, evidence text, proposal input, IDs in URLs, and
  idempotency keys are untrusted.
- Every controller verifies project access and pilot project code.
- Branch admins may list and view conflicts for assigned projects and submit
  proposals. Evidence in the pilot is a required bounded text statement plus
  an optional source reference; uploading files is explicitly out of scope.
- HQ users may list and view all pilot conflicts, approve or reject proposals,
  and confirm escalation-only outcomes.
- Only the conflict service may close a conflict.
- Approval revalidates project, source fingerprint, exact booking IDs, lot
  claims, signed-document state, and proposal status under database locks.
- Evidence is text/reference metadata only in this pilot; no file-upload
  surface is added.

## Data model

### `import_conflicts`

- `id`
- `project_id`
- `current_import_issue_id` nullable
- `stable_source_identity`: normalized project, issue code, source tab, and
  source key; when a source key is absent, use a deterministic identity from
  tab plus normalized business locator stored in context.
- `case_type`: `COMPETING_CLAIMS`, `UNLINKED_BI`, `STOCK_DISAGREEMENT`,
  `BANK_REJECT`, or `SIGNED_DOCUMENT`
- `status`: `OPEN`, `PROPOSED`, `ESCALATED`, or `RESOLVED`
- `source_tab`, `source_row`, `source_key`
- `source_fingerprint`: SHA-256 of a canonical allowlisted source snapshot
- `reviewed_source_fingerprint` nullable
- `supersedes_conflict_id` nullable, linking a materially changed source review
  to the prior decision
- `source_value` JSON
- `laravel_value` JSON
- `inventory_impact` JSON
- `affected_lot_ids` JSON containing exact lot IDs
- timestamps; `resolved_at`, `resolved_by`, and `resolution_type` nullable

Unique current identity: project plus stable source identity plus source
fingerprint. A material fingerprint change creates/reopens a new review while
prior conflicts and events remain immutable.

### `import_conflict_bookings`

Join table containing `import_conflict_id`, exact `booking_id`, and role
(`CLAIM`, `CURRENT_HOLDER`, `LINK_TARGET`, or `DOCUMENT_OWNER`). This prevents
resolution against a different cycle sharing the same legacy transaction ID.

### `import_conflict_proposals`

- conflict and project IDs
- proposer ID
- action:
  - `CONFIRM_LARAVEL`
  - `CORRECT_LARAVEL`
  - `LINK_BI`
  - `SELECT_CURRENT_CLAIM`
  - `ROUTE_BOOKING_DECISION`
  - `ESCALATE_SIGNED_DOCUMENT`
- reason, evidence, source reference
- proposed values JSON containing only action-specific allowlisted fields
- idempotency key and request hash
- status: `PENDING`, `APPROVED`, `REJECTED`, `APPLIED`, or `FAILED`
- approver, approval reason, approved/rejected/applied timestamps
- before and after JSON

A partial unique index/portable equivalent permits only one pending proposal per
conflict. A unique idempotency key plus request hash returns the same proposal
for an identical retry and returns HTTP 409 for changed payload reuse.

### `import_conflict_events`

Append-only event log containing conflict, proposal, actor, event type, reason,
source reference, source fingerprint, before, after, and timestamp.

Applied booking mutations also create a `BookingAudit` event with action
`import_conflict_resolved`; conflict-only decisions create an event without
inventing a booking mutation.

## Conflict projection from imports

After the importer derives issues, a projector maps supported `ImportIssue`
rows into durable conflicts. A stock detector also compares normalized
`hq_report_export` lot positions with availability derived from Laravel claims;
this is required for L02/M20 because those disagreements are reconciliation
differences rather than existing `ImportIssue` codes.

1. Canonicalize the source snapshot with explicit field names rather than raw
   positional arrays.
2. Compute stable source identity and source fingerprint.
3. If an unresolved conflict with the fingerprint exists, attach the latest
   issue evidence without creating a duplicate.
4. If a `CONFIRM_LARAVEL` decision reviewed the same fingerprint, preserve the
   Laravel value, keep the conflict resolved, attach the new import evidence,
   and mark that batch's regenerated `ImportIssue` resolved so dashboard open
   counts do not contradict the durable decision.
5. If the source fingerprint materially changes, create a new open review
   linked to the prior conflict. The prior decision and events remain intact.
6. For an applied Laravel correction, existing importer ownership guards
   continue preserving Laravel-owned records. An unchanged source row does not
   reopen the conflict; a materially changed fingerprint does.

Projection and issue persistence run in the import transaction. Dry-runs build
the projection for reporting but persist neither issues nor conflicts.

Material fields are case-specific:

- claims: transaction/PSJB identity, lot identity, buyer identity, status, and
  chain references;
- BI: BI identity, transaction reference, buyer/lot locator, date, result, and
  decision;
- stock: lot identity plus the source values that assert availability or
  allocation;
- signed documents: signed record identity, booking identity, number/date, and
  linkage fields.

Formatting-only differences are normalized and do not change the fingerprint.

## Affected-lot booking block

`PsjbWorkflow::availableLots()` excludes lots referenced by unresolved
allocation conflicts. `PsjbWorkflow::create()` repeats the same check after
locking the project and lot so a stale form cannot bypass it.

Allocation-blocking cases are `COMPETING_CLAIMS` and `STOCK_DISAGREEMENT`.
Unlinked BI, bank reject, and signed-document escalation do not block unrelated
booking creation unless their conflict explicitly identifies an allocation
dispute.

The block is released only after:

1. the approved resolution succeeds, or HQ confirms Laravel as authoritative;
2. no other unresolved allocation conflict references the lot.

Normal claim rules remain authoritative after release. A lot with an active,
completed, or needs-review claim remains unavailable.

## Proposal and approval workflows

### Confirm Laravel as authoritative

HQ approval locks the conflict and affected lots, verifies the source
fingerprint is unchanged, snapshots current Laravel claims, and records the
reviewed fingerprint, evidence, reason, approver, and before/after values. No
booking or lot field changes. The conflict closes only if no validation changed
during approval.

### Correct Laravel

HQ approval locks the conflict, project, affected lots, and exact bookings. It
verifies current claims and source fingerprint, then invokes an action-specific
method on `PsjbWorkflow` where an existing workflow operation fits.

For competing claims, the approved action names the exact current booking ID
and an explicit disposition for every other valid claim. The service uses the
existing withdraw/cancel behavior where applicable, then verifies exactly one
current holder at most.

For stock disagreements, availability is never assigned directly. The approved
booking/claim correction is applied and availability is derived from resulting
records.

If no existing workflow method expresses the approved correction safely, a
narrow conflict-resolution method is added to `PsjbWorkflow`; it receives exact
booking IDs, locks and revalidates the same claim set, applies only allowlisted
status/current-holder changes, and records the normal booking audit snapshot.
Controllers never update booking allocation fields directly.

The mutation, `BookingAudit`, conflict event, proposal update, and conflict
closure commit in one database transaction. Failure leaves the conflict open
and records no partial operational mutation.

### Unlinked BI

The proposal names one exact booking ID in the same project. Approval verifies
the source locator, booking cycle, absence of a conflicting Laravel-owned BI
record, and signed-document constraints before linking/importing through the BI
workflow. Ambiguous rows remain open.

### Bank reject

The conflict page links to the existing booking decision actions. The conflict
projector observes the resulting approved booking state on re-evaluation and
closes only when the decision satisfies the issue. The conflict module does not
duplicate withdraw/cancel behavior.

### Signed document conflict

Branch admins may submit evidence and escalate. HQ may acknowledge/escalate or
confirm that existing Laravel links are correct. `CORRECT_LARAVEL`, BI linking,
claim changes, and document relinking are rejected when PPJB, Akad, or BAST
exists. No signed record or existing link is mutated.

## Web interface

Project-scoped routes:

- `GET /projects/{project}/import-conflicts`
- `GET /projects/{project}/import-conflicts/{conflict}`
- `POST /projects/{project}/import-conflicts/{conflict}/proposals`
- `POST /projects/{project}/import-conflicts/{conflict}/proposals/{proposal}/approve`
- `POST /projects/{project}/import-conflicts/{conflict}/proposals/{proposal}/reject`

The list shows severity, case, lot, source location, status, inventory impact,
and pending owner/action. The detail page shows:

- exact lot and exact booking IDs/cycles;
- source tab, row, key, fingerprint, and source reference;
- named Sheets and Laravel values;
- current claims and inventory impact;
- signed-document warning;
- proposal/evidence form for branch admins;
- approval/rejection controls for HQ;
- append-only event history.

Bank-reject records display a route to the existing booking page/workflow.

## Validation and error semantics

- 403: role or project access denied.
- 404: nested resource does not belong to the route project/conflict.
- 409: duplicate pending proposal, reused idempotency key with different
  payload, changed source fingerprint, stale claims, or competing approval.
- 422: invalid action-specific proposal data.

All state-changing requests use CSRF protection and server-side allowlists.
Evidence and reason are length-limited and rendered through Blade escaping.

## Tests

### Domain and feature tests

- branch admin can access only assigned pilot projects;
- HQ can review both pilot projects;
- nested-resource and cross-project ID substitution is rejected;
- duplicate identical proposal retries are idempotent;
- changed payload with the same key returns 409;
- only one pending proposal exists per conflict;
- booking-cycle isolation uses exact booking IDs;
- unrelated lots remain available and writable;
- affected lot is absent from available lots and rejected at create-time;
- approved correction leaves at most one current holder;
- active holder remains unavailable after conflict closure;
- failure rolls back booking, audit, proposal, event, and conflict updates;
- unchanged reimport preserves `CONFIRM_LARAVEL` and applied corrections;
- materially changed source fields create a new open review linked to history;
- unlinked BI links only to the approved exact booking;
- bank rejects route to existing decision behavior;
- PPJB/Akad/BAST conflicts are escalation-only and preserve links;
- source, Laravel, inventory, actor, approver, reason, reference, and
  before/after values are retained.

### Staging rehearsal

1. Seed/project current open issues into conflict projections without changing
   bookings or Sheets.
2. Verify M22 is blocked while an unaffected available lot remains writable in
   a rolled-back transaction.
3. Submit a branch proposal and approve it as HQ against staging fixtures.
4. Reimport unchanged fixtures and verify the reviewed conflict remains closed.
5. Change one material source field in a copied fixture and verify a new open
   review appears.
6. Rehearse L02/M20 `CONFIRM_LARAVEL` and one fixture-only
   `CORRECT_LARAVEL`, each inside rollback-safe test data.
7. Verify PPJB/Akad/BAST IDs and links before and after escalation.

The pilot UI is enabled only in staging with operational writes enabled and only
for the two pilot project codes. Production cutover and scheduled imports remain
out of scope.

## Rollback

- Disable the pilot routes through the staging/pilot gate.
- Do not delete conflict, proposal, or event history.
- Existing booking and signed-document tables remain compatible.
- Applied corrections are reversed only by a new approved domain action, never
  by deleting audit records or restoring raw database snapshots.
