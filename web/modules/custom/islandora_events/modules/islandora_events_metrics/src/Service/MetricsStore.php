<?php

declare(strict_types=1);

namespace Drupal\islandora_events_metrics\Service;

use Drupal\Core\State\StateInterface;

/**
 * Stores numeric counters for Prometheus-style metrics.
 */
final class MetricsStore {

  private const STATE_KEY = 'islandora_events_metrics.counters';

  /**
   * Constructs the metrics store.
   */
  public function __construct(
    private StateInterface $state,
  ) {}

  /**
   * Adds to a labeled counter.
   *
   * @param string $name
   *   Metric name.
   * @param array<string, string> $labels
   *   Label values.
   * @param float $amount
   *   Value to add.
   */
  public function increment(string $name, array $labels = [], float $amount = 1.0): void {
    $counters = $this->all();
    $key = $this->buildKey($name, $labels);
    $counters[$key] = (float) ($counters[$key] ?? 0) + $amount;
    $this->state->set(self::STATE_KEY, $counters);
  }

  /**
   * Returns all counter values.
   *
   * @return array<string, float>
   *   Counters keyed by flattened metric key.
   */
  public function all(): array {
    $counters = $this->state->get(self::STATE_KEY, []);
    return is_array($counters) ? $counters : [];
  }

  /**
   * Builds a stable key for one labeled metric.
   *
   * @param string $name
   *   Metric name.
   * @param array<string, string> $labels
   *   Label values.
   */
  private function buildKey(string $name, array $labels): string {
    ksort($labels);
    return $name . '|' . http_build_query($labels, '', '&', PHP_QUERY_RFC3986);
  }

}
