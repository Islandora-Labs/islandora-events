<?php

namespace Drupal\islandora_events\Service;

use Drupal\islandora\Plugin\Action\AbstractGenerateDerivative;
use Drupal\islandora\Plugin\Action\AbstractGenerateDerivativeMediaFile;
use Drupal\Core\Entity\EntityInterface;

/**
 * Extracts derivative payload data from supported Islandora action plugins.
 */
class DerivativeActionDataExtractor {

  /**
   * Extracts derivative payload data from one action plugin.
   *
   * @return array<string, mixed>
   *   Derivative payload data.
   */
  public function extract(object $plugin, EntityInterface $entity): array {
    if ($plugin instanceof DerivativeActionDataProviderInterface) {
      return $plugin->generateDerivativeData($entity);
    }

    if (class_exists('\Drupal\islandora\Plugin\Action\AbstractGenerateDerivativeMediaFile')
      && $plugin instanceof AbstractGenerateDerivativeMediaFile) {
      return AbstractGenerateDerivativeMediaFileBridge::extract($plugin, $entity);
    }

    if (class_exists('\Drupal\islandora\Plugin\Action\AbstractGenerateDerivative')
      && $plugin instanceof AbstractGenerateDerivative) {
      return AbstractGenerateDerivativeBridge::extract($plugin, $entity);
    }

    throw new \RuntimeException(sprintf(
      'Derivative action plugin "%s" does not expose a supported payload-data API.',
      $plugin::class,
    ));
  }

}
