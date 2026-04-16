<?php

namespace Drupal\islandora_events\Service;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\islandora_events\Index\IndexEventContext;
use Drupal\islandora_events\Index\IndexTargetManager;
use Drupal\sm_ledger\Entity\EventRecordInterface;
use Drupal\sm_ledger\Service\LedgerProjectionService;

/**
 * Executes persisted index ledger records.
 */
final class IndexRecordProcessor implements IndexRecordProcessorInterface {

  /**
   * Event record storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private EntityStorageInterface $recordStorage;

  /**
   * User storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private EntityStorageInterface $userStorage;

  /**
   * Constructs the processor.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    private IndexTargetManager $indexTargetManager,
    private LedgerProjectionService $projection,
    private AccountSwitcherInterface $accountSwitcher,
  ) {
    $this->recordStorage = $entityTypeManager->getStorage('event_record');
    $this->userStorage = $entityTypeManager->getStorage('user');
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * Executes one persisted index event record.
   *
   * @throws \Throwable
   */
  public function processEventRecord(int $eventRecordId, bool $manageLifecycle = FALSE): void {
    $this->doProcessEventRecord($eventRecordId, $manageLifecycle, TRUE);
  }

  /**
   * Replays one persisted index event record without rewriting ledger status.
   *
   * This is intended for idempotent reindex workflows where the ledger is the
   * source of truth for what should be reprocessed. Historical record state is
   * preserved so replay can be used operationally without falsifying the
   * original attempt lifecycle.
   *
   * @throws \Throwable
   */
  public function replayEventRecord(int $eventRecordId): void {
    $this->doProcessEventRecord($eventRecordId, FALSE, FALSE);
  }

  /**
   * Executes one persisted index event record.
   *
   * @throws \Throwable
   */
  private function doProcessEventRecord(
    int $eventRecordId,
    bool $manageLifecycle,
    bool $writeDisposition,
  ): void {
    $record = $this->loadEventRecord($eventRecordId);
    if ($record === NULL) {
      throw new \RuntimeException(sprintf('Event record %d not found.', $eventRecordId));
    }

    if ($manageLifecycle) {
      $this->projection->markProcessing($eventRecordId);
    }

    try {
      $targetId = (string) $record->get('target_system')->value;
      $target = $this->indexTargetManager->getTarget($targetId);
      if (!$target || !$target->isEnabled()) {
        if ($writeDisposition) {
          $this->projection->markAbandoned(
            $eventRecordId,
            sprintf('Index target "%s" is not enabled.', $targetId)
          );
        }
        return;
      }

      $payloadRaw = (string) $record->get('payload_json')->value;
      $payload = $payloadRaw !== '' ? json_decode($payloadRaw, TRUE, 512, JSON_THROW_ON_ERROR) : [];
      $entityType = (string) $record->get('source_entity_type')->value;
      $entityId = (int) $record->get('source_entity_id')->value;
      $operation = (string) $record->get('trigger_event_type')->value;
      $entity = NULL;
      if ($entityType !== '' && $entityId > 0) {
        $entity = $this->entityTypeManager->getStorage($entityType)->load($entityId);
      }

      $switched = FALSE;
      $initiatingUserId = (int) $record->get('initiating_user_id')->target_id;
      if ($initiatingUserId > 0) {
        $user = $this->userStorage->load($initiatingUserId);
        if ($user) {
          $this->accountSwitcher->switchTo($user);
          $switched = TRUE;
        }
      }

      try {
        $target->process(new IndexEventContext(
          $targetId,
          $entityType,
          $entityId,
          $operation,
          is_array($payload) ? $payload : [],
          $entity,
        ));
      }
      finally {
        if ($switched) {
          $this->accountSwitcher->switchBack();
        }
      }

      if ($manageLifecycle && $writeDisposition) {
        $this->projection->markCompleted($eventRecordId);
      }
    }
    catch (\Throwable $exception) {
      if ($this->isStaleNoopException($exception)) {
        if ($manageLifecycle && $writeDisposition) {
          $this->projection->markCompleted($eventRecordId);
        }
        return;
      }

      if ($manageLifecycle && $writeDisposition) {
        $this->projection->markFailed($eventRecordId, $exception->getMessage());
      }
      throw $exception;
    }
  }

  /**
   * Loads one event record.
   */
  private function loadEventRecord(int $eventRecordId): ?EventRecordInterface {
    $record = $this->recordStorage->load($eventRecordId);
    return $record instanceof EventRecordInterface ? $record : NULL;
  }

  /**
   * Returns whether an exception represents an idempotent stale-update no-op.
   */
  private function isStaleNoopException(\Throwable $exception): bool {
    return $exception->getCode() === 412
      && str_contains($exception->getMessage(), 'is not newer');
  }

}
