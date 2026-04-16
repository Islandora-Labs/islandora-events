<?php

namespace Drupal\islandora_events\Service;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\sm_ledger\Entity\EventRecord;
use Drupal\sm_ledger\Entity\EventRecordInterface;
use Psr\Log\LoggerInterface;

/**
 * Replays persisted indexing ledger rows for idempotent reindex workflows.
 */
final class IndexLedgerReplayService {

  /**
   * Event record storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private EntityStorageInterface $recordStorage;

  /**
   * Constructs the replay service.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    private IndexRecordProcessorInterface $indexRecordProcessor,
    private LoggerInterface $logger,
  ) {
    $this->recordStorage = $entityTypeManager->getStorage('event_record');
  }

  /**
   * Replays matching indexing ledger rows.
   *
   * @param array<string, mixed> $filters
   *   Optional filter values keyed by:
   *   - target_id
   *   - entity_type
   *   - entity_id
   *   - operation
   *   - statuses.
   * @param int $limit
   *   Maximum rows to inspect in one run.
   * @param bool $dryRun
   *   When TRUE, only report matching rows.
   *
   * @return array<int, array<string, mixed>>
   *   Per-record replay results.
   */
  public function replay(array $filters = [], int $limit = 100, bool $dryRun = FALSE): array {
    $ids = $this->findReplayRecordIds($filters, $limit);
    if ($ids === []) {
      return [];
    }

    $records = $this->loadRecords($ids);
    $results = [];
    foreach ($ids as $id) {
      $record = $records[$id] ?? NULL;
      if (!$record instanceof EventRecordInterface) {
        continue;
      }

      if ($dryRun) {
        $results[] = $this->buildResult($record, 'planned', 'Would replay persisted index event.');
        continue;
      }

      try {
        $this->indexRecordProcessor->replayEventRecord($id);
        $results[] = $this->buildResult($record, 'replayed', 'Replayed persisted index event.');
      }
      catch (\Throwable $exception) {
        $this->logger->error('Index ledger replay failed for record @id: @message', [
          '@id' => $id,
          '@message' => $exception->getMessage(),
        ]);
        $results[] = $this->buildResult($record, 'failed', $exception->getMessage());
      }
    }

    return $results;
  }

  /**
   * Finds matching indexing event record IDs in replay order.
   *
   * @param array<string, mixed> $filters
   *   Replay filters.
   * @param int $limit
   *   Maximum row count.
   *
   * @return int[]
   *   Matching event record IDs.
   */
  public function findReplayRecordIds(array $filters = [], int $limit = 100): array {
    $query = $this->recordStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('event_kind', EventRecord::KIND_INDEXING)
      ->sort('id', 'ASC')
      ->range(0, max(1, $limit));

    $targetId = trim((string) ($filters['target_id'] ?? ''));
    if ($targetId !== '') {
      $query->condition('target_system', $targetId);
    }

    $entityType = trim((string) ($filters['entity_type'] ?? ''));
    if ($entityType !== '') {
      $query->condition('source_entity_type', $entityType);
    }

    $entityId = (int) ($filters['entity_id'] ?? 0);
    if ($entityId > 0) {
      $query->condition('source_entity_id', $entityId);
    }

    $operation = trim((string) ($filters['operation'] ?? ''));
    if ($operation !== '') {
      $query->condition('trigger_event_type', $operation);
    }

    $statuses = array_values(array_filter(
      array_map(
        static fn (mixed $status): string => trim((string) $status),
        is_array($filters['statuses'] ?? NULL) ? $filters['statuses'] : []
      ),
      static fn (string $status): bool => $status !== ''
    ));
    if ($statuses !== []) {
      $query->condition('status', $statuses, 'IN');
    }

    return array_map('intval', array_values($query->execute()));
  }

  /**
   * Loads event records keyed by integer ID.
   *
   * @param int[] $ids
   *   Event record IDs.
   *
   * @return array<int, \Drupal\sm_ledger\Entity\EventRecordInterface>
   *   Loaded records.
   */
  private function loadRecords(array $ids): array {
    $records = [];
    foreach ($this->recordStorage->loadMultiple($ids) as $record) {
      if ($record instanceof EventRecordInterface) {
        $records[(int) $record->id()] = $record;
      }
    }

    return $records;
  }

  /**
   * Builds a consistent result payload for replay output.
   *
   * @return array<string, mixed>
   *   Replay result values.
   */
  private function buildResult(EventRecordInterface $record, string $status, string $message): array {
    return [
      'id' => (int) $record->id(),
      'status' => $status,
      'target' => (string) $record->get('target_system')->value,
      'entity_type' => (string) $record->get('source_entity_type')->value,
      'entity_id' => (int) $record->get('source_entity_id')->value,
      'operation' => (string) $record->get('trigger_event_type')->value,
      'ledger_status' => (string) $record->get('status')->value,
      'message' => $message,
    ];
  }

}
