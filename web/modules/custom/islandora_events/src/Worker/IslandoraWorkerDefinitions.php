<?php

namespace Drupal\islandora_events\Worker;

use Drupal\sm_workers\WorkerDefinitionProviderInterface;

/**
 * Provides Islandora Events worker definitions.
 */
final class IslandoraWorkerDefinitions implements WorkerDefinitionProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function getWorkerDefinitions(): array {
    return [
      'islandora_events.derivatives' => [
        'label' => 'Islandora derivatives',
        'description' => 'Processes derivative-generation messages.',
        'transports' => ['islandora_derivatives'],
        'options' => [
          'time-limit' => 3600,
        ],
      ],
      'islandora_events.index_fedora' => [
        'label' => 'Islandora Fedora indexing',
        'description' => 'Processes Fedora indexing messages.',
        'transports' => ['islandora_index_fedora'],
        'options' => [
          'time-limit' => 3600,
        ],
      ],
      'islandora_events.index_blazegraph' => [
        'label' => 'Islandora Blazegraph indexing',
        'description' => 'Processes Blazegraph indexing messages.',
        'transports' => ['islandora_index_blazegraph'],
        'options' => [
          'time-limit' => 3600,
        ],
      ],
      'islandora_events.index_custom' => [
        'label' => 'Islandora custom indexing',
        'description' => 'Processes custom index-target messages.',
        'transports' => ['islandora_index_custom'],
        'options' => [
          'time-limit' => 3600,
        ],
      ],
    ];
  }

}
