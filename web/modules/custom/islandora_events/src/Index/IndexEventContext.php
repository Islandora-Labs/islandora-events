<?php

namespace Drupal\islandora_events\Index;

use Drupal\Core\Entity\EntityInterface;

/**
 * Value object passed to index targets.
 */
class IndexEventContext {

  /**
   * Constructs a context for index processing.
   *
   * @param string $targetId
   *   Target ID.
   * @param string $entityType
   *   Source entity type.
   * @param int $entityId
   *   Source entity ID.
   * @param string $eventType
   *   Trigger event type.
   * @param array $payload
   *   Persisted event payload.
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   Loaded source entity, if available.
   */
  public function __construct(
    public string $targetId,
    public string $entityType,
    public int $entityId,
    public string $eventType,
    public array $payload,
    public ?EntityInterface $entity = NULL,
  ) {}

}
