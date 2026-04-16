<?php

declare(strict_types=1);

namespace Drupal\islandora_events_otel\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\islandora_events_otel\Messenger\Stamp\TraceContextStamp;
use Drupal\sm_ledger\Message\TrackableLedgerMessageInterface;
use Symfony\Component\Messenger\Envelope;

/**
 * Persists OTEL-style metadata on ledger records.
 */
class LedgerTelemetryService {

  /**
   * Event record storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private EntityStorageInterface $storage;

  /**
   * Constructs a ledger telemetry service.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    private TimeInterface $time,
    private TraceContextService $traceContext,
  ) {
    $this->storage = $entityTypeManager->getStorage('event_record');
  }

  /**
   * Initializes OTEL metadata for a new ledger record.
   */
  public function initializeRecordTelemetry(ContentEntityInterface $record): void {
    $metadata = $this->decodeMetadata((string) $record->get('transport_metadata')->value);
    if (isset($metadata['otel']['trace_id'])) {
      return;
    }

    $metadata['otel'] = $this->traceContext->buildCurrentContext() + [
      'record_initialized_at' => $this->time->getRequestTime(),
    ];

    $record->set('transport_metadata', $this->encodeMetadata($metadata));
  }

  /**
   * Records worker lifecycle telemetry for a trackable message.
   */
  public function recordWorkerLifecycle(string $phase, Envelope $envelope): void {
    $message = $envelope->getMessage();
    if (!$message instanceof TrackableLedgerMessageInterface) {
      return;
    }

    $recordId = $message->getEventRecordId();
    if ($recordId <= 0 && $message->getCorrelationKey() !== '') {
      $recordId = $this->resolveRecordIdByCorrelationKey($message->getCorrelationKey());
    }
    if ($recordId <= 0) {
      return;
    }

    $record = $this->storage->load($recordId);
    if (!$record instanceof ContentEntityInterface) {
      return;
    }

    $metadata = $this->decodeMetadata((string) $record->get('transport_metadata')->value);
    $metadata['otel'] = ($metadata['otel'] ?? []) + $this->resolveEnvelopeTraceContext($envelope);
    $metadata['otel']['message_class'] = $message::class;
    $metadata['otel']['worker'] ??= [];
    $metadata['otel']['worker']['last_phase'] = $phase;
    $metadata['otel']['worker']['last_phase_at'] = $this->time->getRequestTime();

    if ($phase === 'received') {
      $metadata['otel']['worker']['current_span_id'] = $this->traceContext->generateSpanId();
    }
    elseif (in_array($phase, ['handled', 'failed'], TRUE)) {
      $metadata['otel']['worker']['last_span_id'] = $metadata['otel']['worker']['current_span_id'] ?? '';
    }

    $record->set('transport_metadata', $this->encodeMetadata($metadata));
    $record->save();
  }

  /**
   * Resolves trace context from the envelope or falls back to local generation.
   *
   * @return array<string, string>
   *   Trace context values.
   */
  private function resolveEnvelopeTraceContext(Envelope $envelope): array {
    $stamp = $envelope->last(TraceContextStamp::class);
    if ($stamp instanceof TraceContextStamp) {
      return $stamp->getContext();
    }

    return $this->traceContext->buildCurrentContext();
  }

  /**
   * Decodes transport metadata.
   *
   * @return array<string, mixed>
   *   Decoded metadata.
   */
  private function decodeMetadata(string $metadata): array {
    if ($metadata === '') {
      return [];
    }

    try {
      $decoded = json_decode($metadata, TRUE, 512, JSON_THROW_ON_ERROR);
      return is_array($decoded) ? $decoded : [];
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Encodes transport metadata.
   */
  private function encodeMetadata(array $metadata): string {
    return json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
  }

  /**
   * Resolves one event record ID from a correlation key.
   */
  private function resolveRecordIdByCorrelationKey(string $correlationKey): int {
    $ids = $this->storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('correlation_key', $correlationKey)
      ->condition('needs_processing', 1)
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();

    if ($ids === []) {
      return 0;
    }

    return (int) reset($ids);
  }

}
