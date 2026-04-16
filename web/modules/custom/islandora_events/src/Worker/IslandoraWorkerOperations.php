<?php

declare(strict_types=1);

namespace Drupal\islandora_events\Worker;

use Drupal\sm_workers\WorkerOperationProviderInterface;
use Drupal\sm_workers\Service\WorkerCommandBuilder;
use Drupal\sm_workers\Service\WorkerDefinitionRegistry;

/**
 * Provides operator-facing commands related to Islandora worker transports.
 */
final class IslandoraWorkerOperations implements WorkerOperationProviderInterface {

  /**
   * Constructs the operation provider.
   */
  public function __construct(
    private WorkerDefinitionRegistry $registry,
    private WorkerCommandBuilder $commandBuilder,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getWorkerOperations(): array {
    return [
      'islandora_events.derivatives' => [
        [
          'label' => 'Drain persisted derivative work',
          'description' => 'Replay derivative records from the ledger without starting a long-running Messenger consumer.',
          'command' => 'drush islandora-events:process-derivatives --limit=100',
        ],
        [
          'label' => 'Drain derivative transport once',
          'description' => 'Consume derivative transport messages until empty and then exit.',
          'command' => $this->buildDrainCommand('islandora_events.derivatives'),
        ],
      ],
      'islandora_events.index_fedora' => [
        [
          'label' => 'Drain Fedora index transport once',
          'description' => 'Consume Fedora indexing messages until the transport is empty and then exit.',
          'command' => $this->buildDrainCommand('islandora_events.index_fedora'),
        ],
      ],
      'islandora_events.index_blazegraph' => [
        [
          'label' => 'Drain Blazegraph index transport once',
          'description' => 'Consume Blazegraph indexing messages until the transport is empty and then exit.',
          'command' => $this->buildDrainCommand('islandora_events.index_blazegraph'),
        ],
      ],
      'islandora_events.index_custom' => [
        [
          'label' => 'Drain custom index transport once',
          'description' => 'Consume custom indexing messages until the transport is empty and then exit.',
          'command' => $this->buildDrainCommand('islandora_events.index_custom'),
        ],
      ],
    ];
  }

  /**
   * Builds the one-shot drain command for one worker definition.
   */
  private function buildDrainCommand(string $workerId): string {
    $definition = $this->registry->get($workerId);
    if ($definition === NULL) {
      throw new \RuntimeException(sprintf('Unknown worker definition "%s".', $workerId));
    }

    $definition['options']['stop-when-empty'] = TRUE;
    return $this->commandBuilder->buildConsumeCommand($definition);
  }

}
