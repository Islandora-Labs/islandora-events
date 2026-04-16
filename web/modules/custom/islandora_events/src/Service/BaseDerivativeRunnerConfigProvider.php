<?php

namespace Drupal\islandora_events\Service;

/**
 * Owns the base module's derivative runner queue config.
 */
final class BaseDerivativeRunnerConfigProvider implements DerivativeRunnerConfigProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function getConfigName(): string {
    return 'islandora_events.derivative_runners';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return 'Base derivative queue runners';
  }

}
