<?php

namespace Drupal\islandora_events\Service;

/**
 * Merges defaults from all tagged derivative runner providers.
 */
final class DerivativeRunnerDefaultsResolver {

  /**
   * Constructs the defaults resolver.
   *
   * @param iterable<\Drupal\islandora_events\Service\DerivativeRunnerDefaultsProviderInterface> $providers
   *   Tagged defaults providers.
   */
  public function __construct(
    private iterable $providers,
  ) {}

  /**
   * Returns merged runner defaults keyed by queue name.
   *
   * Later providers override earlier ones for the same queue key.
   *
   * @return array<string, array<string, mixed>>
   *   Queue defaults.
   */
  public function getDefaults(): array {
    $defaults = [];

    foreach ($this->providers as $provider) {
      foreach ($provider->getDefaults() as $queue => $runnerDefaults) {
        if (!is_string($queue) || !is_array($runnerDefaults)) {
          continue;
        }
        $defaults[$queue] = array_replace($defaults[$queue] ?? [], $runnerDefaults);
      }
    }

    return $defaults;
  }

}
