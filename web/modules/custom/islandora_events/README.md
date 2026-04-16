# Islandora Events

Islandora is a Drupal-based digital repository framework. Historically, its
derivative generation and indexing pipeline ran outside Drupal: entity events
were published to ActiveMQ, consumed by Alpaca, and forwarded to downstream
services that then had to communicate status back to Drupal.

`islandora_events` replaces that stack with a Drupal-native event model built
on Symfony Messenger (`drupal/sm`) plus the reusable
[`sm_ledger`](modules/sm_ledger/README.md) and
[`sm_workers`](modules/sm_workers/README.md) modules. Derivative and indexing
jobs are dispatched onto Drupal-owned transports, consumed by long-running
workers, and tracked end-to-end in ledger records. Direct Fedora/fcrepo and
Blazegraph indexing now live in dedicated submodules:
[`islandora_events_fcrepo`](modules/islandora_events_fcrepo/README.md) and
[`islandora_events_blazegraph`](modules/islandora_events_blazegraph/README.md).
The base module stays generic and owns the common queueing, worker, and target
extension model.

The practical value is straightforward: Alpaca, broker-side routing, and
out-of-band status callbacks are replaced with Drupal-owned queueing, Drupal
workers, and Drupal-visible operator state. Sites keep the same repository
workflows, but failure handling, retries, and deployment behavior are now part
of the normal Drupal runtime instead of a separate Java integration stack.

## Event flow

```text
Islandora event -> ledger record -> Messenger transport -> worker -> derivative or index target
```

## Transports

| Transport | Purpose |
|---|---|
| `islandora_derivatives` | Derivative generation |
| `islandora_index_fedora` | Fedora indexing |
| `islandora_index_blazegraph` | Blazegraph/Triplestore indexing |
| `islandora_index_custom` | Custom index targets that do not define a dedicated transport |

Each transport runs an independent worker, allowing separate scaling and failure isolation.

## Requirements

