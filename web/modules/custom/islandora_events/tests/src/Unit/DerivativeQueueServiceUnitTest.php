<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\islandora\EventGenerator\EventGeneratorInterface;
use Drupal\islandora_events\Message\IslandoraDerivativeMessage;
use Drupal\islandora_events\Service\DerivativeActionDataExtractor;
use Drupal\islandora_events\Service\DerivativeQueueService;
use Drupal\sm_ledger\Service\LedgerDispatchService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Unit tests for derivative queue persistence and dispatch.
 */
final class DerivativeQueueServiceUnitTest extends UnitTestCase {

  /**
   * Tests enqueued derivative jobs are persisted with queue metadata.
   */
  public function testEnqueueCreatesEventRecord(): void {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('id')->willReturn(7);
    $entity->method('uuid')->willReturn('abc-123');

    $dispatch = $this->createMock(LedgerDispatchService::class);
    $dispatch->expects($this->once())
      ->method('recordAndDispatch')
      ->with(
        $this->identicalTo($entity),
        $this->identicalTo('Generate Derivative'),
        $this->identicalTo(
          'derivative:islandora-connector-houdini:node:7:'
          . '2545a8604bd9414fb06ea5eeea2861a75cb7eb34'
        ),
        $this->callback(static function (object $message): bool {
          return $message instanceof IslandoraDerivativeMessage
            && $message->entityId === 7
            && $message->entityType === 'node'
            && $message->eventType === 'Generate Derivative'
            && $message->eventRecordId === 0
            && preg_match(
              '/^derivative:islandora-connector-houdini:node:7:2545a8604bd9414fb06ea5eeea2861a75cb7eb34:[a-f0-9]{16}$/',
              $message->correlationKey,
            ) === 1;
        }),
        $this->callback(function (array $values): bool {
          $metadata = json_decode($values['transport_metadata'], TRUE);
          return $values['action_plugin_id'] === 'generate_image_derivative'
            && $values['target_system'] === 'derivative_runner'
            && $values['dedupe_key'] === 'derivative:islandora-connector-houdini:node:7:2545a8604bd9414fb06ea5eeea2861a75cb7eb34'
            && preg_match(
              '/^derivative:islandora-connector-houdini:node:7:2545a8604bd9414fb06ea5eeea2861a75cb7eb34:[a-f0-9]{16}$/',
              $values['correlation_key'],
            ) === 1
            && str_contains($values['transport_metadata'], 'islandora-connector-houdini')
            && !isset($metadata['headers']['Authorization']);
        }),
      )
      ->willReturn(TRUE);

    $actionStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage = $this->createMock(EntityStorageInterface::class);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['action', $actionStorage],
        ['user', $userStorage],
      ]);

    $currentUser = $this->createMock(AccountProxyInterface::class);
    $currentUser->method('id')->willReturn(1);

    $eventGenerator = $this->createMock(EventGeneratorInterface::class);
    $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('info');
    $service = new DerivativeQueueService(
      $entityTypeManager,
      $currentUser,
      $dispatch,
      $logger,
      $eventGenerator,
      $eventDispatcher,
      $this->createMock(DerivativeActionDataExtractor::class),
    );
    $service->enqueue(
      $entity,
      '{"type":"Activity"}',
      ['Authorization' => 'Bearer test'],
      ['queue' => 'islandora-connector-houdini', 'event' => 'Generate Derivative'],
      'generate_image_derivative'
    );
  }

  /**
   * Tests lock contention skips derivative enqueue before any DB work starts.
   */
  public function testEnqueueSkipsWhenDedupeLockCannotBeAcquired(): void {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('id')->willReturn(7);

    $actionStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage = $this->createMock(EntityStorageInterface::class);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['action', $actionStorage],
        ['user', $userStorage],
      ]);

    $dispatch = $this->createMock(LedgerDispatchService::class);
    $dispatch->expects($this->once())
      ->method('recordAndDispatch')
      ->willReturn(FALSE);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('debug');

    $service = new DerivativeQueueService(
      $entityTypeManager,
      $this->createMock(AccountProxyInterface::class),
      $dispatch,
      $logger,
      $this->createMock(EventGeneratorInterface::class),
      $this->createMock(EventDispatcherInterface::class),
      $this->createMock(DerivativeActionDataExtractor::class),
    );

    $service->enqueue(
      $entity,
      '{"type":"Activity"}',
      [],
      ['queue' => 'islandora-connector-houdini', 'event' => 'Generate Derivative'],
      'generate_image_derivative'
    );
  }

  /**
   * Tests dispatch exceptions roll back the open database transaction.
   */
  public function testEnqueueRollsBackWhenDispatchFails(): void {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('id')->willReturn(7);
    $entity->method('uuid')->willReturn('abc-123');

    $actionStorage = $this->createMock(EntityStorageInterface::class);
    $userStorage = $this->createMock(EntityStorageInterface::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['action', $actionStorage],
        ['user', $userStorage],
      ]);

    $dispatch = $this->createMock(LedgerDispatchService::class);
    $dispatch->expects($this->once())
      ->method('recordAndDispatch')
      ->willThrowException(new \RuntimeException('dispatch failed'));

    $service = new DerivativeQueueService(
      $entityTypeManager,
      $this->createMock(AccountProxyInterface::class),
      $dispatch,
      $this->createMock(LoggerInterface::class),
      $this->createMock(EventGeneratorInterface::class),
      $this->createMock(EventDispatcherInterface::class),
      $this->createMock(DerivativeActionDataExtractor::class),
    );

    try {
      $service->enqueue(
        $entity,
        '{"type":"Activity"}',
        [],
        ['queue' => 'islandora-connector-houdini', 'event' => 'Generate Derivative'],
        'generate_image_derivative'
      );
      $this->fail('Expected dispatch exception was not thrown.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('dispatch failed', $exception->getMessage());
    }
  }

}
