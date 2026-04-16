<?php

namespace Drupal\islandora_events\Service;

/**
 * Interface for executing persisted index ledger records.
 */
interface IndexRecordProcessorInterface {

  /**
   * Executes one persisted index event record.
   *
   * @throws \Throwable
   */
  public function processEventRecord(int $eventRecordId, bool $manageLifecycle = FALSE): void;

  /**
   * Replays one persisted index event record without rewriting ledger status.
   *
   * @throws \Throwable
   */
  public function replayEventRecord(int $eventRecordId): void;

}
