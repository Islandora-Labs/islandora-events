# Islandora Events fcrepo

`islandora_events_fcrepo` provides the Fedora/fcrepo indexing target for
`islandora_events`.

It replaces the old Milliner HTTP hop with Drupal-local indexing logic that
talks directly to Fedora using the same core behaviors Milliner implemented:

- pairtree path mapping from Drupal UUIDs
- create or update of RDF resources from Drupal JSON-LD
- create Fedora mementos for revision-backed Drupal updates
- media describedby resolution
- external file resource updates
- delete handling against Fedora resources

Enable this module when you want `index_targets.fedora` to be handled directly
inside Drupal instead of being POSTed to a remote Milliner service.

## Install and enable

Run Composer first so the embedded Fedora client libraries are available, then
enable the submodule and rebuild Drupal:

```bash
composer install
drush en islandora_events_fcrepo -y
drush updb -y && drush cr
```

## Configure

Set `index_targets.fedora` in the Islandora Events settings UI:

- `enabled: true`
- `endpoint: http://fcrepo:8080/fcrepo/rest`

## Run the worker

```bash
drush sm:consume islandora_index_fedora --time-limit=3600
```

Useful operator command:

```bash
drush islandora-events-fcrepo:index-record 123
```

That command replays one stored Fedora ledger record through the embedded
indexer logic and is useful for debugging or operator recovery.
