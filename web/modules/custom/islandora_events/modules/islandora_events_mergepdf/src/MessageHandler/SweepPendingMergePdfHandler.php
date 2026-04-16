<?php

declare(strict_types=1);

namespace Drupal\islandora_events_mergepdf\MessageHandler;

use Drupal\islandora_events_mergepdf\Message\MergePdfMessage;
use Drupal\islandora_events_mergepdf\Message\SweepPendingMergePdfMessage;
use Drupal\islandora_events_mergepdf\Service\MergePdfPendingStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatches Merge PDF work for currently pending parents.
 */
#[AsMessageHandler]
final class SweepPendingMergePdfHandler {

  /**
   * Constructs the handler.
   */
  public function __construct(
    private MergePdfPendingStore $pendingStore,
    private MessageBusInterface $messageBus,
    private LoggerInterface $logger,
  ) {}

  /**
   * Sweeps the pending-set and dispatches Merge PDF work.
   */
  public function __invoke(SweepPendingMergePdfMessage $message): void {
    $pending = $this->pendingStore->all();
    foreach ($pending as $item) {
      $this->messageBus->dispatch(new MergePdfMessage(
        $item['entity_type'],
        (int) $item['entity_id'],
        'mergepdf',
      ));
    }

    if ($pending !== []) {
      $this->logger->info('Merge PDF reconciliation sweep dispatched @count parent entities.', [
        '@count' => count($pending),
      ]);
    }
  }

}
