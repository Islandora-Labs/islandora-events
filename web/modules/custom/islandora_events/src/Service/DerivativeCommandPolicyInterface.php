<?php

namespace Drupal\islandora_events\Service;

/**
 * Defines the command execution policy for derivative runners.
 */
interface DerivativeCommandPolicyInterface {

  /**
   * Returns whether local command execution is enabled.
   */
  public function isExecutionEnabled(): bool;

  /**
   * Returns whether a command binary is allowlisted.
   *
   * @param string $binary
   *   Binary path or executable name.
   */
  public function isAllowedBinary(string $binary): bool;

  /**
   * Parses and validates untrusted derivative args into argv-safe tokens.
   *
   * @param string $args
   *   Raw argument string from derivative metadata.
   *
   * @return string[]
   *   Parsed argument tokens.
   */
  public function parsePassedArgs(string $args): array;

}
