<?php

namespace Drupal\islandora_events_backfill\Commands;

use Drupal\islandora_events_backfill\Service\DerivativeScannerService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for optional backfill scanning.
 */
class IslandoraEventsBackfillCommands extends DrushCommands {

  /**
   * Constructs the backfill Drush command class.
   */
  public function __construct(
    protected DerivativeScannerService $derivativeScanner,
  ) {}

  /**
   * Runs derivative scans or one requested scan plugin.
   */
  #[CLI\Command(name: 'islandora-events:scan-missing', aliases: ['ie:scan'])]
  #[CLI\Help(description: 'Scan for missing derivatives and queue backfill events for processing.')]
  #[CLI\Option(name: 'type', description: 'Specific derivative type to scan (optional)')]
  public function scanMissing($options = ['type' => NULL]): void {
    $type = $options['type'];
    $results = $type !== NULL
      ? [$type => $this->derivativeScanner->scanPlugin((string) $type)]
      : $this->derivativeScanner->scanMissingDerivatives();
    if (empty($results)) {
      $this->output()->writeln('<comment>No missing derivatives found.</comment>');
      return;
    }
    foreach ($results as $result) {
      $this->output()->writeln(sprintf('%s: missing=%d queued=%d action=%s', $result['description'], $result['missing_count'], $result['queued_count'], $result['action']));
    }
  }

  /**
   * Shows derivative scan schedule status.
   */
  #[CLI\Command(name: 'islandora-events:scan-status', aliases: ['ie:scan-status'])]
  #[CLI\Help(description: 'Show derivative backfill scan schedule and statistics.')]
  public function scanStatus(): void {
    foreach ($this->derivativeScanner->getScanStats() as $type => $stat) {
      $this->output()->writeln(sprintf('%s (%s): last=%s next=%s', $stat['description'], $type, $stat['last_scan'], $stat['next_due']));
    }
  }

  /**
   * Lists the registered derivative scanner plugins.
   */
  #[CLI\Command(name: 'islandora-events:scan-types', aliases: ['ie:types'])]
  #[CLI\Help(description: 'List available derivative backfill scan types and their configurations.')]
  public function scanTypes(): void {
    foreach ($this->derivativeScanner->getScanConfigurations() as $type => $config) {
      $this->output()->writeln(sprintf('%s: action=%s entity=%s frequency=%d', $type, $config['action'], $config['entity_type'], $config['frequency']));
    }
  }

  /**
   * Shows the scheduler transport name for recurring backfill scans.
   */
  #[CLI\Command(name: 'islandora-events:scan-scheduler-info', aliases: ['ie:scan-scheduler'])]
  #[CLI\Help(description: 'Show the scheduler transport and worker commands for backfill scanning.')]
  public function schedulerInfo(): void {
    $this->output()->writeln('Recurring backfill scan schedule transport: scheduler_islandora_events_backfill');
    $this->output()->writeln('Consume the schedule generator with:');
    $this->output()->writeln('  drush sm:consume scheduler_islandora_events_backfill');
    $this->output()->writeln('Consume generated backfill work with:');
    $this->output()->writeln('  drush sm:consume islandora_backfill');
    $this->output()->writeln('These can also be chained in one worker process by priority:');
    $this->output()->writeln('  drush sm:consume scheduler_islandora_events_backfill islandora_backfill');
  }

}
