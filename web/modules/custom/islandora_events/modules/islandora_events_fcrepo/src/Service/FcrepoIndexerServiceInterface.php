<?php

namespace Drupal\islandora_events_fcrepo\Service;

/**
 * Interface for Fedora indexer operations.
 */
interface FcrepoIndexerServiceInterface {

  /**
   * Saves a Drupal RDF resource into Fedora.
   */
  public function saveNode(
    string $uuid,
    string $jsonldUrl,
    string $fedoraBaseUrl,
    ?string $authorization = NULL,
  ): void;

  /**
   * Saves a media-described Fedora resource using the media JSON endpoint.
   */
  public function saveMedia(
    string $sourceField,
    string $jsonUrl,
    string $fedoraBaseUrl,
    ?string $authorization = NULL,
  ): void;

  /**
   * Creates a Fedora version for a Drupal RDF resource.
   */
  public function createVersion(
    string $uuid,
    string $fedoraBaseUrl,
    ?string $authorization = NULL,
    ?string $label = NULL,
  ): void;

  /**
   * Creates a Fedora version for a media-described Fedora resource.
   */
  public function createMediaVersion(
    string $sourceField,
    string $jsonUrl,
    string $fedoraBaseUrl,
    ?string $authorization = NULL,
    ?string $label = NULL,
  ): void;

  /**
   * Saves an external file resource in Fedora.
   */
  public function saveExternal(
    string $uuid,
    string $externalUrl,
    string $fedoraBaseUrl,
    ?string $authorization = NULL,
  ): void;

  /**
   * Deletes a Fedora resource for a Drupal UUID.
   */
  public function deleteResource(
    string $uuid,
    string $fedoraBaseUrl,
    ?string $authorization = NULL,
  ): void;

}
