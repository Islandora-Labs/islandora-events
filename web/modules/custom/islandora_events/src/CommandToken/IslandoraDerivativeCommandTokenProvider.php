<?php

declare(strict_types=1);

namespace Drupal\islandora_events\CommandToken;

use Drupal\sm_workers\CommandToken\WorkerCommandTokenProviderInterface;

/**
 * Provides Islandora derivative event tokens for command arg substitution.
 *
 * These tokens mirror the substitution variables that scyllaridae makes
 * available in cmdByMimeType args, using %token-name syntax. They are sourced
 * from the derivative event's attachment content metadata.
 *
 * Registered with the sm_workers.command_token_provider tag so that any
 * WorkerCommandBuilder::buildCommandArgv() call during derivative execution
 * automatically has access to these values.
 */
final class IslandoraDerivativeCommandTokenProvider implements WorkerCommandTokenProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function getTokens(array $context): array {
    $content = is_array($context['metadata'] ?? NULL) ? $context['metadata'] : [];

    $sourceMimeType = (string) ($content['source_mimetype'] ?? '');
    $destinationMimeType = (string) ($content['mimetype'] ?? '');
    $argsRaw = trim((string) ($content['args'] ?? ''));

    return [
      '%source-uri' => (string) ($content['source_uri'] ?? ''),
      '%destination-uri' => (string) ($content['destination_uri'] ?? ''),
      '%file-upload-uri' => (string) ($content['file_upload_uri'] ?? ''),
      '%canonical' => (string) ($content['canonical'] ?? ''),
      '%target' => (string) ($content['target'] ?? ''),
      '%source-mime-ext' => $this->mimeToExtension($sourceMimeType),
      '%destination-mime-ext' => $this->mimeToExtension($destinationMimeType),
      // %args expands to a list so a standalone '%args' arg fans out into
      // however many individual arguments the header value contains.
      '%args' => $argsRaw !== '' ? $this->parseArgs($argsRaw) : [],
    ];
  }

  /**
   * Derives a conventional file extension from a MIME type.
   *
   * Falls back to the subtype portion of the MIME string when the type is not
   * in the known map (e.g. "x-custom" from "application/x-custom").
   */
  private function mimeToExtension(string $mimeType): string {
    static $map = [
      'image/jpeg' => 'jpg',
      'image/png' => 'png',
      'image/gif' => 'gif',
      'image/tiff' => 'tif',
      'image/webp' => 'webp',
      'image/jp2' => 'jp2',
      'application/pdf' => 'pdf',
      'audio/mpeg' => 'mp3',
      'audio/ogg' => 'ogg',
      'video/mp4' => 'mp4',
      'video/ogg' => 'ogv',
      'text/plain' => 'txt',
    ];

    if (isset($map[$mimeType])) {
      return $map[$mimeType];
    }

    $parts = explode('/', $mimeType, 2);
    return strtolower($parts[1] ?? $mimeType);
  }

  /**
   * Splits a space-separated args string into individual tokens.
   *
   * Handles double-quoted substrings so values with spaces can be passed as
   * a single arg (e.g. %args from "quality 85 -filter Lanczos").
   *
   * @return list<string>
   *   Parsed arg list, empty values excluded.
   */
  private function parseArgs(string $args): array {
    $parsed = str_getcsv($args, ' ', '"', '\\');
    return array_values(array_filter($parsed, static fn (string $v): bool => $v !== ''));
  }

}
