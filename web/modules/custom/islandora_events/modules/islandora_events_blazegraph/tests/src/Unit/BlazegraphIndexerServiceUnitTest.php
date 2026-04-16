<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events_blazegraph\Unit;

use Drupal\islandora_events_blazegraph\Service\BlazegraphIndexerService;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for embedded Blazegraph SPARQL writes.
 */
final class BlazegraphIndexerServiceUnitTest extends UnitTestCase {

  /**
   * Tests update requests fetch JSON-LD and POST a SPARQL update.
   */
  public function testUpdateResourceBuildsSparqlUpdate(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->exactly(2))
      ->method('request')
      ->willReturnCallback(function (string $method, string $url, array $options): Response {
        if ($method === 'GET') {
          static::assertSame('http://example.com/node/1?_format=jsonld', $url);
          return new Response(200, [
            'Link' => '<http://example.com/node/1>; rel="describes"',
          ], <<<'JSON'
{
  "@context": {
    "title": "http://purl.org/dc/terms/title"
  },
  "@id": "http://example.com/node/1",
  "title": "Example"
}
JSON);
        }

        static::assertSame('POST', $method);
        static::assertSame('http://blazegraph:8080/bigdata/namespace/islandora/sparql', $url);
        static::assertSame(
          'application/x-www-form-urlencoded; charset=utf-8',
          $options['headers']['Content-Type'],
        );
        static::assertStringContainsString('DELETE%20WHERE', $options['body']);
        static::assertStringContainsString('INSERT%20DATA', $options['body']);
        static::assertStringContainsString(rawurlencode('http://example.com/node/1'), $options['body']);

        return new Response(200);
      });

    $service = new BlazegraphIndexerService($client, $this->createMock(LoggerInterface::class));
    $service->updateResource(
      'http://example.com/node/1?_format=jsonld',
      'http://example.com/node/1',
      'http://blazegraph:8080/bigdata/namespace/islandora/sparql',
      'Bearer token',
    );
  }

  /**
   * Tests delete requests POST a SPARQL delete statement.
   */
  public function testDeleteResourceBuildsSparqlDelete(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'http://blazegraph:8080/bigdata/namespace/islandora/sparql',
        $this->callback(static function (array $options): bool {
          return isset($options['body'])
            && str_contains($options['body'], 'DELETE%20WHERE')
            && !str_contains($options['body'], 'INSERT%20DATA');
        }),
      )
      ->willReturn(new Response(200));

    $service = new BlazegraphIndexerService($client, $this->createMock(LoggerInterface::class));
    $service->deleteResource(
      'http://example.com/node/1',
      'http://blazegraph:8080/bigdata/namespace/islandora/sparql',
      'Bearer token',
    );
  }

}
