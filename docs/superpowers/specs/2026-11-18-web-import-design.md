# Web Import Design

## Intent

Expose safe project import through web app. User chooses accessible project, runs dry-run, reviews report, then dispatches real import to queue without blocking HTTP request.

## Approaches

1. Synchronous web import: smallest code, unsafe for large spreadsheets because request can timeout.
2. Dry-run plus synchronous apply: safer preview, still timeout-prone.
3. Dry-run plus queued apply: recommended. Keeps existing `ProjectImporter`, avoids request timeout, exposes batch status and retry-safe idempotent execution.

## Goals

- HQ can import every accessible project.
- Branch admin can import only assigned projects.
- Dry-run happens before apply.
- Apply runs through queue job.
- User sees queued, running, completed, or failed status.
- Existing importer, data contract, conflict handling, and batch detail remain canonical.
- No duplicate import button submissions.

## Non-goals

- No new importer implementation.
- No spreadsheet editing from web.
- No automatic conflict resolution.
- No changes to metrics or dashboard filtering.
- No queue driver migration.

## Interface

- `GET /imports`: existing list plus import form and recent status.
- `POST /imports/dry-run`: validate accessible project, run `ProjectImporter` with `dryRun=true`, return redirect with report payload/session flash.
- `POST /imports/apply`: accept project and validated dry-run token, create queued batch/job, redirect to batch detail.
- Existing `GET /imports/{batch}` displays status and report.

Dry-run report stores a short-lived signed/cache payload, not operational rows. Apply requires matching user, project, source, and report checksum. Expired or reused token rejects with validation error.

## Queue

`RunProjectImport` job receives project ID and dry-run checksum. Job rechecks project, invokes `ProjectImporter`, and records final ImportBatch status. Queue retries use existing idempotency behavior. Job failure marks batch failed and stores safe error text; no secrets or credentials in UI.

## Status

Import batch statuses: existing values preserved; add only missing queued/running/failed handling if schema supports it. Detail page refresh link is sufficient; no polling JavaScript required for first version.

## Security

- Authorization uses `canAccessProject` at every web endpoint and job dispatch boundary.
- CSRF protection on POST routes.
- Validate project ID and dry-run token server-side.
- Never accept spreadsheet ID from browser; load it from Project.

## Acceptance

- Unauthorized project returns 403/validation failure.
- Dry-run shows counts and issues without operational changes.
- Apply queues job and redirects to batch detail.
- Duplicate apply cannot create a second batch from same token.
- Job completes and existing import detail displays results.
- Failed job shows failed status and safe message.
- Existing CLI importer tests remain green.
