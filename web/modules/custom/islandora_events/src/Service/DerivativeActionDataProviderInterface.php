<?php

namespace Drupal\islandora_events\Service;

use Drupal\Core\Entity\EntityInterface;

/**
 * Public contract for action plugins that can build derivative payload data.
 */
interface DerivativeActionDataProviderInterface {

  /**
   * Builds derivative payload data for one entity.
   *
   * @return array<string, mixed>
   *   Derivative payload data.
   */
  public function generateDerivativeData(EntityInterface $entity): array;

}
