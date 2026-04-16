<?php

namespace Drupal\islandora_events\Operator;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\islandora_events\Message\CustomIndexEventMessage;
use Drupal\islandora_events\Message\IslandoraDerivativeMessage;
use Drupal\islandora_events\Message\IndexEventMessage;
use Drupal\islandora_events\Index\IndexTargetManager;
use Drupal\sm_ledger\Entity\EventRecord;
use Drupal\sm_ledger\Entity\EventRecordInterface;
use Drupal\sm_ledger\Operator\EventRecordRequeueHandlerInterface;

/**
 * Rebuilds Islandora messages for persisted ledger records.
 */
final class IslandoraEventRecordRequeueHandler implements EventRecordRequeueHandlerInterface {

  /**
   * Constructs the handler.
   */
  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private IndexTargetManager $indexTargetManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function supports(EventRecordInterface $record): bool {
    $eventKind = (string) $record->get('event_kind')->value;
    if (!in_array($eventKind, [EventRecord::KIND_DERIVATIVE, EventRecord::KIND_INDEXING], TRUE)) {
      return FALSE;
    }

    $entityType = (string) $record->get('source_entity_type')->value;
    $entityId = (int) $record->get('source_entity_id')->value;
    $triggerEventType = (string) $record->get('trigger_event_type')->value;
    if ($entityType === '' || $entityId <= 0 || $triggerEventType === '') {
      return FALSE;
    }

    $sourceEntityRequired = $eventKind === EventRecord::KIND_DERIVATIVE
      || $triggerEventType !== 'delete';
    if (!$sourceEntityRequired) {
      return TRUE;
    }

    try {
      return (bool) $this->entityTypeManager
        ->getStorage($entityType)
        ->load($entityId);
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildMessage(EventRecordInterface $record): object {
    $eventKind = (string) $record->get('event_kind')->value;
    $entityId = (int) $record->get('source_entity_id')->value;
    $entityType = (string) $record->get('source_entity_type')->value;
    $triggerEventType = (string) $record->get('trigger_event_type')->value;
    $eventRecordId = (int) $record->id();
    $correlationKey = (string) $record->get('correlation_key')->value;

    if ($eventKind === EventRecord::KIND_DERIVATIVE) {
      return new IslandoraDerivativeMessage(
        $entityId,
        $entityType,
        $triggerEventType,
        $eventRecordId,
        $correlationKey,
      );
    }

    $targetId = (string) $record->get('target_system')->value;
    $target = $this->indexTargetManager->getTarget($targetId);
    $messageClass = $target?->getMessageClass() ?? CustomIndexEventMessage::class;
    if (!is_a($messageClass, IndexEventMessage::class, TRUE)) {
      throw new \RuntimeException(sprintf(
        'Index target "%s" declared invalid message class "%s" for record %d.',
        $targetId,
        $messageClass,
        $eventRecordId,
      ));
    }

    return new $messageClass(
      $entityId,
      $entityType,
      $triggerEventType,
      $targetId,
      $eventRecordId,
      $correlationKey,
    );
  }

}
