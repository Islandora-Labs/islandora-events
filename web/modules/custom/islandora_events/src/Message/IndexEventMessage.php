<?php

namespace Drupal\islandora_events\Message;

use Drupal\sm_ledger\Message\TrackableLedgerMessageInterface;

/**
 * Base message for indexing events processed by target-specific workers.
 */
readonly class IndexEventMessage implements TrackableLedgerMessageInterface {

  /**
   * Constructs a new IndexEventMessage.
   *
   * @param int $entityId
   *   Source entity ID.
   * @param string $entityType
   *   Source entity type.
   * @param string $eventType
   *   Trigger event type.
   * @param string $targetId
   *   Target integration ID.
   * @param int $eventRecordId
   *   Event record ID.
   * @param string $correlationKey
   *   Stable ledger correlation key used to resolve the record asynchronously.
   */
  public function __construct(
    public int $entityId,
    public string $entityType,
    public string $eventType,
    public string $targetId,
    public int $eventRecordId = 0,
    public string $correlationKey = '',
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getEventRecordId(): int {
    return $this->eventRecordId;
  }

  /**
   * {@inheritdoc}
   */
  public function getCorrelationKey(): string {
    return $this->correlationKey;
  }

}
