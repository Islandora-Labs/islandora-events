# ADR 002: Use a Drupal-owned Messenger runtime for transport and delivery

## Status

Accepted

## Context

The legacy Islandora stack routes work through ActiveMQ and Alpaca. That model
pushes delivery, orchestration, and much of the operational picture outside
Drupal. Recovering reliable success and failure state in that design requires
middleware callbacks or postback code on infrastructure we are intentionally
moving away from.

Once the long-term direction became "Drupal owns orchestration", the transport
question became narrower:

- should work be delivered through a Drupal-native runtime
- should that runtime be Symfony Messenger via `drupal/sm`
- how should retries, deduplication, and worker concurrency be expressed

`islandora_events` already has a domain ledger in `sm_ledger`. It does not need
another framework to become the source of truth for business-level job state.

## Decision

`islandora_events` will use Symfony Messenger via `drupal/sm` as its transport
and worker runtime.

This decision includes the following runtime model:

- Drupal-owned workers replace ActiveMQ and Alpaca for supported workflows
- SQL-backed Messenger transports are the default deployment backend
- work is split across transport-specific queues and workers
- delivery is at-least-once, with handler idempotency as the correctness
  boundary
- deduplication is expressed at the producer and ledger layers by business key,
  not by relying on Messenger lock middleware alone
- if SQL transport becomes the limiting factor, the transport backend may
  change later without changing the ledger model

## Reasoning

### Messenger is the right abstraction level

Messenger gives us messages, routing, transports, retry integration, and worker
lifecycle events. That is enough. We do not need a queue framework that also
wants to own the business job record, because `sm_ledger` already owns that.

### Drupal stays the source of truth

Dropping ActiveMQ and Alpaca is not just a deployment simplification. It keeps
the authoritative view of what happened inside Drupal:

- ledger rows are written in Drupal
- handlers run in Drupal workers
- retries and final status are visible from Drupal

That is the architectural benefit we were after.

### Separate transports create useful operational boundaries

Derivative work, Fedora indexing, Blazegraph indexing, and optional scheduler
flows do not have the same throughput or failure profile. Separate transports
and worker pools let us isolate failures and scale by workload instead of
treating everything as one undifferentiated queue.

### At-least-once plus idempotency is the right guarantee here

Exactly-once delivery is not the target. The target is safe, observable,
retriable work:

- producers write ledger state and queue work together for the main flows
- producers use `sm_ledger.dispatch` for the shared SQL-backed `record + dispatch` transaction wrapper
- consumers treat duplicate delivery as a no-op if the ledger record is no
  longer processable
- the dedupe boundary is the ledger `dedupe_key`, which is checked before
  enqueue within the configured dedupe TTL window

This is intentionally different from Symfony Messenger's `DeduplicateStamp`.
That middleware is lock-based and temporary. Our contract is business-key
dedupe that is queryable from Drupal, configurable by TTL, and visible in
operator workflows through `sm_ledger`.

The enqueue lock remains only a race guard around producer concurrency. It is
released when the producer finishes dispatch coordination; it is not held until
a worker receives the message.

That keeps the runtime understandable and operationally tractable.

## Consequences

- ActiveMQ and Alpaca are not architectural dependencies of `islandora_events`
- `drupal/sm` is a required runtime dependency
- dedupe keys and handler idempotency are part of the public correctness model
- worker deployment is transport-specific and measurement-driven
- direct Fedora and Blazegraph indexing can live in dedicated target submodules
  without reintroducing external queue middleware
- a future transport swap is an infrastructure change, not a business-model
  rewrite
