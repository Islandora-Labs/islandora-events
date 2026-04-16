<?php

namespace Drupal\islandora_events_backfill\Plugin;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Interface for derivative scanner plugins.
 */
interface DerivativeScannerInterface extends PluginInspectionInterface {

  /**
   * Returns the human-readable scanner label.
   */
  public function getLabel(): string;

  /**
   * Returns the scanner description.
   */
  public function getDescription(): string;

  /**
   * Returns the Islandora action ID to queue.
   */
  public function getAction(): string;

  /**
   * Returns the source entity type handled by the scanner.
   */
  public function getEntityType(): string;

  /**
   * Returns the event type recorded on queued ledger rows.
   */
  public function getEventType(): string;

  /**
   * Returns the scan frequency in hours.
   */
  public function getFrequency(): int;

  /**
   * Returns the scanner priority.
   */
  public function getPriority(): int;

  /**
   * Finds entity IDs missing the derivative represented by this scanner.
   *
   * @return int[]
   *   Entity IDs missing the expected derivative.
   */
  public function findMissingDerivatives(): array;

}
