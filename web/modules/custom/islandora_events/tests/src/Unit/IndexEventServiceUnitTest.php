<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events\Unit;

use Drupal\islandora_events\Index\IndexTargetInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\islandora_events\Index\IndexPayloadBuilder;
use Drupal\islandora_events\Index\IndexTargetManager;
use Drupal\islandora_events\Message\BlazegraphIndexEventMessage;
use Drupal\islandora_events\Message\FedoraIndexEventMessage;
use Drupal\islandora_events\Message\CustomIndexEventMessage;
use Drupal\islandora_events\Service\IndexEventService;
use Drupal\sm_ledger\Service\LedgerDispatchService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for index event queueing behavior.
 */
final class IndexEventServiceUnitTest extends UnitTestCase {

  /**
   * Tests dispatching fedora and blazegraph messages for one entity event.
   */
  public function testQueueEntityEventDispatchesEnabledTargets(): void {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('id')->willReturn(10);
    $entity->method('uuid')->willReturn('entity-uuid');

    $indexTargetManager = $this->createMock(IndexTargetManager::class);
    $indexTargetManager->expects($this->once())
      ->method('getEnabledTargetIdsFor')
      ->with('node', 'update')
      ->willReturn(['fedora', 'blazegraph']);
    $fedoraTarget = $this->createMock(IndexTargetInterface::class);
    $fedoraTarget->method('getMessageClass')->willReturn(FedoraIndexEventMessage::class);
    $blazegraphTarget = $this->createMock(IndexTargetInterface::class);
    $blazegraphTarget->method('getMessageClass')->willReturn(BlazegraphIndexEventMessage::class);
    $indexTargetManager->method('getTarget')
      ->willReturnMap([
        ['fedora', $fedoraTarget],
        ['blazegraph', $blazegraphTarget],
      ]);

    $payloadBuilder = $this->createMock(IndexPayloadBuilder::class);
    $payloadBuilder->method('build')
      ->willReturnCallback(fn (EntityInterface $e, string $operation, string $target): array => [
        'entity_type' => $e->getEntityTypeId(),
        'entity_id' => (int) $e->id(),
        'operation' => $operation,
        'target' => $target,
      ]);

    $messages = [];
    $dispatch = $this->createMock(LedgerDispatchService::class);
    $dispatch->expects($this->exactly(2))
      ->method('recordAndDispatch')
      ->willReturnCallback(function (
        EntityInterface $sourceEntity,
        string $triggerEventType,
        string $dedupeKey,
        object $message,
        array $recordValues,
      ) use (&$messages, $entity): bool {
        $this->assertSame($entity, $sourceEntity);
        $this->assertSame('update', $triggerEventType);
        $this->assertSame($dedupeKey, $recordValues['dedupe_key']);
        $this->assertMatchesRegularExpression(
          '/^' . preg_quote($dedupeKey, '/') . ':[a-f0-9]{16}$/',
          $recordValues['correlation_key'],
        );
        $messages[] = $message;
        return TRUE;
      });

    $service = new IndexEventService(
      $indexTargetManager,
      $payloadBuilder,
      $dispatch,
      $this->createMock(LoggerInterface::class),
    );

    $service->queueEntityEvent($entity, 'update');
    $this->assertInstanceOf(FedoraIndexEventMessage::class, $messages[0]);
    $this->assertInstanceOf(BlazegraphIndexEventMessage::class, $messages[1]);
  }

  /**
   * Tests that open matching events are not requeued.
   */
  public function testQueueEntityEventSkipsOpenCorrelationKey(): void {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('id')->willReturn(10);
    $entity->method('uuid')->willReturn('entity-uuid');

    $indexTargetManager = $this->createMock(IndexTargetManager::class);
    $indexTargetManager->method('getEnabledTargetIdsFor')->willReturn(['fedora']);

    $payloadBuilder = $this->createMock(IndexPayloadBuilder::class);

    $dispatch = $this->createMock(LedgerDispatchService::class);
    $dispatch->expects($this->once())
      ->method('recordAndDispatch')
      ->willReturn(FALSE);

    $service = new IndexEventService(
      $indexTargetManager,
      $payloadBuilder,
      $dispatch,
      $this->createMock(LoggerInterface::class),
    );

    $service->queueEntityEvent($entity, 'update');
  }

