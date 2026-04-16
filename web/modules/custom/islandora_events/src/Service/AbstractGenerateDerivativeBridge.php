<?php

namespace Drupal\islandora_events\Service;

use Drupal\islandora\Plugin\Action\AbstractGenerateDerivative;
use Drupal\Core\Entity\EntityInterface;

/**
 * Typed bridge for Islandora derivative plugins.
 *
 * Supports plugins based on AbstractGenerateDerivative.
 */
abstract class AbstractGenerateDerivativeBridge extends AbstractGenerateDerivative {

  /**
   * Extracts derivative data using the Islandora base-class contract.
   *
   * @return array<string, mixed>
   *   Derivative payload data.
   */
  public static function extract(
    AbstractGenerateDerivative $plugin,
    EntityInterface $entity,
  ): array {
    $data = $plugin->generateData($entity);
    if (!is_array($data)) {
      throw new \RuntimeException('Derivative action did not generate an array payload.');
    }

    return $data;
  }

}
