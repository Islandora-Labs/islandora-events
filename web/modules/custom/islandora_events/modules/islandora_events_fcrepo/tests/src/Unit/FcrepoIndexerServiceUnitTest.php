<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events_fcrepo\Unit;

use Drupal\islandora_events_fcrepo\Service\FcrepoIndexerService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the Fedora/fcrepo JSON-LD indexer service.
 */
final class FcrepoIndexerServiceUnitTest extends UnitTestCase {

  /**
   * Tests media JSON-LD can resolve the resource via describedby fallback.
   */
  public function testProcessJsonldFallsBackToDescribedByReference(): void {
    $service = new FcrepoIndexerService(
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerInterface::class),
    );

    $method = new \ReflectionMethod($service, 'processJsonld');
    $method->setAccessible(TRUE);

    $jsonld = [
      '@graph' => [
        [
          '@id' => 'http://islandora.traefik.me/_flysystem/fedora/2026-04/Documents_Collection_2.png',
          'http://www.iana.org/assignments/relation/describedby' => [
            ['@id' => 'http://islandora.traefik.me/media/117/edit'],
          ],
          'http://schema.org/dateModified' => [
            [
              '@value' => '2026-04-10T18:12:00+00:00',
              '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
            ],
          ],
        ],
      ],
    ];

    $result = $method->invoke(
      $service,
      $jsonld,
      'http://islandora.traefik.me/media/117/edit?_format=jsonld',
      'http://fcrepo:8080/fcrepo/rest/abc123/fcr:metadata',
    );

    static::assertSame(
      'http://fcrepo:8080/fcrepo/rest/abc123/fcr:metadata',
      $result[0]['@id'],
    );
    static::assertSame(
      'http://islandora.traefik.me/media/117/edit',
      $result[0]['http://www.iana.org/assignments/relation/describedby'][0]['@id'],
    );
  }

  /**
   * Tests missing dateModified is treated as optional for Drupal JSON-LD.
   */
  public function testOptionalModifiedTimestampReturnsNullWhenMissing(): void {
    $service = new FcrepoIndexerService(
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerInterface::class),
    );

    $method = new \ReflectionMethod($service, 'getOptionalModifiedTimestamp');
    $method->setAccessible(TRUE);

    $jsonld = [
      [
        '@id' => 'http://fcrepo:8080/fcrepo/rest/term/138',
        'http://schema.org/name' => [
          [
            '@value' => 'Argentina',
            '@language' => 'en',
          ],
        ],
      ],
    ];

    static::assertNull($method->invoke($service, $jsonld));
  }

}
