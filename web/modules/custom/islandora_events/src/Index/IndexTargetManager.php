<?php

namespace Drupal\islandora_events\Index;

/**
 * Resolves and filters configured index targets.
 */
class IndexTargetManager {

  /**
   * Indexed targets.
   *
   * @var array<string, \Drupal\islandora_events\Index\IndexTargetInterface>
   */
  private array $targets = [];

  /**
   * Constructs a target manager.
   *
   * @param iterable<\Drupal\islandora_events\Index\IndexTargetInterface> $targets
   *   Tagged target services.
   */
  public function __construct(iterable $targets) {
    foreach ($targets as $target) {
      $this->targets[$target->getTargetId()] = $target;
    }
  }

  /**
   * Gets enabled target IDs for a source event.
   *
   * @return string[]
   *   Target IDs.
   */
  public function getEnabledTargetIdsFor(string $entityType, string $eventType): array {
    $ids = [];

    foreach ($this->targets as $id => $target) {
      if ($target->isEnabled() && $target->supports($entityType, $eventType)) {
        $ids[] = $id;
      }
    }

    return $ids;
  }

  /**
   * Gets a target by ID.
   */
  public function getTarget(string $targetId): ?IndexTargetInterface {
    return $this->targets[$targetId] ?? NULL;
  }

  /**
   * Gets all registered targets keyed by target ID.
   *
   * @return array<string, \Drupal\islandora_events\Index\IndexTargetInterface>
   *   Registered targets.
   */
  public function all(): array {
    return $this->targets;
  }

}
