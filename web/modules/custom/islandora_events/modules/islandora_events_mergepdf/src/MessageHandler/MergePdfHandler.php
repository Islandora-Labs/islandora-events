<?php

namespace Drupal\islandora_events_mergepdf\MessageHandler;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\islandora_events_mergepdf\Message\MergePdfMessage;
use Drupal\islandora_events_mergepdf\Service\MergePdfPendingStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles scheduled mergepdf messages.
 */
#[AsMessageHandler]
class MergePdfHandler {

  /**
   * Constructs a new MergePdfHandler.
   */
  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private LoggerInterface $logger,
    private MergePdfPendingStore $pendingStore,
  ) {}

  /**
   * Executes queued mergepdf actions.
   */
  public function __invoke(MergePdfMessage $message): void {
    $storage = $this->entityTypeManager->getStorage($message->parentEntityType);
    $parentEntity = $storage->load($message->parentEntityId);

    if (!$parentEntity) {
      $this->pendingStore->remove($message->parentEntityType, $message->parentEntityId);
      $this->logger->warning('Parent entity @type:@id not found for mergepdf action', [
        '@type' => $message->parentEntityType,
        '@id' => $message->parentEntityId,
      ]);
      return;
    }

    $actionStorage = $this->entityTypeManager->getStorage('action');
    $action = $actionStorage->load($message->action);

    if (!$action) {
      $this->pendingStore->remove($message->parentEntityType, $message->parentEntityId);
      $this->logger->warning('Mergepdf action @action not found', [
        '@action' => $message->action,
      ]);
      return;
    }

    $action->execute([$parentEntity]);
    $this->pendingStore->remove($message->parentEntityType, $message->parentEntityId);
    $this->logger->info('Executed mergepdf action @action for @type:@id', [
      '@action' => $message->action,
      '@type' => $message->parentEntityType,
      '@id' => $message->parentEntityId,
    ]);
  }

}
