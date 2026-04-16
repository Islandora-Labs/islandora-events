<?php

namespace Drupal\islandora_events\Service;

use Psr\Log\LoggerInterface;

/**
 * Normalizes stored derivative payloads and transport metadata.
 */
class DerivativePayloadNormalizer {

  /**
   * Constructs the normalizer.
   */
  public function __construct(
    private LoggerInterface $logger,
  ) {}

  /**
   * Decodes stored transport metadata.
   *
   * @return array<string, mixed>
   *   Decoded metadata.
   */
  public function decodeTransportMetadata(string $metadata): array {
    if ($metadata === '') {
      return [];
    }

    try {
      $decoded = json_decode($metadata, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $e) {
      $this->logger->warning('Invalid transport metadata JSON on derivative record.', [
        'error' => $e->getMessage(),
      ]);
      return [];
    }

    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Backfills derivative payload fields needed by local runner modes.
   *
   * @return array{0: string, 1: array<string, mixed>}
   *   Normalized payload JSON and content array.
   */
  public function normalizeDerivativePayload(
    string $payload,
    array $content,
    ?array $event = NULL,
    ?string $fallbackSourceMimeType = NULL,
  ): array {
    $sourceMimeType = trim((string) ($content['source_mimetype'] ?? ''));
    if ($sourceMimeType !== '') {
      return [$payload, $content];
    }

    if ($event === NULL) {
      try {
        $event = json_decode($payload, TRUE, 512, JSON_THROW_ON_ERROR);
      }
      catch (\JsonException $e) {
        $this->logger->warning('Unable to decode derivative payload while inferring source MIME type.', [
          'error' => $e->getMessage(),
        ]);
        return [$payload, $content];
      }
    }

    if (!is_array($event)) {
      return [$payload, $content];
    }

    $sourceMimeType = $this->inferSourceMimeTypeFromEvent($event, (string) ($content['source_uri'] ?? ''));
    if ($sourceMimeType === '') {
      $sourceMimeType = trim((string) $fallbackSourceMimeType);
    }
    if ($sourceMimeType === '') {
      return [$payload, $content];
    }

    $event['attachment']['content']['source_mimetype'] = $sourceMimeType;
    $content['source_mimetype'] = $sourceMimeType;

    return [
      json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
      $content,
    ];
  }

  /**
   * Infers the source mimetype from ActivityStreams object URLs.
   */
  private function inferSourceMimeTypeFromEvent(array $event, string $sourceUri): string {
    $urls = $event['object']['url'] ?? [];
    if (!is_array($urls)) {
      return '';
    }

    foreach ($urls as $url) {
      if (!is_array($url)) {
        continue;
      }
      if (($url['href'] ?? '') === $sourceUri && !empty($url['mediaType'])) {
        return trim((string) $url['mediaType']);
      }
    }

    foreach ($urls as $url) {
      if (!is_array($url)) {
        continue;
      }
      if (($url['rel'] ?? '') === 'describes' && !empty($url['mediaType'])) {
        return trim((string) $url['mediaType']);
      }
    }

    return '';
  }

}
