<?php

namespace Drupal\islandora_events_backfill\Annotation;

use Drupal\Component\Annotation\Plugin;

/**
 * Defines a derivative scanner plugin annotation.
 *
 * @Annotation
 */
class DerivativeScanner extends Plugin {

  /**
   * The plugin ID.
   *
   * @var string
   */
  public $id;

  /**
   * The label.
   *
   * @var \Drupal\Core\Annotation\Translation
   */
  public $label;

  /**
   * The description.
   *
   * @var \Drupal\Core\Annotation\Translation
   */
  public $description;

  /**
   * Action ID.
   *
   * @var string
   */
  public $action;

  /**
   * Entity type.
   *
   * @var string
   */
  public $entity_type;

  /**
   * Event type.
   *
   * @var string
   */
  public $event_type;

  /**
   * Scan frequency in hours.
   *
   * @var int
   */
  public $frequency;

  /**
   * Priority.
   *
   * @var int
   */
  public $priority;

}
