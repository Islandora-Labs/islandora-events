<?php

namespace Drupal\islandora_events_blazegraph\Service;

/**
 * Interface for Blazegraph indexer operations.
 */
interface BlazegraphIndexerServiceInterface {

  /**
   * Upserts one subject into Blazegraph from Drupal JSON-LD.
   */
  public function updateResource(
    string $jsonldUrl,
    string $subjectUrl,
    string $endpoint,
    ?string $authorization = NULL,
    string $namedGraph = '',
  ): void;

  /**
   * Deletes one subject from Blazegraph.
   */
  public function deleteResource(
    string $subjectUrl,
    string $endpoint,
    ?string $authorization = NULL,
    string $namedGraph = '',
  ): void;

}
