# Islandora Events Blazegraph

`islandora_events_blazegraph` provides the Blazegraph/triplestore indexing
target for `islandora_events`.

It replaces the old Alpaca triplestore hop with Drupal-local indexing logic
that talks directly to the SPARQL update endpoint:

- fetch Drupal JSON-LD at execution time with a fresh worker JWT
- normalize subject URLs from stored payload metadata
- issue `DELETE WHERE` plus `INSERT DATA` updates for upserts
- issue `DELETE WHERE` updates for deletes

Enable this module when you want `index_targets.blazegraph` to be handled
directly inside Drupal instead of being POSTed to a remote microservice.

## Install and enable

Run Composer first so the embedded RDF parser library is available, then enable
the submodule and rebuild Drupal:

```bash
composer install
drush en islandora_events_blazegraph -y
drush updb -y && drush cr
```

## Configure

Set `index_targets.blazegraph` in the Islandora Events settings UI:

- `enabled: true`
- `endpoint: http://blazegraph:8080/bigdata/namespace/islandora/sparql`
- `named_graph: https://example.org/graph/islandora` if your repository uses a
  non-default graph; leave blank for the default graph

## Run the worker

```bash
drush sm:consume islandora_index_blazegraph --time-limit=3600
```

Useful operator command:

```bash
drush islandora-events-blazegraph:index-record 123
```
