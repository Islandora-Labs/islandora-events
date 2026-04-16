<?php

namespace Drupal\islandora_events_fcrepo\Index;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\file\FileInterface;
use Drupal\islandora\MediaSource\MediaSourceService;
use Drupal\islandora_events\Index\IndexPayloadMetadataProviderInterface;
use Drupal\media\MediaInterface;

/**
 * Adds Fedora/fcrepo-specific payload metadata to index records.
 */
final class FcrepoIndexPayloadMetadataProvider implements IndexPayloadMetadataProviderInterface {

  /**
   * Constructs the provider.
   */
  public function __construct(
    private MediaSourceService $mediaSourceService,
    private FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function buildMetadata(EntityInterface $entity, string $operation, string $targetId): array {
    if ($targetId !== 'fedora') {
      return [];
    }

    $metadata = [
      'fcrepo_resource_uuid' => (string) $entity->uuid(),
    ];

    $canonicalUrl = $this->buildCanonicalUrl($entity);
    if ($canonicalUrl !== '') {
      $metadata['canonical_url'] = $canonicalUrl;
      $metadata['jsonld_url'] = $this->appendFormatQuery($canonicalUrl, 'jsonld');
      $metadata['json_url'] = $this->appendFormatQuery($canonicalUrl, 'json');
    }

    if ($entity instanceof MediaInterface) {
      $sourceField = $this->mediaSourceService->getSourceFieldName($entity->bundle());
      if ($sourceField !== '') {
        $metadata['source_field'] = $sourceField;
        if (
          $entity->hasField($sourceField)
          && !$entity->get($sourceField)->isEmpty()
        ) {
          $target = $entity->get($sourceField)->entity;
          if ($target instanceof EntityInterface) {
            $metadata['fcrepo_resource_uuid'] = (string) $target->uuid();
          }
        }
      }
    }

    if ($entity instanceof FileInterface) {
      $metadata['external_url'] = $this->fileUrlGenerator->generateAbsoluteString($entity->getFileUri());
    }

    if ($this->shouldCreateVersion($entity, $operation)) {
      $metadata['create_version'] = TRUE;
      $metadata['revision_id'] = (string) $entity->getRevisionId();
    }

    return $metadata;
  }

  /**
   * Returns TRUE when this update should create a Fedora memento.
   */
  private function shouldCreateVersion(EntityInterface $entity, string $operation): bool {
    if ($operation !== 'update' || !$entity instanceof RevisionableInterface) {
      return FALSE;
    }

    $original = method_exists($entity, 'getOriginal') ? $entity->getOriginal() : NULL;
    if (!$original instanceof RevisionableInterface) {
      return FALSE;
    }

    $revisionId = (int) $entity->getRevisionId();
    $originalRevisionId = (int) $original->getRevisionId();
    if ($revisionId <= 0 || $originalRevisionId <= 0) {
      return FALSE;
    }

    return $revisionId !== $originalRevisionId;
  }

  /**
   * Builds an absolute canonical URL when Drupal can resolve one.
   */
  private function buildCanonicalUrl(EntityInterface $entity): string {
    try {
      $generated = $entity->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE);
      if ($generated instanceof GeneratedUrl) {
        return $generated->getGeneratedUrl();
      }
      return (string) $generated;
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Adds or replaces the _format query argument on a URL.
   */
  private function appendFormatQuery(string $url, string $format): string {
    if ($url === '') {
      return '';
    }

    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . '_format=' . rawurlencode($format);
  }

}
