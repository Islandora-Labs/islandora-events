# Islandora Events OTel

Optional trace-context integration for `islandora_events`.

This module does two things:

- captures or creates W3C trace context on the request path
- stamps Messenger envelopes so that context survives async execution

It also enriches ledger metadata with useful trace identifiers so operators can
correlate one ledger row with external telemetry systems.

This module extends the shared `sm_ledger` operator surface; it does not alter
`sm_workers` orchestration or transport ownership.

This is not a full OpenTelemetry SDK/exporter module. It is the local
propagation and ledger-correlation layer.

Enable it with:

```bash
drush en islandora_events_otel -y
drush updb -y && drush cr
```

After enabling it, new ledger rows include trace metadata and worker envelopes
carry propagated trace context across async hops.
