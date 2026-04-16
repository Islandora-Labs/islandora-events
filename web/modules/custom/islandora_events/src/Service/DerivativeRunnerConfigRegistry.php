<?php

namespace Drupal\islandora_events\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Merges derivative runner config from all tagged config owners.
 */
final class DerivativeRunnerConfigRegistry {

  /**
   * Constructs the registry.
   *
   * @param iterable<\Drupal\islandora_events\Service\DerivativeRunnerConfigProviderInterface> $providers
   *   Tagged config providers.
   */
  public function __construct(
    private iterable $providers,
  ) {}

  /**
   * Returns all registered providers.
   *
   * @return \Drupal\islandora_events\Service\DerivativeRunnerConfigProviderInterface[]
   *   Providers in registration order.
   */
  public function all(): array {
    $providers = [];

    foreach ($this->providers as $provider) {
      if ($provider instanceof DerivativeRunnerConfigProviderInterface) {
        $providers[] = $provider;
      }
    }

    return $providers;
  }

  /**
   * Returns merged queue config from all provider-owned config objects.
   *
   * @return array<string, array<string, mixed>>
   *   Queue config keyed by queue name.
   */
  public function getConfiguredRunners(ConfigFactoryInterface $configFactory): array {
    $configured = [];

    foreach ($this->all() as $provider) {
      $values = $configFactory->get($provider->getConfigName())->get('runners');
      if (!is_array($values)) {
        continue;
      }

      foreach ($values as $queue => $runner) {
        if (!is_string($queue) || !is_array($runner)) {
          continue;
        }

        $configured[$queue] = array_replace($configured[$queue] ?? [], $runner);
      }
    }

    return $configured;
  }

}
