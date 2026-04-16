<?php

namespace Drupal\islandora_events\Index;

use Drupal\Core\Entity\EntityInterface;

/**
 * Contributes target-specific metadata to stored index payloads.
 */
interface IndexPayloadMetadataProviderInterface {

  /**
   * Builds metadata for one target payload.
   *
   * @return array<string, mixed>
   *   Metadata to merge into the payload.
   */
  public function buildMetadata(EntityInterface $entity, string $operation, string $targetId): array;

}
