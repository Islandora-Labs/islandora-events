<?php

declare(strict_types=1);

namespace Drupal\islandora_events_mergepdf\Service;

use Drupal\Core\State\StateInterface;

/**
 * Tracks parent entities awaiting Merge PDF reconciliation.
 */
final class MergePdfPendingStore {

  private const STATE_KEY = 'islandora_events_mergepdf.pending_parents';

  /**
   * Constructs the pending store.
   */
  public function __construct(
    private StateInterface $state,
  ) {}

  /**
   * Marks a parent entity as pending reconciliation.
   */
  public function add(string $entityType, int $entityId): void {
    $pending = $this->getRaw();
    $pending[$this->buildKey($entityType, $entityId)] = [
      'entity_type' => $entityType,
      'entity_id' => $entityId,
    ];
    $this->state->set(self::STATE_KEY, $pending);
  }

  /**
   * Removes a parent entity from the pending set.
   */
  public function remove(string $entityType, int $entityId): void {
    $pending = $this->getRaw();
    unset($pending[$this->buildKey($entityType, $entityId)]);
    $this->state->set(self::STATE_KEY, $pending);
  }

  /**
   * Returns all pending parent entities.
   *
   * @return array<int, array{entity_type: string, entity_id: int}>
   *   Pending parents.
   */
  public function all(): array {
    return array_values($this->getRaw());
  }

  /**
   * Returns the underlying keyed state array.
   *
   * @return array<string, array{entity_type: string, entity_id: int}>
   *   Pending parents keyed by stable identifier.
   */
  private function getRaw(): array {
    $pending = $this->state->get(self::STATE_KEY, []);
    return is_array($pending) ? $pending : [];
  }

  /**
   * Builds a stable key for a parent entity.
   */
  private function buildKey(string $entityType, int $entityId): string {
    return sprintf('%s:%d', $entityType, $entityId);
  }

}
