<?php

namespace Drupal\islandora_events_backfill\Worker;

use Drupal\sm_workers\WorkerDefinitionProviderInterface;

/**
 * Provides worker definitions for Islandora Events Backfill.
 */
final class BackfillWorkerDefinitions implements WorkerDefinitionProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function getWorkerDefinitions(): array {
    return [
      'islandora_events_backfill.scheduler' => [
        'label' => 'Islandora backfill scheduler',
        'description' => 'Schedules derivative backfill scans.',
        'transports' => ['scheduler_islandora_events_backfill'],
        'options' => [
          'time-limit' => 3600,
        ],
      ],
      'islandora_events_backfill.scan' => [
        'label' => 'Islandora backfill scan',
        'description' => 'Processes derivative backfill scan messages.',
        'transports' => ['islandora_backfill'],
        'options' => [
          'time-limit' => 3600,
        ],
      ],
    ];
  }

}
