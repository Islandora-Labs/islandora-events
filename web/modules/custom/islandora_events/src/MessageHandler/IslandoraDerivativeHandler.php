<?php

namespace Drupal\islandora_events\MessageHandler;

use Drupal\islandora_events\Message\IslandoraDerivativeMessage;
use Drupal\islandora_events\Service\DerivativeLifecycleMode;
use Drupal\islandora_events\Service\DerivativeRunnerService;
use Drupal\sm_ledger\Service\LedgerExecutionLockServiceInterface;
use Drupal\sm_ledger\Service\LedgerProjectionService;
use Drupal\sm_workers\Exception\CircuitBreakerOpenException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Symfony Messenger handler for record-centric derivative processing.
 */
#[AsMessageHandler]
class IslandoraDerivativeHandler {

  /**
   * Constructs a new IslandoraDerivativeHandler.
   *
   * @param \Drupal\islandora_events\Service\DerivativeRunnerService $derivativeRunner
   *   The derivative runner for stored payload execution.
   * @param \Drupal\sm_ledger\Service\LedgerProjectionService $projection
   *   Ledger projection lifecycle service.
   * @param \Drupal\sm_ledger\Service\LedgerExecutionLockServiceInterface $executionLocks
   *   Execution lock service for deduplicating concurrent workers.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(
    private DerivativeRunnerService $derivativeRunner,
    private LedgerProjectionService $projection,
    private LedgerExecutionLockServiceInterface $executionLocks,
    private LoggerInterface $logger,
  ) {}

  /**
   * Handles IslandoraDerivativeMessage.
   *
   * @param \Drupal\islandora_events\Message\IslandoraDerivativeMessage $message
   *   The message to process.
   */
  public function __invoke(IslandoraDerivativeMessage $message): void {
    $eventRecordId = $this->resolveEventRecordId($message);
    if ($eventRecordId <= 0) {
      throw new \InvalidArgumentException('Derivative messages must reference an event record or correlation key.');
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
      $this->derivativeRunner->processEventRecord(
        $eventRecordId,
        [],
        lifecycleMode: DerivativeLifecycleMode::Externalized,
      );
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
      $this->logger->error('Error processing derivative for @entity_type:@id: @message', [
        '@entity_type' => $message->entityType,
        '@id' => $message->entityId,
        '@message' => $e->getMessage(),
      ]);

      // Re-throw to let SM handle retries.
      throw $e;
    }
  }

  /**
   * Resolves the target ledger record for one derivative message.
   */
  private function resolveEventRecordId(IslandoraDerivativeMessage $message): int {
    if ($message->getEventRecordId() > 0) {
      return $message->getEventRecordId();
    }

    $correlationKey = $message->getCorrelationKey();
    return $correlationKey !== ''
      ? $this->projection->findOpenByCorrelationKey($correlationKey)
      : 0;
  }

}
