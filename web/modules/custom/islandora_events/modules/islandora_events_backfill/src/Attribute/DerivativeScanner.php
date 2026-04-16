<?php

namespace Drupal\islandora_events_backfill\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a derivative scanner plugin attribute.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class DerivativeScanner extends Plugin {

  /**
   * Constructs a derivative scanner attribute.
   */
  public function __construct(
    public readonly string $id,
    public readonly ?TranslatableMarkup $label = NULL,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly string $action = '',
    public readonly string $entity_type = '',
    public readonly string $event_type = '',
    public readonly int $frequency = 24,
    public readonly int $priority = 0,
  ) {}

}
