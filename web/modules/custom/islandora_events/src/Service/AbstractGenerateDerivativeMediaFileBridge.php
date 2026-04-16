<?php

namespace Drupal\islandora_events\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\islandora\Plugin\Action\AbstractGenerateDerivativeMediaFile;

/**
 * Typed bridge for Islandora derivative plugins based on media-file actions.
 */
abstract class AbstractGenerateDerivativeMediaFileBridge extends AbstractGenerateDerivativeMediaFile {

  /**
   * Extracts derivative data using the Islandora media-file base-class.
   *
   * @return array<string, mixed>
   *   Derivative payload data.
   */
  public static function extract(
    AbstractGenerateDerivativeMediaFile $plugin,
    EntityInterface $entity,
  ): array {
    $data = $plugin->generateData($entity);
    if (!is_array($data)) {
      throw new \RuntimeException('Derivative action did not generate an array payload.');
    }

    return $data;
  }

}
