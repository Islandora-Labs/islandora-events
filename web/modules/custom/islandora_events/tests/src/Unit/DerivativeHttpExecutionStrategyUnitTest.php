<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events\Unit;

use Drupal\islandora_events\Service\DerivativeHttpExecutionStrategy;
use Drupal\sm_workers\ExecutionStrategy\WorkerExecutionContext;
use Drupal\sm_workers\Service\CircuitBreakerService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;

/**
 * Unit tests for the HTTP derivative execution strategy.
 */
final class DerivativeHttpExecutionStrategyUnitTest extends UnitTestCase {

  /**
   * Tests HTTP execution emits heartbeats around the downstream request.
   */
  public function testExecuteEmitsHeartbeatsAndRecordsBreakerSuccess(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->with(
        'GET',
        'https://derivatives.example/process',
        $this->callback(static function (array $options): bool {
          return ($options['timeout'] ?? NULL) === 123
            && ($options['headers']['Authorization'] ?? '') === 'Bearer token';
        }),
      )
      ->willReturn(new Response(200, ['Content-Type' => 'image/tiff'], 'body'));

    $breakers = $this->createMock(CircuitBreakerService::class);
    $breakers->expects($this->once())->method('ensure')->with('derivative:ocr', 'Derivative queue ocr');
    $breakers->expects($this->once())->method('assertAllows')->with('derivative:ocr', 'Derivative queue ocr');
    $breakers->expects($this->once())->method('recordSuccess')->with('derivative:ocr');

    $heartbeats = 0;
    $strategy = new DerivativeHttpExecutionStrategy($client, $breakers);
    $result = $strategy->execute(new WorkerExecutionContext(
      '{"event":"value"}',
      [
        'source_uri' => 'https://repo.example/source',
        'mimetype' => 'image/tiff',
      ],
      [
        'queue' => 'ocr',
        'endpoint' => 'https://derivatives.example/process',
        'timeout' => 123,
      ],
      'Bearer token',
      static function () use (&$heartbeats): void {
        $heartbeats++;
      },
    ));

    static::assertSame(2, $heartbeats);
    static::assertSame('body', $result->body());
    static::assertSame('image/tiff', $result->contentType());
  }

}
