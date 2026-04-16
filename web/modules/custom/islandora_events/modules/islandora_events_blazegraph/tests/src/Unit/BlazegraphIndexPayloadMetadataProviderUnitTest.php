<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events_blazegraph\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\islandora_events_blazegraph\Index\BlazegraphIndexPayloadMetadataProvider;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for Blazegraph payload metadata generation.
 */
final class BlazegraphIndexPayloadMetadataProviderUnitTest extends UnitTestCase {

  /**
   * Tests non-Blazegraph targets receive no extra metadata.
   */
  public function testBuildMetadataSkipsNonBlazegraphTargets(): void {
    $provider = new BlazegraphIndexPayloadMetadataProvider();
    $entity = $this->createMock(EntityInterface::class);

    static::assertSame([], $provider->buildMetadata($entity, 'update', 'fedora'));
  }

  /**
   * Tests canonical URLs are persisted as subject and JSON-LD metadata.
   */
  public function testBuildMetadataUsesCanonicalUrl(): void {
    $provider = new BlazegraphIndexPayloadMetadataProvider();

    $entity = $this->createMock(EntityInterface::class);
    $entity->method('toUrl')->willReturn(new class {

      /**
       * Returns a stub URL string.
       */
      public function toString(bool $collect = FALSE): string {
        return 'http://example.com/node/11';
      }

    });

    $metadata = $provider->buildMetadata($entity, 'update', 'blazegraph');

    static::assertSame('http://example.com/node/11', $metadata['subject_url']);
    static::assertSame('http://example.com/node/11?_format=jsonld', $metadata['jsonld_url']);
  }

}
