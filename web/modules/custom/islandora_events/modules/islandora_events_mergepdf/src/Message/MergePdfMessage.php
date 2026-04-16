<?php

namespace Drupal\islandora_events_mergepdf\Message;

/**
 * Symfony Messenger message for mergepdf processing.
 */
class MergePdfMessage {

  /**
   * Constructs a new MergePdfMessage.
   */
  public function __construct(
    public string $parentEntityType,
    public int $parentEntityId,
    public string $action = 'mergepdf',
  ) {}

}
