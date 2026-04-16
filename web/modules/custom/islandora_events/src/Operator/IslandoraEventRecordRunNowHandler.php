<?php

namespace Drupal\islandora_events\Operator;

use Drupal\islandora_events\Service\DerivativeRunnerService;
use Drupal\islandora_events\Service\IndexRecordProcessor;
use Drupal\sm_ledger\Entity\EventRecord;
use Drupal\sm_ledger\Entity\EventRecordInterface;
use Drupal\sm_ledger\Operator\EventRecordRunNowHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Executes Islandora ledger records synchronously.
 */
final class IslandoraEventRecordRunNowHandler implements EventRecordRunNowHandlerInterface {

  /**
   * Constructs the handler.
   */
  public function __construct(
    private DerivativeRunnerService $derivativeRunner,
    private IndexRecordProcessor $indexRecordProcessor,
    private LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function supports(EventRecordInterface $record): bool {
    return in_array((string) $record->get('event_kind')->value, [
      EventRecord::KIND_DERIVATIVE,
      EventRecord::KIND_INDEXING,
    ], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function runNow(EventRecordInterface $record): void {
    $eventKind = (string) $record->get('event_kind')->value;

    match ($eventKind) {
      EventRecord::KIND_DERIVATIVE => $this->derivativeRunner->processEventRecord((int) $record->id()),
      EventRecord::KIND_INDEXING => $this->runIndexRecord((int) $record->id()),
      default => throw new \RuntimeException(sprintf('Unsupported event kind "%s".', $eventKind)),
    };
  }

  /**
   * Executes one indexing event record synchronously.
   */
  private function runIndexRecord(int $eventRecordId): void {
    try {
      $this->indexRecordProcessor->processEventRecord($eventRecordId, TRUE);
    }
    catch (\Throwable $exception) {
      $this->logger->error('Error running event record @id immediately: @message', [
        '@id' => $eventRecordId,
        '@message' => $exception->getMessage(),
      ]);
      throw $exception;
    }
  }

}
