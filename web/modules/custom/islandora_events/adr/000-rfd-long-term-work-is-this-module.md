# ADR 000: Treat the approved Drupal-module RFD as implemented by this module set

## Status

Accepted

## Context

[RFD 0000](https://github.com/Islandora-Labs/rfds/pull/1) proposed two paths
for Islandora event processing:

- a short-term Alpaca change to forward the full Drupal event in an
  `X-Islandora-Event` header
- a long-term replacement of ActiveMQ and Alpaca with a Drupal-native module

This repository is that long-term work. `islandora_events` is not an extension
of Alpaca; it is the Drupal-native replacement for derivative and indexing
orchestration.

## Decision

We record the approved long-term direction from RFD 0000 in this repository as:

- `islandora_events` replaces the legacy ActiveMQ + Alpaca path
- Symfony Messenger via `drupal/sm` is the transport/runtime layer
- `sm_ledger` is the durable domain ledger for operator-visible job state
- optional reconciliation work is built with `sm_scheduler`
- the short-term `X-Islandora-Event` header approach is historical context, not
  the direction of this module set

## Reasoning

This keeps the repository aligned with the accepted architecture already
captured elsewhere:

- [ADR 001](/workspace/adr/001-consume-sm-ledger.md) explains why this
  codebase consumes `sm_ledger` as a separate durable ledger
- [ADR 002](/workspace/adr/002-drupal-owned-messenger-runtime.md) defines the
  Drupal-owned Messenger runtime and delivery model
- [ADR 003](/workspace/adr/003-explicit-islandora-integration-points.md)
  defines how Islandora explicitly hands orchestration to this module
- [ADR 004](/workspace/adr/004-downstream-execution-model.md) defines how
  Drupal workers execute downstream derivative and indexing work

Together, these decisions describe one consistent direction: Drupal owns event
orchestration, durable job state, retries, and downstream execution, including
the direct Fedora and Blazegraph indexer submodules that replace the legacy
repository-indexing connectors.

## Consequences

- Future work in this repository should strengthen the Drupal-native pipeline
  rather than add new dependency on Alpaca or ActiveMQ
- References to the old header-based proposal should be treated as superseded
  by the module implemented here
- This ADR records what the approved RFD became in practice
