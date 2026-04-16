<?php

namespace Drupal\islandora_events\Commands;

use Drupal\islandora_events\Service\DerivativeRunnerService;
use Drupal\islandora_events\Service\IndexLedgerReplayService;
use Drupal\sm_ledger\Service\LedgerCapacityReportService;
use Drush\Commands\DrushCommands;
use Drush\Attributes as CLI;
use Symfony\Component\Console\Command\Command;

/**
 * Drush commands for Islandora Events queue management.
 */
class IslandoraEventsCommands extends DrushCommands {

  /**
   * Constructs an IslandoraEventsCommands object.
   *
   * @param \Drupal\islandora_events\Service\DerivativeRunnerService $derivativeRunner
   *   The derivative queue runner service.
   * @param \Drupal\islandora_events\Service\IndexLedgerReplayService $indexLedgerReplay
   *   The index ledger replay service.
   * @param \Drupal\sm_ledger\Service\LedgerCapacityReportService $capacityReport
   *   The capacity reporting service.
   */
  public function __construct(
    private DerivativeRunnerService $derivativeRunner,
    private IndexLedgerReplayService $indexLedgerReplay,
    private LedgerCapacityReportService $capacityReport,
  ) {}

  /**
   * Processes derivative work from stored event records.
   */
  #[CLI\Command(name: 'islandora-events:process-derivatives', aliases: ['ie:process-derivatives'])]
  #[CLI\Help(description: 'Drain a derivative queue and execute the configured HTTP endpoint or local runner. Command mode is privileged-only and controlled from settings.php, not the Drupal UI.')]
  #[CLI\Option(name: 'queue', description: 'Queue name to process. Optional; when omitted, process queued derivative records across all queues.')]
  #[CLI\Option(name: 'limit', description: 'Maximum jobs to process.')]
  #[CLI\Option(name: 'execution-mode', description: 'Override execution mode: http or command.')]
  #[CLI\Option(name: 'endpoint', description: 'Override the configured HTTP endpoint.')]
  #[CLI\Option(name: 'command', description: 'Override the configured local command template.')]
  #[CLI\Option(name: 'config-path', description: 'Override the local config-backed command runner path, such as a mounted scyllaridae.yml file.')]
  #[CLI\Option(name: 'working-directory', description: 'Override the working directory for command mode.')]
  #[CLI\Option(name: 'write-back', description: 'Override whether the runner should PUT the command output back to Drupal.')]
  #[CLI\Option(name: 'dry-run', description: 'Inspect jobs without invoking the runner.')]
  #[CLI\Option(name: 'failure-rate-threshold', description: 'Maximum allowed failed-job ratio before the command exits non-zero. Use 0 to fail on any execution error.')]
  public function processDerivatives(
    array $options = [
      'queue' => NULL,
      'limit' => 1,
      'execution-mode' => NULL,
      'endpoint' => NULL,
      'command' => NULL,
      'config-path' => NULL,
      'working-directory' => NULL,
      'write-back' => NULL,
      'dry-run' => FALSE,
      'failure-rate-threshold' => 0,
    ],
  ): int {
    $queue = $options['queue'] !== NULL ? (string) $options['queue'] : NULL;
    $limit = max(1, (int) ($options['limit'] ?? 1));
    $overrides = array_filter([
      'execution_mode' => $options['execution-mode'] ?? NULL,
      'endpoint' => $options['endpoint'] ?? NULL,
      'command' => $options['command'] ?? NULL,
      'config_path' => $options['config-path'] ?? NULL,
      'working_directory' => $options['working-directory'] ?? NULL,
      'write_back' => $options['write-back'],
    ], static fn ($value): bool => $value !== NULL && $value !== '');

    $results = $this->derivativeRunner->processNativeQueue($queue, $limit, (bool) $options['dry-run'], $overrides);

    foreach ($results as $result) {
      $this->output()->writeln(sprintf(
        '%s [%s] %s',
        strtoupper($result['status']),
        $result['queue'],
        $result['message']
      ));
    }

    if ((bool) $options['dry-run'] || $results === []) {
      return Command::SUCCESS;
    }

    $failed = count(array_filter(
      $results,
      static fn (array $result): bool => ($result['status'] ?? '') === 'failed',
    ));
    $failureRate = $failed / max(count($results), 1);
    $threshold = max(0.0, min(1.0, (float) ($options['failure-rate-threshold'] ?? 0)));

    if ($failed > 0) {
      $this->output()->writeln(sprintf(
        'SUMMARY [failed=%d total=%d rate=%.3f threshold=%.3f]',
        $failed,
        count($results),
        $failureRate,
        $threshold,
      ));
    }

    return $failureRate > $threshold
      ? Command::FAILURE
      : Command::SUCCESS;
  }

