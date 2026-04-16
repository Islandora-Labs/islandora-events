<?php

declare(strict_types=1);

namespace Drupal\islandora_events_metrics\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\State\StateInterface;

/**
 * Collects Prometheus-style metrics for Islandora Events.
 */
final class MetricsCollector {

  private const SNAPSHOT_CACHE_ID = 'islandora_events_metrics:db_snapshot';
  private const SNAPSHOT_TTL = 15;

  /**
   * The event record base table.
   */
  private string $baseTable;

  /**
   * Constructs the collector.
   */
  public function __construct(
    private Connection $database,
    private StateInterface $state,
    private MetricsStore $store,
    private CacheBackendInterface $cache,
    EntityTypeManagerInterface $entityTypeManager,
    private array $transports = [],
  ) {
    $this->baseTable = (string) $entityTypeManager
      ->getDefinition('event_record')
      ->getBaseTable();
  }

  /**
   * Builds the text exposition format.
   */
  public function renderPrometheus(): string {
    $lines = [];

    $snapshot = $this->dbSnapshot();

    $lines[] = '# HELP sm_ledger_records_total Count of ledger records by status and event kind.';
    $lines[] = '# TYPE sm_ledger_records_total gauge';
    foreach ($snapshot['ledger_status_counts'] as $row) {
      $lines[] = sprintf(
        'sm_ledger_records_total{status="%s",event_kind="%s"} %d',
        $this->escapeLabel((string) $row['status']),
        $this->escapeLabel((string) $row['event_kind']),
        (int) $row['record_count'],
      );
    }

    $lines[] = '# HELP sm_ledger_processing_due_total Count of ledger rows still marked for processing by event kind.';
    $lines[] = '# TYPE sm_ledger_processing_due_total gauge';
    foreach ($snapshot['processing_due_counts'] as $row) {
      $lines[] = sprintf(
        'sm_ledger_processing_due_total{event_kind="%s"} %d',
        $this->escapeLabel((string) $row['event_kind']),
        (int) $row['record_count'],
      );
    }

    $lines[] = '# HELP islandora_events_queue_depth Current queue depth for configured drupal-sql transports.';
    $lines[] = '# TYPE islandora_events_queue_depth gauge';
    foreach ($snapshot['queue_depths'] as $transport => $depth) {
      $lines[] = sprintf(
        'islandora_events_queue_depth{transport="%s"} %d',
        $this->escapeLabel($transport),
        $depth,
      );
    }

    $lines[] = '# HELP sm_workers_circuit_breaker_state Current breaker state where 1 means open/manual_open.';
    $lines[] = '# TYPE sm_workers_circuit_breaker_state gauge';
    $lines[] = '# HELP sm_workers_circuit_breaker_consecutive_failures Current consecutive failure count by breaker.';
    $lines[] = '# TYPE sm_workers_circuit_breaker_consecutive_failures gauge';
    foreach ($this->breakerStates() as $breakerId => $breaker) {
      $status = (string) ($breaker['status'] ?? 'closed');
      $label = (string) ($breaker['label'] ?? $breakerId);
      $isOpen = in_array($status, ['open', 'manual_open'], TRUE) ? 1 : 0;
      $lines[] = sprintf(
        'sm_workers_circuit_breaker_state{breaker="%s",label="%s",status="%s"} %d',
        $this->escapeLabel($breakerId),
        $this->escapeLabel($label),
        $this->escapeLabel($status),
        $isOpen,
      );
      $lines[] = sprintf(
        'sm_workers_circuit_breaker_consecutive_failures{breaker="%s",label="%s"} %d',
        $this->escapeLabel($breakerId),
        $this->escapeLabel($label),
        (int) ($breaker['consecutive_failures'] ?? 0),
      );
    }

    $lines[] = '# HELP sm_workers_circuit_breaker_open_total Total automatic breaker opens by breaker.';
    $lines[] = '# TYPE sm_workers_circuit_breaker_open_total counter';
    $lines[] = '# HELP sm_workers_circuit_breaker_manual_open_total Total manual breaker opens by breaker.';
    $lines[] = '# TYPE sm_workers_circuit_breaker_manual_open_total counter';
    $lines[] = '# HELP sm_workers_circuit_breaker_short_circuit_total Total requests rejected by open breakers.';
    $lines[] = '# TYPE sm_workers_circuit_breaker_short_circuit_total counter';
    foreach ($this->breakerCounters() as $metric => $values) {
      foreach ($values as $breakerId => $count) {
        $lines[] = sprintf(
          '%s{breaker="%s"} %d',
          $metric,
          $this->escapeLabel($breakerId),
          $count,
        );
      }
    }

    foreach ($this->store->all() as $key => $value) {
      [$metric, $labels] = $this->parseCounterKey($key);
      $lines[] = sprintf(
        '%s%s %s',
        $metric,
        $this->renderLabels($labels),
        $this->formatNumber((float) $value),
      );
    }

    $lines[] = '';
    return implode("\n", $lines);
  }