  /**
   * Tests explicit target overrides bypass global fan-out.
   */
  public function testQueueEntityEventUsesExplicitTargets(): void {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('id')->willReturn(10);
    $entity->method('uuid')->willReturn('entity-uuid');

    $indexTargetManager = $this->createMock(IndexTargetManager::class);
    $indexTargetManager->expects($this->never())
      ->method('getEnabledTargetIdsFor');
    $fedoraTarget = $this->createMock(IndexTargetInterface::class);
    $fedoraTarget->method('getMessageClass')->willReturn(FedoraIndexEventMessage::class);
    $indexTargetManager->method('getTarget')
      ->with('fedora')
      ->willReturn($fedoraTarget);

    $payloadBuilder = $this->createMock(IndexPayloadBuilder::class);
    $payloadBuilder->expects($this->once())
      ->method('build')
      ->with($entity, 'update', 'fedora')
      ->willReturn([
        'entity_type' => 'node',
        'entity_id' => 10,
        'operation' => 'update',
        'target' => 'fedora',
      ]);

    $dispatch = $this->createMock(LedgerDispatchService::class);
    $dispatch->expects($this->once())
      ->method('recordAndDispatch')
      ->with(
        $entity,
        'update',
        'index:fedora:node:10:update',
        $this->callback(static function (object $message): bool {
          return $message instanceof FedoraIndexEventMessage
            && preg_match('/^index:fedora:node:10:update:[a-f0-9]{16}$/', $message->correlationKey) === 1;
        }),
        $this->callback(static function (array $values): bool {
          return $values['target_system'] === 'fedora'
            && $values['dedupe_key'] === 'index:fedora:node:10:update'
            && preg_match('/^index:fedora:node:10:update:[a-f0-9]{16}$/', $values['correlation_key']) === 1;
        }),
      )
      ->willReturn(TRUE);

    $service = new IndexEventService(
      $indexTargetManager,
      $payloadBuilder,
      $dispatch,
      $this->createMock(LoggerInterface::class),
    );

    $service->queueEntityEvent($entity, 'update', ['fedora']);
  }

  /**
   * Tests lock contention skips enqueue before any DB work starts.
   */
  public function testQueueEntityEventSkipsWhenDedupeLockCannotBeAcquired(): void {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('id')->willReturn(10);

    $indexTargetManager = $this->createMock(IndexTargetManager::class);
    $indexTargetManager->method('getEnabledTargetIdsFor')->willReturn(['fedora']);

    $dispatch = $this->createMock(LedgerDispatchService::class);
    $dispatch->expects($this->once())
      ->method('recordAndDispatch')
      ->willReturn(FALSE);
    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('debug');

    $service = new IndexEventService(
      $indexTargetManager,
      $this->createMock(IndexPayloadBuilder::class),
      $dispatch,
      $logger,
    );

    $service->queueEntityEvent($entity, 'update');
  }

  /**
   * Tests dispatch exceptions roll back the open database transaction.
   */
  public function testQueueEntityEventRollsBackWhenDispatchFails(): void {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('id')->willReturn(10);

    $indexTargetManager = $this->createMock(IndexTargetManager::class);
    $indexTargetManager->method('getEnabledTargetIdsFor')->willReturn(['fedora']);

    $dispatch = $this->createMock(LedgerDispatchService::class);
    $dispatch->expects($this->once())
      ->method('recordAndDispatch')
      ->willThrowException(new \RuntimeException('dispatch failed'));

    $service = new IndexEventService(
      $indexTargetManager,
      $this->createMock(IndexPayloadBuilder::class),
      $dispatch,
      $this->createMock(LoggerInterface::class),
    );

    try {
      $service->queueEntityEvent($entity, 'update');
      $this->fail('Expected dispatch exception was not thrown.');
    }
    catch (\RuntimeException $exception) {
      $this->assertSame('dispatch failed', $exception->getMessage());
    }
  }

  /**
   * Tests non-core targets dispatch a generic async index message.
   */
  public function testQueueEntityEventDispatchesGenericMessageForCustomTarget(): void {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('id')->willReturn(10);
    $entity->method('uuid')->willReturn('entity-uuid');

    $indexTargetManager = $this->createMock(IndexTargetManager::class);
    $indexTargetManager->method('getEnabledTargetIdsFor')->willReturn(['solr']);

    $payloadBuilder = $this->createMock(IndexPayloadBuilder::class);
    $payloadBuilder->expects($this->once())
      ->method('build')
      ->with($entity, 'update', 'solr')
      ->willReturn([
        'entity_type' => 'node',
        'entity_id' => 10,
        'operation' => 'update',
        'target' => 'solr',
      ]);

    $dispatch = $this->createMock(LedgerDispatchService::class);
    $dispatch->expects($this->once())
      ->method('recordAndDispatch')
      ->with(
        $entity,
        'update',
        'index:solr:node:10:update',
        $this->isInstanceOf(CustomIndexEventMessage::class),
        $this->anything(),
      )
      ->willReturn(TRUE);

    $service = new IndexEventService(
      $indexTargetManager,
      $payloadBuilder,
      $dispatch,
      $this->createMock(LoggerInterface::class),
    );

    $service->queueEntityEvent($entity, 'update');
  }

}
