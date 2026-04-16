<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events_fcrepo\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\islandora\MediaSource\MediaSourceService;
use Drupal\islandora_events_fcrepo\Index\FcrepoIndexPayloadMetadataProvider;
use Drupal\media\MediaInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for Fedora/fcrepo payload metadata generation.
 */
final class FcrepoIndexPayloadMetadataProviderUnitTest extends UnitTestCase {

  /**
   * Tests non-Fedora targets receive no extra metadata.
   */
  public function testBuildMetadataSkipsNonFedoraTargets(): void {
    $provider = new FcrepoIndexPayloadMetadataProvider(
      $this->createMock(MediaSourceService::class),
      $this->createMock(FileUrlGeneratorInterface::class),
    );

    $entity = $this->createMock(FileInterface::class);
    $entity->method('uuid')->willReturn('file-uuid');

    static::assertSame([], $provider->buildMetadata($entity, 'update', 'blazegraph'));
  }

  /**
   * Tests file entities contribute Fedora UUID and external URL metadata.
   */
  public function testBuildMetadataForFileEntity(): void {
    $fileUrlGenerator = $this->createMock(FileUrlGeneratorInterface::class);
    $fileUrlGenerator->expects($this->once())
      ->method('generateAbsoluteString')
      ->with('public://example.txt')
      ->willReturn('http://example.com/sites/default/files/example.txt');

    $provider = new FcrepoIndexPayloadMetadataProvider(
      $this->createMock(MediaSourceService::class),
      $fileUrlGenerator,
    );

    $file = $this->createMock(FileInterface::class);
    $file->method('uuid')->willReturn('file-uuid');
    $file->method('getFileUri')->willReturn('public://example.txt');
    $file->method('toUrl')->willThrowException(new \RuntimeException('skip canonical'));

    $metadata = $provider->buildMetadata($file, 'update', 'fedora');

    static::assertSame('file-uuid', $metadata['fcrepo_resource_uuid']);
    static::assertSame(
      'http://example.com/sites/default/files/example.txt',
      $metadata['external_url'],
    );
    static::assertArrayNotHasKey('jsonld_url', $metadata);
  }

  /**
   * Tests media source-field UUIDs override the default target UUID.
   */
  public function testBuildMetadataForMediaEntityUsesReferencedSourceUuid(): void {
    $mediaSourceService = $this->createMock(MediaSourceService::class);
    $mediaSourceService->expects($this->once())
      ->method('getSourceFieldName')
      ->with('image')
      ->willReturn('field_media_file');

    $provider = new FcrepoIndexPayloadMetadataProvider(
      $mediaSourceService,
      $this->createMock(FileUrlGeneratorInterface::class),
    );

    $source = $this->createMock(FileInterface::class);
    $source->method('uuid')->willReturn('file-uuid');

    $field = new class($source) {

      /**
       * Constructs the field wrapper.
       */
      public function __construct(
        public mixed $entity,
      ) {}

      /**
       * Returns whether the field is empty.
       */
      public function isEmpty(): bool {
        return FALSE;
      }

    };

    $media = $this->createMock(MediaInterface::class);
    $media->method('uuid')->willReturn('media-uuid');
    $media->method('bundle')->willReturn('image');
    $media->method('hasField')->with('field_media_file')->willReturn(TRUE);
    $media->method('get')->with('field_media_file')->willReturn($field);
    $media->method('toUrl')->willThrowException(new \RuntimeException('skip canonical'));

    $metadata = $provider->buildMetadata($media, 'update', 'fedora');

    static::assertSame('field_media_file', $metadata['source_field']);
    static::assertSame('file-uuid', $metadata['fcrepo_resource_uuid']);
  }

  /**
   * Tests revision-backed updates request Fedora version creation.
   */
  public function testBuildMetadataMarksRevisionBackedUpdatesForVersioning(): void {
    $provider = new FcrepoIndexPayloadMetadataProvider(
      $this->createMock(MediaSourceService::class),
      $this->createMock(FileUrlGeneratorInterface::class),
    );

    $original = $this->createMock(RevisionableTestEntityInterface::class);
    $original->method('getRevisionId')->willReturn(4);

    $entity = $this->createMock(RevisionableTestEntityInterface::class);
    $entity->method('uuid')->willReturn('node-uuid');
    $entity->method('getRevisionId')->willReturn(5);
    $entity->method('getOriginal')->willReturn($original);
    $entity->method('toUrl')->willThrowException(new \RuntimeException('skip canonical'));

    $metadata = $provider->buildMetadata($entity, 'update', 'fedora');

    static::assertTrue($metadata['create_version']);
    static::assertSame('5', $metadata['revision_id']);
  }

}

/**
 * Test-only stub combining entity and revision methods for the provider.
 */
interface RevisionableTestEntityInterface extends EntityInterface, RevisionableInterface {

}
