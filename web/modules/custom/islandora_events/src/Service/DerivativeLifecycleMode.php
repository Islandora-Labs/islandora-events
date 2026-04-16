<?php

namespace Drupal\islandora_events\Service;

/**
 * Controls which ledger lifecycle transitions the runner should manage.
 */
enum DerivativeLifecycleMode {

  // The runner owns the full synchronous lifecycle.
  case ManageAll;

  // The record was already claimed; only terminal failure is runner-managed.
  case ManageFailureOnly;

  // Messenger worker events own all lifecycle projection updates.
  case Externalized;

  /**
   * Returns whether the runner should mark the record in progress.
   */
  public function shouldMarkProcessing(): bool {
    return $this === self::ManageAll;
  }

  /**
   * Returns whether the runner should mark the record completed.
   */
  public function shouldMarkCompleted(): bool {
    return $this === self::ManageAll;
  }

  /**
   * Returns whether the runner should mark the record failed.
   */
  public function shouldMarkFailed(): bool {
    return $this !== self::Externalized;
  }

}
