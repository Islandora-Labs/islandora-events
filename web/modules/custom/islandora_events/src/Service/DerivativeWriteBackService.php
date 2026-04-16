<?php

namespace Drupal\islandora_events\Service;

use GuzzleHttp\ClientInterface;

/**
 * Writes completed derivative output back to Drupal.
 */
class DerivativeWriteBackService {

  /**
   * Constructs the write-back service.
   */
  public function __construct(
    private ClientInterface $httpClient,
  ) {}

  /**
   * Uploads derivative output to the destination URI.
   */
  public function writeBack(
    string $destinationUri,
    string $body,
    string $contentType,
    ?string $fileUploadUri,
    string $authorization,
    int $timeout,
  ): void {
    $putHeaders = array_filter([
      'Authorization' => $authorization,
      'Content-Type' => $contentType,
      'Content-Location' => $fileUploadUri,
    ], static fn ($value): bool => $value !== NULL && $value !== '');

    $this->httpClient->request('PUT', $destinationUri, [
      'headers' => $putHeaders,
      'body' => $body,
      'timeout' => $timeout,
    ]);
  }

}
