<?php

namespace Drupal\islandora_events\MessageHandler;

use Drupal\islandora_events\Message\IndexEventMessage;
use Drupal\islandora_events\Service\IndexRecordProcessorInterface;
use Drupal\sm_ledger\Service\LedgerExecutionLockServiceInterface;
use Drupal\sm_ledger\Service\LedgerProjectionService;
use Drupal\sm_workers\Exception\CircuitBreakerOpenException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles Fedora/Blazegraph indexing messages through shared target API.
 */
#[AsMessageHandler]
class IndexEventHandler {

  /**
   * Constructs an index event handler.
   */
  public function __construct(
    private IndexRecordProcessorInterface $indexRecordProcessor,
    private LedgerProjectionService $projection,
    private LedgerExecutionLockServiceInterface $executionLocks,
    private LoggerInterface $logger,
  ) {}

  /**
   * Handles one index event.
   */
  public function __invoke(IndexEventMessage $message): void {
    $eventRecordId = $this->resolveEventRecordId($message);
    if ($eventRecordId <= 0) {
      return;
    }
    if (!$this->executionLocks->acquire($message)) {
      return;
    }
    if (!$this->projection->isQueuedForProcessing($eventRecordId)) {
      $this->executionLocks->release($message);
      return;
    }

    $this->projection->markProcessing($eventRecordId);

    try {
      $this->indexRecordProcessor->processEventRecord($eventRecordId);
    }
    catch (CircuitBreakerOpenException $e) {
      if ($e->isManualOpen()) {
        $this->projection->markNeedsManualIntervention($eventRecordId, $e->getMessage());
      }
      else {
        $this->projection->markRetryDue($eventRecordId, 0, $e->retryAfter(), $e->getMessage());
      }
      $this->executionLocks->release($message);
      return;
    }
    catch (\Throwable $e) {
      $this->logger->error('Error processing indexing event @target @type:@id: @message', [
        '@target' => $message->targetId,
        '@type' => $message->entityType,
        '@id' => $message->entityId,
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Resolves the target ledger record for one index message.
   */
  private function resolveEventRecordId(IndexEventMessage $message): int {
    if ($message->getEventRecordId() > 0) {
      return $message->getEventRecordId();
    }

    $correlationKey = $message->getCorrelationKey();
    if ($correlationKey === '') {
      $correlationKey = sprintf(
        'index:%s:%s:%d:%s',
        $message->targetId,
        $message->entityType,
        $message->entityId,
        $message->eventType
      );
    }

    return $this->projection->findOpenByCorrelationKey($correlationKey);
  }

}
