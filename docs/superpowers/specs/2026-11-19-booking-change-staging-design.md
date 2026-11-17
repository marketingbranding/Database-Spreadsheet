# Ganti Nama and Pindah Kavling staging design

## Confirmed rules

- Only `ACTIVE` bookings in the two Laravel-managed pilot projects are eligible.
- The source must not have a PPJB, Akad, or BAST record. This record-level check is authoritative even when `current_stage` is stale.
- A change preserves the source booking and its complete history, then creates a new Laravel-owned successor at `PSJB`.
- `GANTI_NAMA` keeps the source lot and requires a different buyer.
- `PINDAH_KAVLING` keeps the source buyer and requires a different destination lot.
- The successor carries payment method, price, salesperson/coordinator, and PSJB UTJ metadata. The original UTJ reference is recorded as a carried-forward reference and is not counted as a second payment.
- BI, Pemberkasan, bank submissions, PPJB, Akad, and BAST never move to the successor.
- A transfer requires explicit confirmation when the destination price differs; the confirmed destination price becomes the successor price.
- Source and destination bookings/lots are locked in one transaction. A name change keeps allocation continuous. A transfer creates the successor and releases the source only after the destination is reserved and no other valid claim remains.
- The request is idempotent by a unique key. Replays with the same payload return the original transition; a changed payload returns `409`.
- A transition record stores action type, effective date, reason, actor, source booking ID, successor booking ID, and a safe payload snapshot. Booking audits are written for both source and successor.

## Out of scope

Changes after PPJB, Akad, or BAST remain Sheets-managed. Historical correction tooling, scheduled imports, and production cutover remain outside this task.

## Open operational note

The source workbook contains historical change chains whose immediate-parent links can be ambiguous. Laravel writes require an explicit source booking and destination/buyer, so they do not infer a historical parent.
