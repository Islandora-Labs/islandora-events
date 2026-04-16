<?php

namespace Drupal\islandora_events_backfill\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\islandora_events\Service\DerivativeQueueService;
use Drupal\islandora_events_backfill\Message\IslandoraBackfillDerivativeMessage;
use Drupal\islandora_events_backfill\Plugin\DerivativeScannerInterface;
use Drupal\islandora_events_backfill\Plugin\DerivativeScannerManager;
use Psr\Log\LoggerInterface;
use Drupal\sm_ledger\Service\LedgerProjectionService;

/**
 * Service for scanning and queuing missing derivative events.
 */
class DerivativeScannerService {

  /**
   * Constructs the derivative scanner service.
   */
  public function __construct(
    protected StateInterface $state,
    protected LoggerInterface $logger,
    protected DerivativeScannerManager $scannerManager,
    protected TimeInterface $time,
    protected LedgerProjectionService $projection,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DerivativeQueueService $derivativeQueue,
    protected LockBackendInterface $lock,
  ) {}

  /**
   * Runs derivative scanners and queues any missing work.
   *
   * @return array<string, array<string, int|string>>
   *   Scan results keyed by plugin ID.
   */
  public function scanMissingDerivatives(?string $scan_type = NULL): array {
    $results = [];
    $definitions = $scan_type
      ? [$scan_type => $this->scannerManager->getDefinition($scan_type)]
      : $this->scannerManager->getSortedDefinitions();

    foreach ($definitions as $plugin_id => $definition) {
      $lockName = sprintf('islandora_events_backfill:scanner:%s', $plugin_id);
      if (!$this->lock->acquire($lockName, 900.0)) {
        $this->logger->debug('Skipped derivative scanner @plugin because another worker holds the scan lock.', [
          '@plugin' => $plugin_id,
        ]);
        continue;
      }

      try {
        $results[$plugin_id] = $this->scanPlugin($plugin_id);
      }
      catch (\Exception $e) {
        $this->logger->error('Error running derivative scanner @plugin: @message', [
          '@plugin' => $plugin_id,
          '@message' => $e->getMessage(),
        ]);
      }
      finally {
        $this->lock->release($lockName);
      }
    }

    return $results;
  }

  /**
   * Runs one scanner plugin and queues missing derivatives.
   *
   * @return array<string, int|string>
   *   Scan result details.
   */
  public function scanPlugin(string $pluginId): array {
    $scanner = $this->scannerManager->createInstance($pluginId);
    \assert($scanner instanceof DerivativeScannerInterface);
    $missingEntities = $scanner->findMissingDerivatives();
    $queued = $this->queueMissingDerivatives($missingEntities, $scanner, $pluginId);

    $this->state->set("islandora_events_backfill.last_scan.{$pluginId}", $this->time->getRequestTime());

    return [
      'description' => $scanner->getDescription(),
      'missing_count' => count($missingEntities),
      'queued_count' => $queued,
      'action' => $scanner->getAction(),
    ];
  }

  /**
   * Queues missing derivatives discovered by one scanner.
   */
  protected function queueMissingDerivatives(array $entity_ids, DerivativeScannerInterface $scanner, string $pluginId): int {
    if ($entity_ids === []) {
      return 0;
    }

    $queued = 0;
    $entityType = $scanner->getEntityType();
    $eventType = $scanner->getEventType();
    $storage = $this->entityTypeManager->getStorage($entityType);
    $entities = $storage->loadMultiple($entity_ids);

    foreach ($entity_ids as $entity_id) {
      $entityId = (int) $entity_id;
      if (!isset($entities[$entityId]) || !$entities[$entityId] instanceof EntityInterface) {
        continue;
      }
      if ($this->projection->findOpenEventRecordId($entityType, $entityId, $eventType) > 0) {
        continue;
      }

      $this->derivativeQueue->enqueueConfiguredAction(
        $entities[$entityId],
        $scanner->getAction(),
        $eventType,
        sprintf('scanner:%s:%s:%d:%s', $pluginId, $entityType, $entityId, $eventType),
        IslandoraBackfillDerivativeMessage::class,
      );
      $queued++;
    }

    return $queued;
  }

  /**
   * Returns the scanner plugin definitions.
   */
  public function getScanConfigurations(): array {
    return $this->scannerManager->getDefinitions();
  }

  /**
   * Returns current scan timing and due-state information.
   *
   * @return array<string, array<string, int|string|bool>>
   *   Scan stats keyed by plugin ID.
   */
  public function getScanStats(): array {
    $stats = [];
    $current_time = $this->time->getRequestTime();
    foreach ($this->scannerManager->getDefinitions() as $plugin_id => $definition) {
      $last_scan = (int) $this->state->get("islandora_events_backfill.last_scan.{$plugin_id}", 0);
      $next_due = $last_scan + (((int) $definition['frequency']) * 3600);
      $stats[$plugin_id] = [
        'description' => (string) $definition['description'],
        'frequency_hours' => (int) $definition['frequency'],
        'last_scan' => $last_scan ? date('Y-m-d H:i:s', $last_scan) : 'Never',
        'next_due' => date('Y-m-d H:i:s', $next_due),
        'is_due' => $current_time >= $next_due,
        'overdue_minutes' => $current_time >= $next_due ? (int) floor(($current_time - $next_due) / 60) : 0,
      ];
    }
    return $stats;
  }

}
