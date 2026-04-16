<?php

namespace Drupal\islandora_events\Message;

use Drupal\sm_ledger\Message\TrackableLedgerMessageInterface;

/**
 * Symfony Messenger message for Islandora derivative processing.
 */
readonly class IslandoraDerivativeMessage implements TrackableLedgerMessageInterface {

  /**
   * Constructs a new IslandoraDerivativeMessage.
   *
   * @param int $entityId
   *   The entity ID to process.
   * @param string $entityType
   *   The entity type (e.g., 'node', 'media').
   * @param string $eventType
   *   The event type that triggered this (e.g., 'media_insert', 'node_update').
   * @param int $eventRecordId
   *   The linked event record ID.
   * @param string $correlationKey
   *   Stable ledger correlation key used to resolve the record asynchronously.
   */
  public function __construct(
    public int $entityId,
    public string $entityType,
    public string $eventType,
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
