<?php

declare(strict_types=1);

namespace Drupal\islandora_events_otel\Messenger\Middleware;

use Drupal\islandora_events_otel\Messenger\Stamp\TraceContextStamp;
use Drupal\islandora_events_otel\Service\TraceContextService;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Adds trace context to outgoing Messenger envelopes when absent.
 */
final class TraceContextMiddleware implements MiddlewareInterface {

  /**
   * Constructs the middleware.
   */
  public function __construct(
    private TraceContextService $traceContext,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function handle(Envelope $envelope, StackInterface $stack): Envelope {
    if (!$envelope->last(TraceContextStamp::class)) {
      $envelope = $envelope->with(new TraceContextStamp($this->traceContext->buildCurrentContext()));
    }

    return $stack->next()->handle($envelope, $stack);
  }

}