  /**
   * Returns cached DB-backed gauge data.
   *
   * @return array<string, mixed>
   *   Snapshot arrays.
   */
  private function dbSnapshot(): array {
    $cached = $this->cache->get(self::SNAPSHOT_CACHE_ID);
    if (is_object($cached) && is_array($cached->data ?? NULL)) {
      return $cached->data;
    }

    $snapshot = [
      'ledger_status_counts' => $this->ledgerStatusCounts(),
      'processing_due_counts' => $this->processingDueCounts(),
      'queue_depths' => $this->queueDepths(),
    ];
    $this->cache->set(self::SNAPSHOT_CACHE_ID, $snapshot, time() + self::SNAPSHOT_TTL);

    return $snapshot;
  }

  /**
   * Returns queue depth for configured drupal-sql transports.
   *
   * @return array<string, int>
   *   Queue depth keyed by transport name.
   */
  private function queueDepths(): array {
    $queueNames = [];
    foreach ($this->transports as $transport => $definition) {
      if (!is_array($definition)) {
        continue;
      }
      $dsn = (string) ($definition['dsn'] ?? '');
      if (!str_starts_with($dsn, 'drupal-sql://')) {
        continue;
      }
      $queueNames[] = $transport;
    }

    if ($queueNames === [] || !$this->database->schema()->tableExists('queue')) {
      return [];
    }

    $depths = array_fill_keys($queueNames, 0);
    $query = $this->database->select('queue', 'q');
    $query->fields('q', ['name']);
    $query->addExpression('COUNT(*)', 'record_count');
    $query->condition('q.name', $queueNames, 'IN');
    $query->groupBy('q.name');

    foreach ($query->execute() as $row) {
      $depths[(string) $row->name] = (int) $row->record_count;
    }

    return $depths;
  }

  /**
   * Returns aggregate ledger counts by status and event kind.
   *
   * @return array<int, array{status: string, event_kind: string, record_count: int}>
   *   Aggregate rows.
   */
  private function ledgerStatusCounts(): array {
    $query = $this->database->select($this->baseTable, 'ier');
    $query->fields('ier', ['status', 'event_kind']);
    $query->addExpression('COUNT(*)', 'record_count');
    $query->groupBy('ier.status');
    $query->groupBy('ier.event_kind');
    $rows = [];
    foreach ($query->execute() as $row) {
      $rows[] = [
        'status' => (string) $row->status,
        'event_kind' => (string) $row->event_kind,
        'record_count' => (int) $row->record_count,
      ];
    }
    return $rows;
  }

  /**
   * Returns due-for-processing counts by event kind.
   *
   * @return array<int, array{event_kind: string, record_count: int}>
   *   Aggregate rows.
   */
  private function processingDueCounts(): array {
    $query = $this->database->select($this->baseTable, 'ier');
    $query->fields('ier', ['event_kind']);
    $query->addExpression('COUNT(*)', 'record_count');
    $query->condition('ier.needs_processing', 1);
    $query->groupBy('ier.event_kind');
    $rows = [];
    foreach ($query->execute() as $row) {
      $rows[] = [
        'event_kind' => (string) $row->event_kind,
        'record_count' => (int) $row->record_count,
      ];
    }
    return $rows;
  }

  /**
   * Returns current breaker states from Drupal state.
   *
   * @return array<string, array<string, mixed>>
   *   Breakers keyed by ID.
   */
  private function breakerStates(): array {
    $breakers = $this->state->get('sm_workers.circuit_breakers', []);
    return is_array($breakers) ? $breakers : [];
  }

  /**
   * Returns monotonic breaker counters from Drupal state.
   *
   * @return array<string, array<string, int>>
   *   Counters keyed first by metric name, then by breaker ID.
   */
  private function breakerCounters(): array {
    $counters = $this->state->get('sm_workers.circuit_breaker_metrics', []);
    $counters = is_array($counters) ? $counters : [];

    $metrics = [
      'sm_workers_circuit_breaker_open_total' => [],
      'sm_workers_circuit_breaker_manual_open_total' => [],
      'sm_workers_circuit_breaker_short_circuit_total' => [],
    ];

    foreach ($metrics as $metric => $_default) {
      $values = $counters[$metric] ?? [];
      $metrics[$metric] = is_array($values) ? array_map('intval', $values) : [];
    }

    return $metrics;
  }

  /**
   * Parses a stored counter key into metric and labels.
   *
   * @return array{0: string, 1: array<string, string>}
   *   Metric name and labels.
   */
  private function parseCounterKey(string $key): array {
    [$metric, $labelString] = array_pad(explode('|', $key, 2), 2, '');
    parse_str($labelString, $labels);
    $labels = array_map(static fn ($value): string => is_scalar($value) ? (string) $value : '', $labels);
    return [$metric, $labels];
  }

  /**
   * Renders Prometheus labels.
   *
   * @param array<string, string> $labels
   *   Label values.
   */
  private function renderLabels(array $labels): string {
    if ($labels === []) {
      return '';
    }

    ksort($labels);
    $parts = [];
    foreach ($labels as $key => $value) {
      $parts[] = sprintf('%s="%s"', $key, $this->escapeLabel($value));
    }

    return '{' . implode(',', $parts) . '}';
  }

  /**
   * Escapes a label value.
   */
  private function escapeLabel(string $value): string {
    return str_replace(["\\", "\"", "\n"], ["\\\\", "\\\"", "\\n"], $value);
  }

  /**
   * Formats one metric value in Prometheus-friendly form.
   */
  private function formatNumber(float $value): string {
    if ((float) (int) $value === $value) {
      return (string) (int) $value;
    }

    return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
  }

}
