<?php

namespace Drupal\islandora_events\Service;

/**
 * Declares one config owner for derivative runner queue settings.
 */
interface DerivativeRunnerConfigProviderInterface {

  /**
   * Gets the config object name that owns queue definitions.
   */
  public function getConfigName(): string;

  /**
   * Gets a human-readable label for the config owner.
   */
  public function getLabel(): string;

}
