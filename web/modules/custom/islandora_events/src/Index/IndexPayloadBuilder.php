<?php

namespace Drupal\islandora_events\Index;

use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityInterface;

/**
 * Builds a normalized payload for indexing events.
 */
class IndexPayloadBuilder {

  /**
   * Constructs the payload builder.
   */
  public function __construct(
    private iterable $metadataProviders = [],
  ) {}

  /**
   * Builds payload for indexing targets.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   Source entity.
   * @param string $operation
   *   Operation hint.
   * @param string $targetId
   *   Target integration ID.
   *
   * @return array<string, mixed>
   *   Payload array.
   */
  public function build(EntityInterface $entity, string $operation, string $targetId): array {
    $metadata = [];
    foreach ($this->metadataProviders as $provider) {
      if (!$provider instanceof IndexPayloadMetadataProviderInterface) {
        continue;
      }
      $metadata = array_replace(
        $metadata,
        $provider->buildMetadata($entity, $operation, $targetId),
      );
    }

    return [
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => (int) $entity->id(),
      'entity_uuid' => (string) $entity->uuid(),
      'operation' => $operation,
      'target' => $targetId,
      'changed' => $entity instanceof EntityChangedInterface ? (int) $entity->getChangedTime() : 0,
      'metadata' => $metadata,
    ];
  }

}
