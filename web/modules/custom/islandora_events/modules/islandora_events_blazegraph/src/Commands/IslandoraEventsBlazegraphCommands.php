<?php

namespace Drupal\islandora_events_blazegraph\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\islandora_events\Index\IndexEventContext;
use Drupal\islandora_events_blazegraph\Index\Target\BlazegraphIndexTarget;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;

/**
 * Blazegraph operator commands for Islandora Events.
 */
final class IslandoraEventsBlazegraphCommands extends DrushCommands {

  /**
   * Constructs the command handler.
   */
  public function __construct(
    private EntityTypeManagerInterface $entityTypeManager,
    private BlazegraphIndexTarget $target,
    private LoggerInterface $logger,
  ) {}

  /**
   * Replays one stored Blazegraph ledger row.
   */
  #[CLI\Command(name: 'islandora-events-blazegraph:index-record')]
  #[CLI\Argument(name: 'event-record-id', description: 'Event record ID to replay through the embedded Blazegraph indexer.')]
  public function indexRecord(int $eventRecordId): int {
    $record = $this->entityTypeManager->getStorage('event_record')->load($eventRecordId);
    if ($record === NULL) {
      throw new \InvalidArgumentException(sprintf('Event record %d was not found.', $eventRecordId));
    }

    if ((string) $record->get('target_system')->value !== 'blazegraph') {
      throw new \InvalidArgumentException(sprintf(
        'Event record %d is not a Blazegraph record.',
        $eventRecordId,
      ));
    }

    $payloadRaw = (string) $record->get('payload_json')->value;
    $payload = $payloadRaw !== ''
      ? json_decode($payloadRaw, TRUE, 512, JSON_THROW_ON_ERROR)
      : [];
    $entityType = (string) $record->get('source_entity_type')->value;
    $entityId = (int) $record->get('source_entity_id')->value;
    $operation = (string) $record->get('trigger_event_type')->value;
    $entity = NULL;
    if ($entityType !== '' && $entityId > 0) {
      $entity = $this->entityTypeManager->getStorage($entityType)->load($entityId);
    }

    try {
      $this->target->process(new IndexEventContext(
        'blazegraph',
        $entityType,
        $entityId,
        $operation,
        is_array($payload) ? $payload : [],
        $entity,
      ));
      $this->output()->writeln(sprintf(
        'COMPLETED [record=%d entity=%s:%d op=%s]',
        $eventRecordId,
        $entityType,
        $entityId,
        $operation,
      ));
      return Command::SUCCESS;
    }
    catch (\Throwable $exception) {
      $this->logger->error('Blazegraph replay failed for record @id: @message', [
        '@id' => $eventRecordId,
        '@message' => $exception->getMessage(),
      ]);
      $this->output()->writeln(sprintf(
        'FAILED [record=%d] %s',
        $eventRecordId,
        $exception->getMessage(),
      ));
      return Command::FAILURE;
    }
  }

}
