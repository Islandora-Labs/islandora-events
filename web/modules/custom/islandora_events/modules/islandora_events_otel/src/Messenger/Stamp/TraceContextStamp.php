<?php

declare(strict_types=1);

namespace Drupal\islandora_events_otel\Messenger\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries W3C trace context on a Messenger envelope.
 */
final class TraceContextStamp implements StampInterface {

  /**
   * Constructs a trace context stamp.
   *
   * @param array<string, string> $context
   *   Trace context values.
   */
  public function __construct(
    private array $context,
  ) {}

  /**
   * Returns the trace context.
   *
   * @return array<string, string>
   *   Trace context values.
   */
  public function getContext(): array {
    return $this->context;
  }

}
