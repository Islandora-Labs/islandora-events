<?php

namespace Drupal\islandora_events\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\islandora\IslandoraUtils;

/**
 * Defers media derivative-file reactions until request or command shutdown.
 */
class DeferredMediaFileReactionQueue {

  /**
   * Pending media entities keyed by object ID.
   *
   * @var array<int, \Drupal\Core\Entity\EntityInterface>
   */
  private array $pending = [];

  /**
   * Constructs the queue.
   */
  public function __construct(
    private IslandoraUtils $islandoraUtils,
  ) {}

  /**
   * Queues a media entity for derivative-file reaction processing.
   */
  public function defer(EntityInterface $media): void {
    $this->pending[spl_object_id($media)] = $media;
  }

  /**
   * Flushes all pending derivative-file reactions.
   */
  public function flush(): void {
    if ($this->pending === []) {
      return;
    }

    $pending = $this->pending;
    $this->pending = [];

    foreach ($pending as $media) {
      $this->islandoraUtils->executeMediaReactions(
        '\Drupal\islandora\Plugin\ContextReaction\DerivativeFileReaction',
        $media
      );
    }
  }

}
