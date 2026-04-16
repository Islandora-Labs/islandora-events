<?php

namespace Drupal\islandora_events\Service;

use Drupal\Core\Site\Settings;

/**
 * Reads derivative command execution policy from Drupal settings.php.
 */
final class SettingsDerivativeCommandPolicy implements DerivativeCommandPolicyInterface {

  /**
   * Regex copied from Scyllaridae secure argument handling.
   */
  private const SAFE_ARG_PATTERN = '/^[a-zA-Z0-9._\-:\/@ =]+$/';

  /**
   * {@inheritdoc}
   */
  public function isExecutionEnabled(): bool {
    return (bool) $this->getConfig()['enabled'];
  }

  /**
   * {@inheritdoc}
   */
  public function isAllowedBinary(string $binary): bool {
    if ($binary === '') {
      return FALSE;
    }

    $allowed = $this->getAllowedBinaries();
    if ($allowed === []) {
      return FALSE;
    }

    return in_array($binary, $allowed, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function parsePassedArgs(string $args): array {
    $args = trim($args);
    if ($args === '') {
      return [];
    }

    $passedArgs = $this->splitShellLikeArgs($args);
    if ($this->getConfig()['allow_insecure_args']) {
      return $passedArgs;
    }

    foreach ($passedArgs as $value) {
      if (!preg_match(self::SAFE_ARG_PATTERN, $value)) {
        throw new \RuntimeException(sprintf('Invalid input for passed arg: %s', $value));
      }
    }

    return $passedArgs;
  }

  /**
   * Returns normalized command policy config from settings.php.
   *
   * @return array<string, mixed>
   *   Command policy config.
   */
  private function getConfig(): array {
    $config = Settings::get('islandora_events_derivative_command', []);
    if (!is_array($config)) {
      $config = [];
    }

    return [
      'enabled' => (bool) ($config['enabled'] ?? FALSE),
      'allow_insecure_args' => (bool) ($config['allow_insecure_args'] ?? FALSE),
      'allowed_binaries' => is_array($config['allowed_binaries'] ?? NULL) ? $config['allowed_binaries'] : [],
    ];
  }

  /**
   * Returns the allowlisted binaries.
   *
   * @return string[]
   *   Allowlisted binaries.
   */
  private function getAllowedBinaries(): array {
    $lines = $this->getConfig()['allowed_binaries'];
    $lines = array_map(static fn (mixed $line): string => trim((string) $line), $lines);
    $lines = array_filter($lines, static fn (string $line): bool => $line !== '');
    return array_values(array_unique($lines));
  }

  /**
   * Splits a shell-like argument string without invoking a shell.
   *
   * Supports whitespace separation, single and double quotes, and backslash
   * escaping. This intentionally mirrors the behavior Scyllaridae relies on
   * for `%args` parsing closely enough for safe argv construction.
   *
   * @param string $args
   *   Raw argument string.
   *
   * @return string[]
   *   Parsed argument tokens.
   */
  private function splitShellLikeArgs(string $args): array {
    $tokens = [];
    $buffer = '';
    $length = strlen($args);
    $quote = NULL;
    $escape = FALSE;

    for ($i = 0; $i < $length; $i++) {
      $char = $args[$i];

      if ($escape) {
        $buffer .= $char;
        $escape = FALSE;
        continue;
      }

      if ($char === '\\') {
        $escape = TRUE;
        continue;
      }

      if ($quote !== NULL) {
        if ($char === $quote) {
          $quote = NULL;
        }
        else {
          $buffer .= $char;
        }
        continue;
      }

      if ($char === '"' || $char === "'") {
        $quote = $char;
        continue;
      }

      if (ctype_space($char)) {
        if ($buffer !== '') {
          $tokens[] = $buffer;
          $buffer = '';
        }
        continue;
      }

      $buffer .= $char;
    }

    if ($escape) {
      $buffer .= '\\';
    }
    if ($quote !== NULL) {
      throw new \RuntimeException(sprintf('Error splitting args %s: unterminated quote.', $args));
    }
    if ($buffer !== '') {
      $tokens[] = $buffer;
    }

    return $tokens;
  }

}
