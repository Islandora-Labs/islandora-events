<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events\Unit;

use Drupal\islandora_events\Service\DerivativeCommandExecutionStrategy;
use Drupal\islandora_events\Service\DerivativeCommandPolicyInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for the command execution strategy helpers.
 */
final class DerivativeCommandExecutionStrategyUnitTest extends UnitTestCase {

  /**
   * Tests the args placeholder must be a standalone token.
   */
  public function testBuildCommandArgumentsRejectsEmbeddedArgsPlaceholder(): void {
    $strategy = new DerivativeCommandExecutionStrategy(
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerInterface::class),
      $this->createPolicyMock(),
    );

    $method = new \ReflectionMethod(DerivativeCommandExecutionStrategy::class, 'buildCommandArguments');
    $method->setAccessible(TRUE);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('The {args} placeholder must be used as a standalone token.');
    $method->invoke($strategy, '/usr/bin/convert prefix-{args}', [
      'args' => '--quality 80',
    ]);
  }

  /**
   * Tests command output files must resolve inside the system temp directory.
   */
  public function testConsumeOutputFileRejectsPathOutsideTempDir(): void {
    $strategy = new DerivativeCommandExecutionStrategy(
      $this->createMock(ClientInterface::class),
      $this->createMock(LoggerInterface::class),
      $this->createPolicyMock(),
    );

    $method = new \ReflectionMethod(DerivativeCommandExecutionStrategy::class, 'consumeOutputFile');
    $method->setAccessible(TRUE);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Configured derivative command returned an invalid output file path.');
    $method->invoke($strategy, '/definitely/not/in/tempdir/derivative-output.bin');
  }

  /**
   * Creates a permissive command policy mock for helper tests.
   */
  private function createPolicyMock(): DerivativeCommandPolicyInterface {
    $policy = $this->createMock(DerivativeCommandPolicyInterface::class);
    $policy->method('isExecutionEnabled')->willReturn(TRUE);
    $policy->method('isAllowedBinary')->willReturn(TRUE);
    $policy->method('parsePassedArgs')->willReturnCallback(
      static function (string $args): array {
        if ($args === '') {
          return [];
        }

        return preg_split('/\s+/', trim($args)) ?: [];
      }
    );

    return $policy;
  }

}
