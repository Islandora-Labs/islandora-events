<?php

namespace Drupal\islandora_events_blazegraph\Index;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\islandora_events\Index\IndexPayloadMetadataProviderInterface;

/**
 * Adds Blazegraph-specific payload metadata to index records.
 */
final class BlazegraphIndexPayloadMetadataProvider implements IndexPayloadMetadataProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function buildMetadata(EntityInterface $entity, string $operation, string $targetId): array {
    if ($targetId !== 'blazegraph') {
      return [];
    }

    $canonicalUrl = $this->buildCanonicalUrl($entity);
    if ($canonicalUrl === '') {
      return [];
    }

    return [
      'canonical_url' => $canonicalUrl,
      'subject_url' => $canonicalUrl,
      'jsonld_url' => $this->appendFormatQuery($canonicalUrl, 'jsonld'),
    ];
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
    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . '_format=' . rawurlencode($format);
  }

}
