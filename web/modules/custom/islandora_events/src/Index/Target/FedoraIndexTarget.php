<?php

namespace Drupal\islandora_events\Index\Target;

use Drupal\islandora_events\Message\FedoraIndexEventMessage;

/**
 * Fedora indexing target.
 */
class FedoraIndexTarget extends AbstractHttpIndexTarget {

  /**
   * {@inheritdoc}
   */
  public function getTargetId(): string {
    return 'fedora';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return 'Fedora';
  }

  /**
   * {@inheritdoc}
   */
  public function getMessageClass(): string {
    return FedoraIndexEventMessage::class;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigName(): string {
    return 'islandora_events_fcrepo.settings';
  }

}
