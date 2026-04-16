<?php

namespace Drupal\islandora_events\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\sm_workers\ExecutionStrategy\WorkerRuntimeDefaults;

/**
 * Resolves effective runner configuration for one derivative queue.
 */
class DerivativeRunnerConfigResolver {

  /**
   * Constructs the resolver.
   */
  public function __construct(
    private ConfigFactoryInterface $configFactory,
    private WorkerRuntimeDefaults $workerRuntimeDefaults,
    private ?DerivativeRunnerDefaultsResolver $defaultsResolver = NULL,
    private ?DerivativeRunnerConfigRegistry $configRegistry = NULL,
  ) {}

  /**
   * Resolves queue runner configuration from settings and command overrides.
   *
   * @return array<string, mixed>
   *   Effective runner configuration.
   */
  public function resolve(string $queue, array $overrides = []): array {
    $configuredRunners = $this->getConfiguredRunners();
    $runner = is_array($configuredRunners[$queue] ?? NULL) ? $configuredRunners[$queue] : [];
    $defaults = $this->defaultsResolver?->getDefaults() ?? [];

    return $overrides + $runner + ($defaults[$queue] ?? []) + [
      'queue' => $queue,
      'execution_mode' => 'http',
      'endpoint' => '',
      'command' => '',
      'config_path' => '',
      'cmd' => '',
      'args' => [],
      'env_vars' => [],
      'forward_auth' => $this->workerRuntimeDefaults->defaultForwardAuth(),
      'working_directory' => '',
      'timeout' => $this->workerRuntimeDefaults->defaultTimeoutSeconds(),
      'heartbeat_interval' => $this->heartbeatIntervalSeconds(),
      'write_back' => TRUE,
    ];
  }

  /**
   * Normalizes YAML/env/CLI boolean-like values.
   */
  public function normalizeBoolean(mixed $value): bool {
    if (is_bool($value)) {
      return $value;
    }

    if (is_string($value)) {
      return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], TRUE);
    }

    if (is_int($value)) {
      return $value !== 0;
    }

    return FALSE;
  }

  /**
   * Returns configured derivative runners.
   *
   * @return array<string, array<string, mixed>>
   *   Queue runner mapping.
   */
  private function getConfiguredRunners(): array {
    return $this->configRegistry?->getConfiguredRunners($this->configFactory) ?? [];
  }

  /**
   * Returns the shared stale-claim heartbeat interval in seconds.
   */
  private function heartbeatIntervalSeconds(): int {
    $value = (int) $this->configFactory->get('sm_ledger.settings')
      ->get('recovery.heartbeat_interval_seconds');
    return max(1, $value ?: 30);
  }

}
