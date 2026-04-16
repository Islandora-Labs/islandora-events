<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events_blazegraph\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\islandora_events\Index\IndexEventContext;
use Drupal\islandora_events_blazegraph\Index\Target\BlazegraphIndexTarget;
use Drupal\islandora_events_blazegraph\Service\BlazegraphIndexerServiceInterface;
use Drupal\jwt\Authentication\Provider\JwtAuth;
use Drupal\sm_workers\Service\CircuitBreakerService;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for the direct Blazegraph target.
 */
final class BlazegraphIndexTargetUnitTest extends UnitTestCase {

  /**
   * Tests update events delegate to the embedded SPARQL upsert path.
   */
  public function testProcessUpdateEvent(): void {
    $indexer = $this->createMock(BlazegraphIndexerServiceInterface::class);
    $indexer->expects($this->once())
      ->method('updateResource')
      ->with(
        'http://example.com/node/11?_format=jsonld',
        'http://example.com/node/11',
        'http://blazegraph:8080/bigdata/namespace/islandora/sparql',
        'Bearer token',
        '',
      );

    $target = $this->createTarget($indexer);
    $target->process(new IndexEventContext(
      'blazegraph',
      'node',
      11,
      'update',
      [
        'metadata' => [
          'jsonld_url' => 'http://example.com/node/11?_format=jsonld',
          'subject_url' => 'http://example.com/node/11',
        ],
      ],
    ));
  }

  /**
   * Tests delete events delegate to the embedded SPARQL delete path.
   */
  public function testProcessDeleteEvent(): void {
    $indexer = $this->createMock(BlazegraphIndexerServiceInterface::class);
    $indexer->expects($this->once())
      ->method('deleteResource')
      ->with(
        'http://example.com/node/11',
        'http://blazegraph:8080/bigdata/namespace/islandora/sparql',
        'Bearer token',
        '',
      );

    $target = $this->createTarget($indexer);
    $target->process(new IndexEventContext(
      'blazegraph',
      'node',
      11,
      'delete',
      [
        'metadata' => [
          'subject_url' => 'http://example.com/node/11',
        ],
      ],
    ));
  }

  /**
   * Tests missing persisted metadata falls back to the live entity URL.
   */
  public function testProcessUpdateEventFallsBackToEntityCanonicalUrl(): void {
    $indexer = $this->createMock(BlazegraphIndexerServiceInterface::class);
    $indexer->expects($this->once())
      ->method('updateResource')
      ->with(
        'http://example.com/node/11?_format=jsonld',
        'http://example.com/node/11',
        'http://blazegraph:8080/bigdata/namespace/islandora/sparql',
        'Bearer token',
        '',
      );

    $entity = $this->createMock(EntityInterface::class);
    $entity->method('toUrl')->with('canonical', ['absolute' => TRUE])->willReturn(new class {

      /**
       * Returns a stub URL string.
       */
      public function toString(bool $collect = FALSE): string {
        return 'http://example.com/node/11';
      }

    });

    $target = $this->createTarget($indexer);
    $target->process(new IndexEventContext(
      'blazegraph',
      'node',
      11,
      'update',
      ['metadata' => []],
      $entity,
    ));
  }

  /**
   * Tests supported source entity types match triplestore expectations.
   */
  public function testSupportsNodeMediaAndTermButNotFile(): void {
    $target = $this->createTarget($this->createMock(BlazegraphIndexerServiceInterface::class));

    static::assertTrue($target->supports('node', 'update'));
    static::assertTrue($target->supports('media', 'update'));
    static::assertTrue($target->supports('taxonomy_term', 'update'));
    static::assertFalse($target->supports('file', 'update'));
  }

  /**
   * Builds the target under test with default enabled config.
   */
  private function createTarget(BlazegraphIndexerServiceInterface $indexer): BlazegraphIndexTarget {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnMap([
        ['enabled', TRUE],
        ['endpoint', 'http://blazegraph:8080/bigdata/namespace/islandora/sparql'],
        ['timeout', 30],
        ['named_graph', NULL],
      ]);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('islandora_events_blazegraph.settings')
      ->willReturn($config);

    $jwt = $this->createMock(JwtAuth::class);
    $jwt->method('generateToken')->willReturn('token');

    $breaker = $this->createMock(CircuitBreakerService::class);
    $breaker->expects($this->once())->method('ensure');
    $breaker->expects($this->once())->method('assertAllows');
    $breaker->expects($this->once())->method('recordSuccess');

    return new BlazegraphIndexTarget(
      $configFactory,
      $jwt,
      $breaker,
      $indexer,
      $this->createMock(AccountProxyInterface::class),
    );
  }

}
