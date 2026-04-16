<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\islandora_events\Service\DerivativeRunnerConfigProviderInterface;
use Drupal\islandora_events\Service\DerivativeRunnerConfigRegistry;
use Drupal\islandora_events\Service\DerivativeRunnerConfigResolver;
use Drupal\sm_workers\ExecutionStrategy\WorkerRuntimeDefaults;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for derivative runner config resolution.
 */
final class DerivativeRunnerConfigResolverUnitTest extends UnitTestCase {

  /**
   * Tests worker runtime defaults are used when queue config is absent.
   */
  public function testResolveUsesWorkerRuntimeDefaults(): void {
    $ledgerConfig = $this->createMock(Config::class);
    $ledgerConfig->method('get')
      ->with('recovery.heartbeat_interval_seconds')
      ->willReturn(45);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->willReturnMap([
        ['sm_ledger.settings', $ledgerConfig],
      ]);

    $runtimeDefaults = $this->createMock(WorkerRuntimeDefaults::class);
    $runtimeDefaults->expects($this->once())
      ->method('defaultForwardAuth')
      ->willReturn(FALSE);
    $runtimeDefaults->expects($this->once())
      ->method('defaultTimeoutSeconds')
      ->willReturn(123);

    $resolver = new DerivativeRunnerConfigResolver(
      $configFactory,
      $runtimeDefaults,
    );

    $resolved = $resolver->resolve('example');

    static::assertSame('example', $resolved['queue']);
    static::assertFalse($resolved['forward_auth']);
    static::assertSame(123, $resolved['timeout']);
    static::assertSame(45, $resolved['heartbeat_interval']);
  }

  /**
   * Tests provider-owned runner config overrides legacy monolithic settings.
   */
  public function testResolveUsesProviderOwnedRunnerConfig(): void {
    $runnersConfig = $this->createMock(Config::class);
    $runnersConfig->method('get')
      ->with('runners')
      ->willReturn([
        'example' => [
          'execution_mode' => 'http',
          'endpoint' => 'http://example.test/',
          'timeout' => 90,
        ],
      ]);

    $ledgerConfig = $this->createMock(Config::class);
    $ledgerConfig->method('get')
      ->with('recovery.heartbeat_interval_seconds')
      ->willReturn(45);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->willReturnMap([
        ['islandora_events.derivative_runners', $runnersConfig],
        ['sm_ledger.settings', $ledgerConfig],
      ]);

    $provider = new class implements DerivativeRunnerConfigProviderInterface {
      public function getConfigName(): string {
        return 'islandora_events.derivative_runners';
      }
      public function getLabel(): string {
        return 'Base derivative queue runners';
      }
    };

    $runtimeDefaults = $this->createMock(WorkerRuntimeDefaults::class);
    $runtimeDefaults->method('defaultForwardAuth')->willReturn(FALSE);
    $runtimeDefaults->method('defaultTimeoutSeconds')->willReturn(123);

    $resolver = new DerivativeRunnerConfigResolver(
      $configFactory,
      $runtimeDefaults,
      NULL,
      new DerivativeRunnerConfigRegistry([$provider]),
    );

    $resolved = $resolver->resolve('example');

    static::assertSame('http://example.test/', $resolved['endpoint']);
    static::assertSame(90, $resolved['timeout']);
  }

}