  /**
   * Shows a point-in-time capacity and throughput snapshot.
   */
  #[CLI\Command(name: 'islandora-events:capacity-report', aliases: ['ie:capacity'])]
  #[CLI\Help(description: 'Show queue depth, backlog, completion throughput, and timing from the live ledger.')]
  #[CLI\Option(name: 'window-minutes', description: 'How many recent minutes to use for throughput and timing calculations.')]
  #[CLI\Option(name: 'target-rps', description: 'Optional target completed events per second to compare against measured throughput.')]
  public function capacityReport(
    array $options = [
      'window-minutes' => 15,
      'target-rps' => 0,
    ],
  ): void {
    $report = $this->capacityReport->buildReport(
      (int) ($options['window-minutes'] ?? 15),
      (float) ($options['target-rps'] ?? 0),
    );

    $this->output()->writeln('<info>Islandora Events Capacity Snapshot</info>');
    $this->output()->writeln(sprintf('Window: %d minute(s)', $report['window_minutes']));
    if ((float) $report['target_rps'] > 0) {
      $this->output()->writeln(sprintf('Target throughput: %.3f completed events/sec', $report['target_rps']));
    }

    $this->output()->writeln('');
    $this->output()->writeln('<info>Queue Depth</info>');
    foreach ($report['queue_depths'] as $transport => $depth) {
      $this->output()->writeln(sprintf('  %s: %d', $transport, $depth));
    }

    $this->output()->writeln('');
    $this->output()->writeln('<info>Ledger Status Counts</info>');
    foreach ($report['status_counts'] as $status => $count) {
      $this->output()->writeln(sprintf('  %s: %d', $status, $count));
    }

    $this->output()->writeln('');
    $this->output()->writeln('<info>Recent Throughput</info>');
    $this->output()->writeln(sprintf('  completed count: %d', $report['completion']['completed_count']));
    $this->output()->writeln(sprintf('  completed/min: %.2f', $report['completion']['completed_per_minute']));
    $this->output()->writeln(sprintf('  completed/sec: %.4f', $report['completion']['completed_rps']));

    $this->output()->writeln('');
    $this->output()->writeln('<info>Recent Timing</info>');
    $this->output()->writeln(sprintf('  samples: %d', $report['processing']['sample_count']));
    $this->output()->writeln(sprintf('  avg queue wait sec: %.2f', $report['processing']['avg_queue_wait_seconds']));
    $this->output()->writeln(sprintf('  avg processing sec: %.2f', $report['processing']['avg_processing_seconds']));

    $this->output()->writeln('');
    $this->output()->writeln('<info>Recommendations</info>');
    foreach ($report['recommendations'] as $message) {
      $this->output()->writeln(sprintf('  - %s', $message));
    }
  }

  /**
   * Replays persisted indexing ledger rows for idempotent reindexing.
   */
  #[CLI\Command(name: 'islandora-events:replay-index', aliases: ['ie:replay-index', 'ie:reindex'])]
  #[CLI\Help(description: 'Replay stored indexing ledger rows. Use this for reindexing idempotent target events; use backfill to emit new ledger rows for content that was never captured.')]
  #[CLI\Option(name: 'target', description: 'Optional target system ID such as fedora or blazegraph.')]
  #[CLI\Option(name: 'entity-type', description: 'Optional Drupal entity type filter.')]
  #[CLI\Option(name: 'entity-id', description: 'Optional source entity ID filter.')]
  #[CLI\Option(name: 'operation', description: 'Optional trigger event filter such as insert, update, or delete.')]
  #[CLI\Option(name: 'status', description: 'Optional comma-separated ledger statuses to replay, such as completed,failed,abandoned.')]
  #[CLI\Option(name: 'limit', description: 'Maximum matching rows to inspect in one run.')]
  #[CLI\Option(name: 'dry-run', description: 'Show matching rows without replaying them.')]
  public function replayIndex(
    array $options = [
      'target' => NULL,
      'entity-type' => NULL,
      'entity-id' => NULL,
      'operation' => NULL,
      'status' => NULL,
      'limit' => 100,
      'dry-run' => FALSE,
    ],
  ): int {
    $statuses = array_values(array_filter(array_map(
      'trim',
      explode(',', (string) ($options['status'] ?? ''))
    )));
    $results = $this->indexLedgerReplay->replay([
      'target_id' => $options['target'] ?? NULL,
      'entity_type' => $options['entity-type'] ?? NULL,
      'entity_id' => $options['entity-id'] ?? NULL,
      'operation' => $options['operation'] ?? NULL,
      'statuses' => $statuses,
    ], max(1, (int) ($options['limit'] ?? 100)), (bool) ($options['dry-run'] ?? FALSE));

    if ($results === []) {
      $this->output()->writeln('No matching indexing ledger rows found.');
      return Command::SUCCESS;
    }

    foreach ($results as $result) {
      $this->output()->writeln(sprintf(
        '%s [record=%d target=%s entity=%s:%d op=%s ledger=%s] %s',
        strtoupper((string) $result['status']),
        (int) $result['id'],
        (string) $result['target'],
        (string) $result['entity_type'],
        (int) $result['entity_id'],
        (string) $result['operation'],
        (string) $result['ledger_status'],
        (string) $result['message'],
      ));
    }

    $failed = count(array_filter(
      $results,
      static fn (array $result): bool => ($result['status'] ?? '') === 'failed',
    ));

    return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
  }

}
