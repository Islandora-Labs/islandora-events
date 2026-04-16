## Introduction

`islandora_events_backfill` is the optional derivative backfill companion for
`islandora_events`.

Enable it when you want:

- periodic or manual scans for missing derivatives
- the `islandora_backfill` SM transport
- a recurring scheduler transport via `sm_scheduler`
- Drush scan commands such as `drush islandora-events:scan-missing`

It depends on `islandora_events` for event-record persistence and derivative
queueing, and therefore indirectly on `sm_ledger` for durable operator state
and `sm_workers` for worker command conventions. It keeps scanner/backfill
behavior out of the base module so normal event processing can run without
enabling backfill.

## Enable

```bash
drush en islandora_events_backfill -y
drush updb -y && drush cr
```

Backfill is for missed work, not reindexing. When an index or derivative event
already exists in `event_record`, prefer replaying the stored ledger rows.
Backfill should generate new ledger rows only for content that was never
captured in the first place.

## Commands

- `drush islandora-events:scan-missing`
- `drush islandora-events:scan-missing --type=thumbnails`
- `drush islandora-events:scan-status`
- `drush islandora-events:scan-types`
- `drush islandora-events:scan-scheduler-info`
- `drush sm:consume islandora_backfill --stop-when-empty`
- `drush sm:consume scheduler_islandora_events_backfill`

## Scanner Logic

The bundled scanners resolve Islandora media-use terms by
`field_external_uri_uri` rather than hardcoded term IDs, so they work across
sites where taxonomy term IDs differ.

## Scheduling

This module uses `sm_scheduler` to emit recurring `BackfillScanMessage`
messages, one per configured scanner plugin. The schedule provider transport is
named `scheduler_islandora_events_backfill`.

That scheduler transport generates scan messages; those scan messages are then
routed to the `islandora_backfill` transport for asynchronous execution.

Canonical worker commands:

- `drush sm:consume scheduler_islandora_events_backfill --time-limit=3600`
- `drush sm:consume islandora_backfill --time-limit=3600`
