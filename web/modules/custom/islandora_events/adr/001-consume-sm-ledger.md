# ADR 001: Consume `sm_ledger` as the durable operator ledger

## Status

Accepted

## Context

`islandora_events` needs a durable record of work that remains meaningful after
the transport queue has been drained and after worker logs have rolled over.
Operators need to answer:

- what work was queued
- which entity and user it came from
- whether it is still processable
- how many retries occurred
- whether it completed, failed, or now needs manual intervention

That record is not the same thing as the transport queue. Symfony Messenger
owns delivery, retry scheduling, and worker execution. `islandora_events`
needs an application-facing lifecycle projection that survives transport swaps
and remains usable from Drupal UI and Drush tooling.

We could have embedded that lifecycle model directly in `islandora_events`, but
doing so would have mixed a reusable Drupal/Messenger concern into an
Islandora-specific orchestration module.

## Decision

`islandora_events` will consume `sm_ledger` as a separate dependency rather
than owning its own private ledger implementation.

`sm_ledger` is the durable operator ledger. `islandora_events` is the
Islandora-specific producer and consumer layer that writes to and reads from
that ledger.

## Reasoning

### The ledger is a separate concern from Islandora orchestration

The durable lifecycle model is useful beyond Islandora. A Drupal site may want
the same operator projection for Messenger-backed work that has nothing to do
with Fedora, derivatives, or indexing.

### The ledger must outlive transport details

The transport backend may change over time. Queue tables, retry policy
mechanics, and worker deployment are runtime choices. The operator-facing
record should remain stable regardless of whether the transport is SQL-backed
today or something else later.

### Reuse is cleaner than private duplication

Pulling the ledger into its own module gives us a narrower boundary:

- `sm_ledger` owns event records, projection updates, retention, and archive
- `sm_ledger` owns shared operator workflows such as requeue and run-now coordination through tagged handlers
- `sm_ledger` owns shared SQL-backed dispatch coordination and aggregate ledger analytics
- `sm_ledger` owns the time-bounded `dedupe_key` contract used to suppress
  duplicate work by business key within the configured dedupe window
- `islandora_events` owns dedupe keys, message types, tagged ledger handlers, and Islandora
  integration points

That is easier to explain, test, and publish than one large module that mixes
all of those concerns together.

## Consequences

- `islandora_events` depends on `sm_ledger` for durable lifecycle state
- producer-side locks may suppress enqueue races, but they do not replace the
  ledger's configurable dedupe-window semantics
- transport and retry internals remain subordinate to the ledger rather than
  redefining it
- other codebases can adopt `sm_ledger` without adopting Islandora-specific
  orchestration
- changes to ledger semantics should happen in `sm_ledger`, not in
  `islandora_events`