- Drupal 10 or 11
- [`drupal/sm`](https://www.drupal.org/project/sm)

## Installation

```
composer install
drush en islandora_events -y
drush en islandora_events_blazegraph -y
drush en islandora_events_fcrepo -y
drush updb -y && drush cr
```

This enables `islandora_events`, the direct Blazegraph and Fedora indexing
submodules, and the shared ledger/worker dependencies. If you add either
indexing submodule to an existing site, run `composer install` or
`composer update` before enabling the module so Drupal can load the embedded
indexer libraries.

## Running workers

```bash
drush sm:consume islandora_derivatives --time-limit=3600
drush sm:consume islandora_index_fedora --time-limit=3600
drush sm:consume islandora_index_blazegraph --time-limit=3600
drush sm:consume islandora_index_custom --time-limit=3600
drush islandora-events:capacity-report --window-minutes=15
```

Workers are long-running. Use `--stop-when-empty` to drain and exit. Do not use `--keepalive` — the SQL transport does not implement Messenger keepalives.

In a containerized stack, `s6` can manage one worker per transport in the
Drupal container. Use `drush islandora-events:capacity-report` to decide when
that default is sufficient and when workers should move into separate
containers or hosts. That command now reads from the shared
`sm_ledger.capacity_report` service rather than an Islandora-only report path.

To inspect the canonical worker commands contributed by this module set, use:

```bash
drush sm-workers:list
drush sm-workers:operations islandora_events.derivatives
```

## Admin UI

- `/admin/config/system/sm-ledger` — ledger records across Messenger-backed work
- `/admin/config/system/sm-ledger/settings` — ledger retention, recovery, and dispatch tuning
- `/admin/reports/sm-ledger` — ledger report page
- `/node/{node}/events` — per-node event history
- `/admin/config/services/islandora-events/settings` — settings
- `/admin/config/services/sm-workers/settings` — worker runtime and circuit-breaker settings
- `/admin/config/services/sm-workers/circuit-breakers` — circuit-breaker state and manual controls

## Job lifecycle

```
queued → in_progress → completed
queued → in_progress → retry_due   (automatic retry)
queued → in_progress → failed      (requires manual intervention)
```

Retry metadata (`retry_count`, `next_attempt_at`, last error) is synced live from Messenger worker events.

## Ledger vs Logs

Use the ledger first to answer:

- what happened
- which object it belongs to
- whether it is queued, running, completed, failed, or abandoned
- how many retries happened

Use worker logs to answer:

- why the worker failed
- which downstream service timed out or rejected the request
- what exception or stack trace actually occurred

The ledger is the operator-facing system of record. Logs are the diagnostic layer.

## Recovery

For replaying stored derivative payloads directly from `event_record` without Messenger:

```bash
drush islandora-events:process-derivatives --limit=100
drush islandora-events:process-derivatives --queue=islandora-connector-houdini --limit=10
```

For reindexing idempotent index targets from the ledger of stored Islandora
events:

```bash
drush islandora-events:replay-index --status=completed,failed,abandoned --limit=100
drush islandora-events:replay-index --target=fedora --entity-type=node --entity-id=123
```

Use ledger replay to re-process events that were already captured. Use backfill
to discover content that never generated a ledger record in the first place.

## Derivative execution modes

Each queue can be configured in `derivative_runners` to use one of:

- `execution_mode: http` — calls a remote microservice endpoint
- `execution_mode: command` — privileged-only, configured in `settings.php`, and limited to allowlisted local binaries; when `config_path` is set it uses the config-backed `scyllaridae` CLI pattern with a mounted config

Known connectors default to config-backed `command` mode using configs mounted at `/opt/scyllaridae/<service>/scyllaridae.yml`. To use those local runners, set command approval in `settings.php` and allowlist `/usr/bin/scyllaridae`. Untrusted derivative args are parsed and validated with the same safe-character model Scyllaridae uses unless code explicitly opts into insecure args.

Example `settings.php`:

```php
$settings['islandora_events_derivative_command'] = [
  'enabled' => TRUE,
  'allowed_binaries' => [
    '/usr/bin/scyllaridae',
  ],
  'allow_insecure_args' => FALSE,
];
```

HTTP endpoints (e.g. `http://houdini:8080/`) should be overridden through Drupal
config or `settings.php` config overrides, not environment-variable lookups in
PHP service classes.

Complete example for adding a new HTTP derivative queue:

1. Configure the runner:

```yaml
derivative_runners:
  islandora-connector-myservice:
    execution_mode: http
    endpoint: 'http://myservice:8080/'
    timeout: 300
```

2. Ensure the Islandora action uses the same queue name:

```yaml
queue: islandora-connector-myservice
event: Generate Derivative
```

3. Run the derivative worker:

```bash
drush sm:consume islandora_derivatives --time-limit=3600
```

4. Verify the new queue appears in stored derivative transport metadata and in
   the SM Workers circuit breaker UI after first use.

Circuit breakers protect downstream HTTP integrations from repeated failure.
Breakers are per endpoint and transition through `closed`, `open`, and
`manual_open` states. Operators can inspect and reset them from
`/admin/config/services/sm-workers/circuit-breakers`. Command-mode
execution is not subject to circuit breakers. When a breaker is open, worker
intake is now paused briefly before the failing work is released so a broken
dependency does not cause tight retry churn across the whole transport.

## Extension points

Prefer explicit services over hook-order tricks for behavior changes.

- `index_targets` is the primary indexing configuration model for target services and endpoints
- add a tagged `islandora_events.derivative_runner_defaults` service to contribute queue defaults without replacing other providers
- add a tagged `islandora_events.index_payload_metadata` service when one target needs extra stored payload metadata without polluting the base payload builder
- add a tagged `sm_workers.execution_strategy` service to support a new `execution_mode` without editing the core runner coordinator; `sm_workers` owns the dispatch through its execution manager and strategy `supports($mode)` checks
- add a tagged `islandora_events.index_target` service to introduce a new target; custom targets are dispatched onto the generic `islandora_index_custom` transport unless you also add a dedicated message subclass and SM routing entry
- use a submodule when a target needs service-specific runtime logic, as `islandora_events_fcrepo` and `islandora_events_blazegraph` now do for direct repository indexing
- replace `Drupal\islandora_events\Service\DerivativeCommandPolicyInterface` if you need a stricter code-defined command policy
- keep request-path orchestration explicit; do not reintroduce implicit hook suppression for transport behavior

## Submodules

- `islandora_events_fcrepo` — direct Fedora/fcrepo indexing with embedded Milliner-style logic and a Fedora-specific replay command
- `islandora_events_blazegraph` — direct Blazegraph/SPARQL indexing with embedded triplestore update logic and a Blazegraph-specific replay command
- `islandora_events_backfill` — optional scheduler-driven derivative backfill scanning
- `islandora_events_mergepdf` — optional scheduler-driven mergepdf reconciliation
- `islandora_events_metrics` — optional Prometheus-style metrics endpoint
- `islandora_events_otel` — optional trace-context enrichment for ledger records
  - includes Messenger envelope trace-context stamping for end-to-end correlation

## Delivery semantics

This system provides **at-least-once delivery**. A message may be delivered more
than once (worker crash mid-flight, retry while a slow handler runs). Every
message handler guards against duplicate delivery by checking whether the
associated ledger record is still in a processable state before doing any work,
and worker lifecycle projection is ignored unless the current process owns the
execution lock for that message.

There are **no per-entity ordering guarantees**. Messages are pulled from the
queue in approximate insertion order. Retries may execute after newer jobs for
the same entity. Downstream services must tolerate out-of-order events.

The primary Islandora `drupal-sql` transports now set an explicit
`redeliver_timeout` of 14400 seconds. That does not provide true keepalives,
but it raises the visibility-timeout budget so legitimate long-running work is
less likely to be redelivered while still executing. Keep that value above your
real worst-case processing envelope: worker timeout, downstream write-back
time, bounded dispatch retry delay, and any configured open-breaker intake
pause.

For the main derivative and indexing producers, the ledger write and queue
write are wrapped in one SQL transaction through `sm_ledger.dispatch`. That
removes the previous ghost-ledger failure mode for these paths. The broader
architecture is still at-least-once, and a future non-SQL transport would
require a true outbox relay to preserve the same guarantee.

Manual retry and run-now flows now route through `sm_ledger.operator`.
`islandora_events` contributes tagged requeue and run-now handlers, but the
generic operator orchestration lives in the shared ledger module.

## Operations

The generic ledger model is documented in [`sm_ledger`](modules/sm_ledger/README.md).

Recommended deployment split:

- keep derivative, Fedora indexing, and Blazegraph indexing on separate workers
- keep scheduler and reconciliation flows on their own workers when optional
  submodules are enabled
- add workers per transport before considering deeper architectural changes

`sm_workers` defines the worker commands. `systemd`, `supervisor`, `s6`,
Kubernetes, or another process manager should run them.

Use this order when tuning throughput:

1. add derivative workers
2. tune Fedora indexing workers
3. tune Blazegraph indexing workers
4. keep scheduled reconciliation isolated from request-triggered work

Configure breaker thresholds in settings:

```yaml
circuit_breakers:
  failure_threshold: 5    # consecutive failures before opening
  cooldown_seconds: 300   # seconds before a probe is attempted after opening
  intake_pause_seconds: 5 # bounded worker pause while a breaker is open
execution:
  default_timeout_seconds: 300
  default_forward_auth: true
```

Lower `failure_threshold` for critical services with deterministic failures. Raise `cooldown_seconds` for services with slow restart cycles.
Set `default_timeout_seconds` and `default_forward_auth` in `sm_workers.settings`
when a site wants consistent execution defaults across worker-consuming modules.
Raise or lower `intake_pause_seconds` to balance outage protection against how
quickly workers should cycle through open-breaker work. Those values are also
editable from `/admin/config/services/sm-workers/settings`.

Ledger dispatch and native recovery tuning live in `sm_ledger.settings`:

```yaml
dispatch:
  deadlock_retry_attempts: 3
  deadlock_retry_delay_ms: 100
recovery:
  stale_claim_threshold_seconds: 3600
  heartbeat_interval_seconds: 30
```

`sm_ledger.dispatch` now retries recognized transient database deadlocks with a
small bounded backoff. Native derivative replay also refreshes a heartbeat on
claimed records so long-running command executions do not look stale as long as
their heartbeat interval stays comfortably below the stale-claim threshold.
Those values are also editable from `/admin/config/system/sm-ledger/settings`.

Ledger retention and archival are handled with:

```bash
drush sm-ledger:prune
drush sm-ledger:archive --path=/tmp/sm-ledger.ndjson
drush sm-ledger:archive --path=/tmp/sm-ledger.ndjson --apply-prune
```

## Message deduplication

The main Islandora queues now use `drupal-sql://` transports. Correctness does
not depend on transport-specific deduplication. Instead it comes from the
ledger and handler layers:

- enqueue-time distributed locks on the business key
- `findRecentByDedupeKey()` checks before a new ledger row is created
- `isQueuedForProcessing()` checks in handlers before work is executed

Deduplication keys are constructed by `IndexEventService` and
`DerivativeQueueService` for application-level idempotency within the configured
ledger dedupe TTL window. The failed
transport is for operator review, not for normal worker consumption.

Worker messages are keyed by the ledger `correlation_key`. The numeric
`event_record` ID is now optional compatibility data rather than the primary
identifier the async path depends on.

## Architecture decisions

See [`adr/`](adr/) for the full decision set:

| ADR | Decision |
|---|---|
| [000](adr/000-rfd-long-term-work-is-this-module.md) | Record the community RFD this codebase implements |
| [001](adr/001-consume-sm-ledger.md) | Consume `sm_ledger` as the durable operator ledger |
| [002](adr/002-drupal-owned-messenger-runtime.md) | Use a Drupal-owned Messenger runtime for transport and delivery |
| [003](adr/003-explicit-islandora-integration-points.md) | Use explicit Islandora integration points |
| [004](adr/004-downstream-execution-model.md) | Execute downstream work from Drupal workers through explicit strategies |
