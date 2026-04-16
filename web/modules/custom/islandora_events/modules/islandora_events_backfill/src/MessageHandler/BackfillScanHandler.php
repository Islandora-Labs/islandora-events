<?php

namespace Drupal\islandora_events_backfill\MessageHandler;

use Drupal\islandora_events_backfill\Message\BackfillScanMessage;
use Drupal\islandora_events_backfill\Service\DerivativeScannerService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Executes one scheduled or manually dispatched backfill scan.
 */
#[AsMessageHandler]
final class BackfillScanHandler {

  /**
   * Constructs the handler.
   */
  public function __construct(
    private DerivativeScannerService $scanner,
    private LoggerInterface $logger,
  ) {}

  /**
   * Runs the requested scan.
   */
  public function __invoke(BackfillScanMessage $message): void {
    $result = $this->scanner->scanPlugin($message->pluginId);
    $this->logger->info('Backfill scan {plugin} completed: missing={missing} queued={queued}', [
      'plugin' => $message->pluginId,
      'missing' => $result['missing_count'],
      'queued' => $result['queued_count'],
    ]);
  }

}
