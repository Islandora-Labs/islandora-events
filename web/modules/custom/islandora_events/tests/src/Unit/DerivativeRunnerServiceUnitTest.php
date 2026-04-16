<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\islandora_events\Service\DerivativePayloadNormalizer;
use Drupal\islandora_events\Service\DerivativeRunnerConfigResolver;
use Drupal\islandora_events\Service\DerivativeRunnerService;
use Drupal\islandora_events\Service\DerivativeWriteBackService;
use Drupal\jwt\Authentication\Provider\JwtAuth;
use Drupal\sm_ledger\Service\LedgerRecoveryService;
use Drupal\sm_workers\ExecutionStrategy\WorkerExecutionContext;
use Drupal\sm_workers\ExecutionStrategy\WorkerExecutionManager;
use Drupal\sm_workers\ExecutionStrategy\WorkerExecutionResult;
use Drupal\Tests\UnitTestCase;
use Drupal\sm_ledger\Entity\EventRecordInterface;
use Drupal\sm_ledger\Service\LedgerProjectionService;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for derivative queue execution coordination.
 */
final class DerivativeRunnerServiceUnitTest extends UnitTestCase {

  /**
   * Tests native queue processing uses the matching registered strategy.
   */
  public function testProcessNativeQueueExecutesMatchingStrategy(): void {
    $record = $this->createRecord(5);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->with([5])->willReturn([5 => $record]);
    $storage->method('load')->with(5)->willReturn($record);

    $userStorage = $this->createMock(EntityStorageInterface::class);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['event_record', $storage],
        ['user', $userStorage],
      ]);

    $projection = $this->createMock(LedgerProjectionService::class);
    $recovery = $this->createMock(LedgerRecoveryService::class);
    $recovery->expects($this->once())
      ->method('claimRecordsForProcessing')
      ->with('derivative', 'islandora-connector-houdini', 1)
      ->willReturn([5]);
    $projection->expects($this->once())->method('markCompleted')->with(5);

    $payloadNormalizer = $this->createMock(DerivativePayloadNormalizer::class);
    $payloadNormalizer->method('decodeTransportMetadata')
      ->willReturn([
        'queue' => 'islandora-connector-houdini',
        'headers' => ['persistent' => 'true'],
      ]);
    $payloadNormalizer->method('normalizeDerivativePayload')
      ->willReturnCallback(
        static fn (string $payload, array $content): array => [
          $payload,
          $content + ['source_mimetype' => 'application/pdf'],
        ],
      );

    $runnerConfigResolver = $this->createMock(DerivativeRunnerConfigResolver::class);
    $runnerConfigResolver->expects($this->once())
      ->method('resolve')
      ->with('islandora-connector-houdini', [])
      ->willReturn([
        'queue' => 'islandora-connector-houdini',
        'execution_mode' => 'http',
        'endpoint' => 'http://example.org/derivative',
        'timeout' => 30,
        'write_back' => TRUE,
      ]);
    $runnerConfigResolver->method('normalizeBoolean')->willReturn(TRUE);

    $writeBackService = $this->createMock(DerivativeWriteBackService::class);
    $writeBackService->expects($this->once())
      ->method('writeBack')
      ->with(
        'http://example.org/destination',
        'DERIVATIVE',
        'image/jpeg',
        'public://test.jpg',
        'Bearer generated-token',
        30,
      );

    $logger = $this->createMock(LoggerInterface::class);
    $accountSwitcher = $this->createMock(AccountSwitcherInterface::class);
    $jwtAuth = $this->createMock(JwtAuth::class);
    $jwtAuth->expects($this->once())
      ->method('generateToken')
      ->willReturn('generated-token');

    $executionManager = $this->createMock(WorkerExecutionManager::class);
    $executionManager->expects($this->once())
      ->method('execute')
      ->with(
        'http',
        $this->callback(
          static fn (WorkerExecutionContext $context): bool => $context->payload() !== ''
            && ($context->metadata()['source_uri'] ?? '') === 'http://example.org/source'
            && ($context->worker()['endpoint'] ?? '') === 'http://example.org/derivative'
            && $context->authorization() === 'Bearer generated-token',
        ),
      )
      ->willReturn(new WorkerExecutionResult('DERIVATIVE', 'image/jpeg'));

    $service = new DerivativeRunnerService(
      $entityTypeManager,
      $projection,
      $recovery,
      $logger,
      $accountSwitcher,
      $jwtAuth,
      $payloadNormalizer,
      $runnerConfigResolver,
      $writeBackService,
      $executionManager,
    );

    $results = $service->processNativeQueue('islandora-connector-houdini', 1);
    $this->assertCount(1, $results);
    $this->assertSame('completed', $results[0]['status']);
  }

  /**
   * Tests configured runners override merged tagged defaults.
   */
  public function testProcessNativeQueueMergesDefaultsProviders(): void {
    $record = $this->createRecord(6);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('loadMultiple')->with([6])->willReturn([6 => $record]);
    $storage->method('load')->with(6)->willReturn($record);

    $userStorage = $this->createMock(EntityStorageInterface::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['event_record', $storage],
        ['user', $userStorage],
      ]);

    $projection = $this->createMock(LedgerProjectionService::class);
    $recovery = $this->createMock(LedgerRecoveryService::class);
    $recovery->expects($this->once())
      ->method('claimRecordsForProcessing')
      ->with('derivative', 'islandora-connector-houdini', 1)
      ->willReturn([6]);
    $projection->expects($this->once())->method('markCompleted')->with(6);

    $payloadNormalizer = $this->createMock(DerivativePayloadNormalizer::class);
    $payloadNormalizer->method('decodeTransportMetadata')
      ->willReturn([
        'queue' => 'islandora-connector-houdini',
        'headers' => ['persistent' => 'true'],
      ]);
    $payloadNormalizer->method('normalizeDerivativePayload')
      ->willReturnCallback(
        static fn (string $payload, array $content): array => [
          $payload,
          $content + ['source_mimetype' => 'application/pdf'],
        ],
      );

    $runnerConfigResolver = $this->createMock(DerivativeRunnerConfigResolver::class);
    $runnerConfigResolver->expects($this->once())
      ->method('resolve')
      ->with('islandora-connector-houdini', [])
      ->willReturn([
        'queue' => 'islandora-connector-houdini',
        'execution_mode' => 'command',
        'endpoint' => 'http://houdini:8080/',
        'timeout' => 45,
        'config_path' => '/opt/scyllaridae/houdini/scyllaridae.yml',
        'command' => '/usr/bin/scyllaridae',
        'write_back' => TRUE,
      ]);
    $runnerConfigResolver->method('normalizeBoolean')->willReturn(TRUE);

    $writeBackService = $this->createMock(DerivativeWriteBackService::class);
    $writeBackService->expects($this->once())
      ->method('writeBack')
      ->with(
        'http://example.org/destination',
        'DERIVATIVE',
        'image/jpeg',
        'public://test.jpg',
        'Bearer generated-token',
        45,
      );

    $logger = $this->createMock(LoggerInterface::class);
    $accountSwitcher = $this->createMock(AccountSwitcherInterface::class);
    $jwtAuth = $this->createMock(JwtAuth::class);
    $jwtAuth->expects($this->once())
      ->method('generateToken')
      ->willReturn('generated-token');

    $executionManager = $this->createMock(WorkerExecutionManager::class);
    $executionManager->expects($this->once())
      ->method('execute')
      ->with(
        'command',
        $this->callback(function (WorkerExecutionContext $context): bool {
          $this->assertNotSame('', $context->payload());
          $this->assertSame('application/pdf', $context->metadata()['source_mimetype'] ?? '');
          $this->assertSame('command', $context->worker()['execution_mode'] ?? '');
          $this->assertSame('http://houdini:8080/', $context->worker()['endpoint'] ?? '');
          $this->assertSame('/opt/scyllaridae/houdini/scyllaridae.yml', $context->worker()['config_path'] ?? '');
          $this->assertSame('/usr/bin/scyllaridae', $context->worker()['command'] ?? '');
          $this->assertSame(45, $context->worker()['timeout'] ?? NULL);
          $this->assertSame('Bearer generated-token', $context->authorization() ?? '');
          return TRUE;
        }),
      )
      ->willReturn(new WorkerExecutionResult('DERIVATIVE', 'image/jpeg'));

    $service = new DerivativeRunnerService(
      $entityTypeManager,
      $projection,
      $recovery,
      $logger,
      $accountSwitcher,
      $jwtAuth,
      $payloadNormalizer,
      $runnerConfigResolver,
      $writeBackService,
      $executionManager,
    );

    $results = $service->processNativeQueue('islandora-connector-houdini', 1);
    $this->assertCount(1, $results);
    $this->assertSame('completed', $results[0]['status']);
  }

  /**
   * Creates an event record mock compatible with the runner service.
   */
  private function createRecord(int $id): EventRecordInterface {
    $record = $this->createMock(EventRecordInterface::class);
    $record->method('id')->willReturn($id);
    $record->method('get')->willReturnCallback(static function (string $field): object {
      return match ($field) {
        'transport_metadata' => (object) [
          'value' => json_encode([
            'queue' => 'islandora-connector-houdini',
            'headers' => ['persistent' => 'true'],
          ]),
        ],
        'payload_json' => (object) [
          'value' => json_encode([
            'object' => [
              'url' => [
                [
                  'name' => 'Describes',
                  'type' => 'Link',
                  'href' => 'http://example.org/source',
                  'mediaType' => 'application/pdf',
                  'rel' => 'describes',
                ],
              ],
            ],
            'attachment' => [
              'content' => [
                'mimetype' => 'image/jpeg',
                'args' => '--quality 80',
                'source_uri' => 'http://example.org/source',
                'destination_uri' => 'http://example.org/destination',
                'file_upload_uri' => 'public://test.jpg',
              ],
            ],
          ]),
        ],
        'initiating_user_id' => (object) ['target_id' => 0],
        default => (object) ['value' => ''],
      };
    });

    return $record;
  }

}
