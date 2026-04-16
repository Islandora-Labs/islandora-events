<?php

declare(strict_types=1);

namespace Drupal\islandora_events\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\sm_workers\CircuitBreakerPrimerInterface;
use Drupal\sm_workers\Service\CircuitBreakerService;

/**
 * Primes Islandora-defined circuit breakers for the shared admin UI.
 */
final class IslandoraCircuitBreakerPrimer implements CircuitBreakerPrimerInterface {

  /**
   * Constructs the primer.
   */
  public function __construct(
    private ConfigFactoryInterface $configFactory,
    private DerivativeRunnerDefaultsResolver $defaultsResolver,
    private ?DerivativeRunnerConfigRegistry $configRegistry = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function prime(CircuitBreakerService $breakers): void {
    foreach (['fedora', 'blazegraph'] as $targetId) {
      $breakers->ensure('index:' . $targetId, sprintf('Index target %s', $targetId));
    }

    $configured = $this->configRegistry?->getConfiguredRunners($this->configFactory) ?? [];
    $queues = array_keys($configured);
    $queues = array_merge($queues, array_keys($this->defaultsResolver->getDefaults()));

    foreach (array_unique(array_filter($queues, 'is_string')) as $queue) {
      $breakers->ensure('derivative:' . $queue, sprintf('Derivative queue %s', $queue));
    }
  }

}
