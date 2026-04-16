<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events_fcrepo\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\islandora\MediaSource\MediaSourceService;
use Drupal\islandora_events\Index\IndexEventContext;
use Drupal\islandora_events_fcrepo\Index\Target\FcrepoIndexTarget;
use Drupal\islandora_events_fcrepo\Service\FcrepoIndexerServiceInterface;
use Drupal\jwt\Authentication\Provider\JwtAuth;
use Psr\Log\LoggerInterface;
use Drupal\sm_workers\Service\CircuitBreakerService;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for the direct Fedora/fcrepo target.
 */
final class FcrepoIndexTargetUnitTest extends UnitTestCase {

  /**
   * Tests delete events call the embedded delete path.
   */
  public function testProcessDeleteEvent(): void {
    $indexer = $this->createMock(FcrepoIndexerServiceInterface::class);
    $indexer->expects($this->once())
      ->method('deleteResource')
      ->with('node-uuid', 'http://fcrepo:8080/fcrepo/rest', 'Bearer token');

    $target = $this->createTarget($indexer);
    $target->process(new IndexEventContext(
      'fedora',
      'node',
      11,
      'delete',
      ['metadata' => ['fcrepo_resource_uuid' => 'node-uuid']],
    ));
  }

  /**
   * Tests media updates delegate to the embedded media save path.
   */
  public function testProcessMediaUpdate(): void {
    $indexer = $this->createMock(FcrepoIndexerServiceInterface::class);
    $indexer->expects($this->once())
      ->method('saveMedia')
      ->with(
        'field_media_file',
        'http://example.com/media/10?_format=json',
        'http://fcrepo:8080/fcrepo/rest',
        'Bearer token',
      );

    $target = $this->createTarget($indexer);
    $target->process(new IndexEventContext(
      'fedora',
      'media',
      10,
      'update',
      [
        'metadata' => [
          'source_field' => 'field_media_file',
          'json_url' => 'http://example.com/media/10?_format=json',
        ],
      ],
    ));
  }

  /**
   * Tests revision-backed media updates create Fedora versions.
   */
  public function testProcessMediaUpdateCreatesVersion(): void {
    $indexer = $this->createMock(FcrepoIndexerServiceInterface::class);
    $indexer->expects($this->once())
      ->method('saveMedia')
      ->with(
        'field_media_file',
        'http://example.com/media/10?_format=json',
        'http://fcrepo:8080/fcrepo/rest',
        'Bearer token',
      );
    $indexer->expects($this->once())
      ->method('createMediaVersion')
      ->with(
        'field_media_file',
        'http://example.com/media/10?_format=json',
        'http://fcrepo:8080/fcrepo/rest',
        'Bearer token',
        '42',
      );

    $target = $this->createTarget($indexer);
    $target->process(new IndexEventContext(
      'fedora',
      'media',
      10,
      'update',
      [
        'metadata' => [
          'source_field' => 'field_media_file',
          'json_url' => 'http://example.com/media/10?_format=json',
          'create_version' => TRUE,
          'revision_id' => '42',
        ],
      ],
    ));
  }

  /**
   * Tests file updates delegate to the embedded external-content save path.
   */
  public function testProcessFileUpdate(): void {
    $indexer = $this->createMock(FcrepoIndexerServiceInterface::class);
    $indexer->expects($this->once())
      ->method('saveExternal')
      ->with(
        'file-uuid',
        'http://example.com/sites/default/files/object.tif',
        'http://fcrepo:8080/fcrepo/rest',
        'Bearer token',
      );

    $target = $this->createTarget($indexer);
    $target->process(new IndexEventContext(
      'fedora',
      'file',
      5,
      'update',
      [
        'metadata' => [
          'fcrepo_resource_uuid' => 'file-uuid',
          'external_url' => 'http://example.com/sites/default/files/object.tif',
        ],
      ],
    ));
  }

  /**
   * Tests content updates delegate to the embedded node save path.
   */
  public function testProcessNodeUpdate(): void {
    $indexer = $this->createMock(FcrepoIndexerServiceInterface::class);
    $indexer->expects($this->once())
      ->method('saveNode')
      ->with(
        'node-uuid',
        'http://example.com/node/11?_format=jsonld',
        'http://fcrepo:8080/fcrepo/rest',
        'Bearer token',
      );

    $target = $this->createTarget($indexer);
    $target->process(new IndexEventContext(
      'fedora',
      'node',
      11,
      'update',
      [
        'entity_uuid' => 'node-uuid',
        'metadata' => [
          'jsonld_url' => 'http://example.com/node/11?_format=jsonld',
        ],
      ],
    ));
  }

  /**
   * Tests revision-backed content updates create Fedora versions.
   */
  public function testProcessNodeUpdateCreatesVersion(): void {
    $indexer = $this->createMock(FcrepoIndexerServiceInterface::class);
    $indexer->expects($this->once())
      ->method('saveNode')
      ->with(
        'node-uuid',
        'http://example.com/node/11?_format=jsonld',
        'http://fcrepo:8080/fcrepo/rest',
        'Bearer token',
      );
    $indexer->expects($this->once())
      ->method('createVersion')
      ->with(
        'node-uuid',
        'http://fcrepo:8080/fcrepo/rest',
        'Bearer token',
        '7',
      );

    $target = $this->createTarget($indexer);
    $target->process(new IndexEventContext(
      'fedora',
      'node',
      11,
      'update',
      [
        'entity_uuid' => 'node-uuid',
        'metadata' => [
          'jsonld_url' => 'http://example.com/node/11?_format=jsonld',
          'create_version' => TRUE,
          'revision_id' => '7',
        ],
      ],
    ));
  }

  /**
   * Builds the target under test with default enabled config.
   */
  private function createTarget(FcrepoIndexerServiceInterface $indexer): FcrepoIndexTarget {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['enabled', TRUE],
        ['endpoint', 'http://fcrepo:8080/fcrepo/rest'],
        ['timeout', 30],
      ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('islandora_events_fcrepo.settings')
      ->willReturn($config);

    $jwt = $this->createMock(JwtAuth::class);
    $jwt->method('generateToken')->willReturn('token');

    $breaker = $this->createMock(CircuitBreakerService::class);
    $breaker->expects($this->once())->method('ensure');
    $breaker->expects($this->once())->method('assertAllows');
    $breaker->expects($this->once())->method('recordSuccess');

    return new FcrepoIndexTarget(
      $configFactory,
      $jwt,
      $breaker,
      $indexer,
      $this->createMock(MediaSourceService::class),
      $this->createMock(FileUrlGeneratorInterface::class),
      $this->createMock(AccountProxyInterface::class),
      $this->createMock(LoggerInterface::class),
    );
  }

}
