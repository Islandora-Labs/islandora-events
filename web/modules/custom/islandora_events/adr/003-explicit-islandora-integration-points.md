# ADR 003: Use explicit Islandora integration points

## Status

Accepted

## Context

`islandora_events` replaces Islandora's legacy event orchestration path with a
Drupal-native pipeline built around `sm_ledger` and Symfony Messenger.

There were several ways to take over Islandora's behavior:

- suppress Islandora's hook implementations from another module via
  `hook_module_implements_alter()`
- decorate broad Islandora services and rely on hidden replacement behavior
- add explicit opt-in checks at the Islandora control points that decide
  whether work stays on the legacy path or is handed to `islandora_events`

The first two options reduce visible code changes in `islandora`, but they
also obscure the control flow. We wanted the takeover boundary to be obvious in
code review and safe by default when `islandora_events` is not enabled.

## Decision

We will use explicit integration points inside `islandora` rather than
implicit suppression tricks from `islandora_events`.

Specifically:

- Islandora hook entry points check whether an external orchestrator is active
- direct action execution hands derivative and indexing work to narrow
  `islandora_events` services
- if `islandora_events` is not enabled, Islandora continues to behave as it did
  before

## Reasoning

### Readability matters more than cleverness

Explicit control flow is easier to maintain than hook-order magic or broad
service decoration that silently changes behavior when another module happens
to be enabled.

### The compatibility boundary should be easy to trace

Reviewers should be able to answer:

- where Islandora decides whether the new orchestrator is active
- where derivative execution is handed off
- what happens when `islandora_events` is absent

Explicit checks answer those questions directly.

### Backwards compatibility remains obvious

The default behavior is still the historical Islandora path. The new behavior
is an intentional opt-in at known integration points, not a side effect of
Drupal module ordering.

## Consequences

- `islandora` contains a small, intentional compatibility surface for external
  orchestration
- `islandora_events` depends on those explicit seams rather than hidden Drupal
  behavior
- future upstream extension points can replace these touchpoints if Islandora
  later formalizes them
