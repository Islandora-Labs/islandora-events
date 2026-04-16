## Islandora Events Merge PDF

Optional submodule that integrates mergepdf workflows with
`islandora_events`.

Features:

- Marks parent entities as pending when page/service-file changes occur.
- Uses `sm_scheduler` to emit a recurring reconciliation sweep.
- Routes mergepdf messages to the `islandora_mergepdf` deduplicating transport.
- Provides a handler that executes the configured Drupal action entity.
- Reuses the base module's `sm_ledger` and `sm_workers` integration model
  rather than introducing a separate worker runtime.

This keeps the `islandora_events` base module free of direct mergepdf
workflow dependencies.

Enable it with:

```bash
drush en islandora_events_mergepdf -y
drush updb -y && drush cr
```

Canonical worker commands:

- `drush sm:consume scheduler_islandora_events_mergepdf --time-limit=3600`
- `drush sm:consume islandora_mergepdf --time-limit=3600`
