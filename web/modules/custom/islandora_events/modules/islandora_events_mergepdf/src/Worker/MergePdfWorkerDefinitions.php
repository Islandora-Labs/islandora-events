<?php

namespace Drupal\islandora_events_mergepdf\Worker;

use Drupal\sm_workers\WorkerDefinitionProviderInterface;

/**
 * Provides worker definitions for Islandora Events Merge PDF.
 */
final class MergePdfWorkerDefinitions implements WorkerDefinitionProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function getWorkerDefinitions(): array {
    return [
      'islandora_events_mergepdf.scheduler' => [
        'label' => 'Islandora Merge PDF scheduler',
        'description' => 'Schedules pending Merge PDF reconciliation.',
        'transports' => ['scheduler_islandora_events_mergepdf'],
        'options' => [
          'time-limit' => 3600,
        ],
      ],
      'islandora_events_mergepdf.reconcile' => [
        'label' => 'Islandora Merge PDF reconciliation',
        'description' => 'Processes pending Merge PDF reconciliation messages.',
        'transports' => ['islandora_mergepdf'],
        'options' => [
          'time-limit' => 3600,
        ],
      ],
    ];
  }

}
