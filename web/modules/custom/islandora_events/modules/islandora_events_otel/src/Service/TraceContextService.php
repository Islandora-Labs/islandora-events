<?php

declare(strict_types=1);

namespace Drupal\islandora_events_otel\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Extracts and generates W3C trace context values.
 */
class TraceContextService {

  /**
   * Constructs a trace context service.
   */
  public function __construct(
    private RequestStack $requestStack,
  ) {}

  /**
   * Builds trace context from the current request or generates a new one.
   *
   * @return array<string, string>
   *   OTEL-style context values.
   */
  public function buildCurrentContext(): array {
    $request = $this->requestStack->getCurrentRequest();
    $incoming = $request instanceof Request
      ? $this->parseTraceparent((string) $request->headers->get('traceparent', ''))
      : NULL;

    $traceId = $incoming['trace_id'] ?? $this->generateTraceId();
    $parentSpanId = $incoming['span_id'] ?? '';
    $spanId = $this->generateSpanId();
    $traceFlags = $incoming['trace_flags'] ?? '01';

    return [
      'trace_id' => $traceId,
      'parent_span_id' => $parentSpanId,
      'span_id' => $spanId,
      'trace_flags' => $traceFlags,
      'traceparent' => sprintf('00-%s-%s-%s', $traceId, $spanId, $traceFlags),
      'tracestate' => $request instanceof Request ? $this->sanitizeHeaderValue((string) $request->headers->get('tracestate', ''), 512) : '',
      'baggage' => $request instanceof Request ? $this->sanitizeHeaderValue((string) $request->headers->get('baggage', ''), 1024) : '',
      'source' => $incoming ? 'incoming_traceparent' : 'generated',
    ];
  }

  /**
   * Generates a new span ID.
   */
  public function generateSpanId(): string {
    return bin2hex(random_bytes(8));
  }

  /**
   * Parses a W3C traceparent header.
   *
   * @return array<string, string>|null
   *   Parsed context or NULL.
   */
  private function parseTraceparent(string $traceparent): ?array {
    $traceparent = trim($traceparent);
    if ($traceparent === '') {
      return NULL;
    }

    if (!preg_match('/^[\da-f]{2}-([\da-f]{32})-([\da-f]{16})-([\da-f]{2})$/i', $traceparent, $matches)) {
      return NULL;
    }

    return [
      'trace_id' => strtolower($matches[1]),
      'span_id' => strtolower($matches[2]),
      'trace_flags' => strtolower($matches[3]),
    ];
  }

  /**
   * Generates a new trace ID.
   */
  private function generateTraceId(): string {
    return bin2hex(random_bytes(16));
  }

  /**
   * Normalizes and bounds a propagated header value before persistence.
   */
  private function sanitizeHeaderValue(string $value, int $maxLength): string {
    $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');
    if ($value === '') {
      return '';
    }

    return mb_substr($value, 0, $maxLength);
  }

}
