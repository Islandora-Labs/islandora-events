<?php

namespace Drupal\islandora_events\Service;

/**
 * Provides built-in derivative runner defaults.
 */
interface DerivativeRunnerDefaultsProviderInterface {

  /**
   * Returns queue runner defaults keyed by queue name.
   *
   * @return array<string, array<string, mixed>>
   *   Built-in runner definitions.
   */
  public function getDefaults(): array;

}
