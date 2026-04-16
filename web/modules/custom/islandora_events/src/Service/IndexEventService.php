<?php

namespace Drupal\islandora_events\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\islandora_events\Index\IndexPayloadBuilder;
use Drupal\islandora_events\Index\IndexTargetManager;
use Drupal\islandora_events\Message\CustomIndexEventMessage;
use Drupal\islandora_events\Message\IndexEventMessage;
use Drupal\sm_ledger\Entity\EventRecord;
use Drupal\sm_ledger\Service\LedgerDispatchService;
use Psr\Log\LoggerInterface;

/**
 * Queues indexing events for configured targets.
 */
class IndexEventService {

  /**
   * Constructs an index event service.
   */
  public function __construct(
    private IndexTargetManager $indexTargetManager,
    private IndexPayloadBuilder $payloadBuilder,
    private LedgerDispatchService $dispatch,
    private LoggerInterface $logger,
  ) {}

  /**
   * Queues indexing messages for all enabled targets.
   *
   * With SQL-backed Messenger transports, the ledger row and Messenger enqueue
   * happen inside one database transaction. Non-SQL transports would need a
   * dedicated outbox relay to make the same guarantee.
   */
  public function queueEntityEvent(
    EntityInterface $entity,
    string $operation,
    ?array $targetIds = NULL,
  ): void {
    $entityType = $entity->getEntityTypeId();
    $targetIds ??= $this->indexTargetManager->getEnabledTargetIdsFor($entityType, $operation);

    foreach ($targetIds as $targetId) {
      $dedupeKey = sprintf(
        'index:%s:%s:%d:%s',
        $targetId,
        $entityType,
        (int) $entity->id(),
        $operation,
      );
      $correlationKey = $this->buildCorrelationKey($dedupeKey);
      $queued = $this->dispatch->recordAndDispatch(
        $entity,
        $operation,
        $dedupeKey,
        $this->createIndexMessage(
          $targetId,
          (int) $entity->id(),
          $entityType,
          $operation,
          $correlationKey,
        ),
        [
          'event_kind' => EventRecord::KIND_INDEXING,
          'target_system' => $targetId,
          'action_plugin_id' => 'islandora_events.index_target',
          'correlation_key' => $correlationKey,
          'dedupe_key' => $dedupeKey,
          'payload_json' => json_encode(
            $this->payloadBuilder->build($entity, $operation, $targetId),
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
          ),
        ],
      );
      if (!$queued) {
        $this->logger->debug(
          'Skipped index enqueue for {target} {entity_type}:{entity_id} because a matching record already exists inside the dedupe window.',
          [
            'target' => $targetId,
            'entity_type' => $entityType,
            'entity_id' => (int) $entity->id(),
          ],
        );
      }
    }
  }

  /**
   * Creates the message class for one target dispatch.
   */
  private function createIndexMessage(
    string $targetId,
    int $entityId,
    string $entityType,
    string $eventType,
    string $correlationKey,
  ): IndexEventMessage {
    $target = $this->indexTargetManager->getTarget($targetId);
    $messageClass = $target?->getMessageClass() ?? CustomIndexEventMessage::class;

    return new $messageClass(
      $entityId,
      $entityType,
      $eventType,
      $targetId,
      0,
      $correlationKey,
    );
  }

  /**
   * Builds a transport-stable but row-unique correlation key.
   */
  private function buildCorrelationKey(string $dedupeKey): string {
    return sprintf('%s:%s', $dedupeKey, bin2hex(random_bytes(8)));
  }

}
