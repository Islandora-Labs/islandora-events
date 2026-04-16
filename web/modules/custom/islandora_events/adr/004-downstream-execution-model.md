# ADR 004: Execute downstream work from Drupal workers through explicit strategies

## Status

Accepted

## Context

Once Drupal owns event orchestration and transport delivery, it also becomes
the place where derivative and indexing work is executed.

That raises a second architectural question: how should Drupal workers call
downstream systems?

The module set has several distinct downstream patterns:

- derivative execution against HTTP microservices
- derivative execution against approved local commands
- indexing against Fedora and Blazegraph
- optional custom index targets

These integrations do not all share the same runtime behavior, but they do
share the same operational requirements:

- execution should happen from long-running Drupal workers, not the request
  path
- execution choice should be explicit and configurable
- failing downstream HTTP endpoints should not be hammered indefinitely
- adding a new execution strategy or target should not require replacing the
  whole orchestration model

## Decision

Downstream work will be executed from Drupal Messenger workers through explicit
execution strategies and target services.

This includes:

- derivative runners with named execution modes such as `http` and `command`
- index targets as tagged services behind the `index_targets` configuration
- service-specific repository indexers in dedicated submodules when a target
  needs embedded execution logic rather than a generic HTTP relay
- per-endpoint circuit breakers for downstream HTTP calls
- shared execution-strategy dispatch and runtime defaults in `sm_workers`
- command execution as an explicitly privileged path configured in
  `settings.php`

## Reasoning

### Downstream execution should be configurable, not hard-coded per queue

Workers need to decide how to execute a derivative or indexing action based on
clear configuration and service boundaries, not on hidden connector-specific
logic embedded throughout the codebase.

### HTTP and local command execution have different risk profiles

HTTP calls benefit from circuit breakers because repeated failures can quickly
fill the queue with doomed retries. Local command execution has a different
threat model, so it is gated by explicit administrator approval and binary
allowlisting instead.

### New downstream targets should plug into the architecture cleanly

The architecture should support adding:

- a new derivative execution strategy
- a new source of default runner config
- a new index target

without redefining the rest of the runtime.

## Consequences

- downstream execution remains inside Drupal workers, not in separate
  orchestration middleware
- `sm_workers` owns execution-strategy selection so consumer modules do not
  need their own dispatch loops
- HTTP integrations are guarded by circuit breakers
- local command execution is intentionally privileged and opt-in
- extension work should happen by adding strategy or target services, not by
  bypassing the worker model
