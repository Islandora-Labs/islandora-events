<?php

namespace Drupal\islandora_events_backfill\Message;

/**
 * Requests one derivative backfill scan run for a scanner plugin.
 */
readonly class BackfillScanMessage {

  /**
   * Constructs a backfill scan message.
   */
  public function __construct(
    public string $pluginId,
  ) {}

}
