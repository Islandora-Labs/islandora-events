<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events\Unit;

use Drupal\islandora_events\Message\IslandoraDerivativeMessage;
use Drupal\islandora_events\MessageHandler\IslandoraDerivativeHandler;
use Drupal\islandora_events\Service\DerivativeLifecycleMode;
use Drupal\islandora_events\Service\DerivativeRunnerService;
use Drupal\sm_ledger\Service\LedgerExecutionLockServiceInterface;
use Drupal\sm_ledger\Service\LedgerProjectionService;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for derivative handler lifecycle behavior.
 */
final class IslandoraDerivativeHandlerUnitTest extends UnitTestCase {

  /**
   * Tests record-centric messages delegate to the derivative runner.
   */
  public function testInvokeProcessesStoredDerivativeRecord(): void {
    $projection = $this->createMock(LedgerProjectionService::class);
    $projection->expects($this->once())
      ->method('isQueuedForProcessing')
      ->with(14)
      ->willReturn(TRUE);
    $projection->expects($this->once())
      ->method('markProcessing')
      ->with(14);

    $executionLocks = $this->createMock(LedgerExecutionLockServiceInterface::class);
    $executionLocks->expects($this->once())
      ->method('acquire')
      ->willReturn(TRUE);

    $derivativeRunner = $this->createMock(DerivativeRunnerService::class);
    $derivativeRunner->expects($this->once())
      ->method('processEventRecord')
      ->with(14, [], DerivativeLifecycleMode::Externalized);

    $logger = $this->createMock(LoggerInterface::class);

    $handler = new IslandoraDerivativeHandler(
      $derivativeRunner,
      $projection,
      $executionLocks,
      $logger,
    );

    $handler(new IslandoraDerivativeMessage(7, 'media', 'Generate Derivative', 14));
  }

  /**
   * Tests messages can resolve records through their correlation key.
   */
  public function testInvokeResolvesEventRecordIdFromCorrelationKey(): void {
    $projection = $this->createMock(LedgerProjectionService::class);
    $projection->expects($this->once())
      ->method('findOpenByCorrelationKey')
      ->with('derivative:islandora-connector-houdini:media:7:hash')
      ->willReturn(19);
    $projection->expects($this->once())
      ->method('isQueuedForProcessing')
      ->with(19)
      ->willReturn(TRUE);
    $projection->expects($this->once())
      ->method('markProcessing')
      ->with(19);

    $executionLocks = $this->createMock(LedgerExecutionLockServiceInterface::class);
    $executionLocks->expects($this->once())
      ->method('acquire')
      ->willReturn(TRUE);

    $derivativeRunner = $this->createMock(DerivativeRunnerService::class);
    $derivativeRunner->expects($this->once())
      ->method('processEventRecord')
      ->with(19, [], DerivativeLifecycleMode::Externalized);

    $logger = $this->createMock(LoggerInterface::class);

    $handler = new IslandoraDerivativeHandler(
      $derivativeRunner,
      $projection,
      $executionLocks,
      $logger,
    );

    $handler(new IslandoraDerivativeMessage(
      7,
      'media',
      'Generate Derivative',
      0,
      'derivative:islandora-connector-houdini:media:7:hash',
    ));
  }

  /**
   * Tests missing event identifiers fail fast.
   */
  public function testInvokeRequiresEventRecordReference(): void {
    $derivativeRunner = $this->createMock(DerivativeRunnerService::class);
    $derivativeRunner->expects($this->never())->method('processEventRecord');

    $logger = $this->createMock(LoggerInterface::class);

    $handler = new IslandoraDerivativeHandler(
      $derivativeRunner,
      $this->createMock(LedgerProjectionService::class),
      $this->createMock(LedgerExecutionLockServiceInterface::class),
      $logger,
    );

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('must reference an event record or correlation key');
    $handler(new IslandoraDerivativeMessage(7, 'media', 'Generate Derivative'));
  }

  /**
   * Tests exceptions bubble up for Messenger lifecycle handling.
   */
  public function testInvokeRethrowsExceptionForWorkerLifecycleHandling(): void {
    $projection = $this->createMock(LedgerProjectionService::class);
    $projection->method('isQueuedForProcessing')->with(16)->willReturn(TRUE);
    $projection->expects($this->once())->method('markProcessing')->with(16);

    $executionLocks = $this->createMock(LedgerExecutionLockServiceInterface::class);
    $executionLocks->expects($this->once())
      ->method('acquire')
      ->willReturn(TRUE);

    $derivativeRunner = $this->createMock(DerivativeRunnerService::class);
    $derivativeRunner->method('processEventRecord')->willThrowException(new \RuntimeException('boom'));

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $handler = new IslandoraDerivativeHandler(
      $derivativeRunner,
      $projection,
      $executionLocks,
      $logger,
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('boom');
    $handler(new IslandoraDerivativeMessage(7, 'media', 'Generate Derivative', 16));
  }

  /**
   * Tests closed event records are ignored on redelivery.
   */
  public function testInvokeSkipsClosedEventRecord(): void {
    $projection = $this->createMock(LedgerProjectionService::class);
    $projection->expects($this->once())
      ->method('isQueuedForProcessing')
      ->with(17)
      ->willReturn(FALSE);

    $executionLocks = $this->createMock(LedgerExecutionLockServiceInterface::class);
    $executionLocks->expects($this->once())
      ->method('acquire')
      ->willReturn(TRUE);
    $executionLocks->expects($this->once())
      ->method('release');

    $derivativeRunner = $this->createMock(DerivativeRunnerService::class);
    $derivativeRunner->expects($this->never())->method('processEventRecord');

    $logger = $this->createMock(LoggerInterface::class);

    $handler = new IslandoraDerivativeHandler(
      $derivativeRunner,
      $projection,
      $executionLocks,
      $logger,
    );

    $handler(new IslandoraDerivativeMessage(7, 'media', 'Generate Derivative', 17));
  }

  /**
   * Tests duplicate deliveries are ignored when another worker owns the lock.
   */
  public function testInvokeSkipsWhenExecutionLockIsAlreadyHeld(): void {
    $projection = $this->createMock(LedgerProjectionService::class);
    $projection->expects($this->never())->method('isQueuedForProcessing');
    $projection->expects($this->never())->method('markProcessing');

    $executionLocks = $this->createMock(LedgerExecutionLockServiceInterface::class);
    $executionLocks->expects($this->once())
      ->method('acquire')
      ->willReturn(FALSE);

    $derivativeRunner = $this->createMock(DerivativeRunnerService::class);
    $derivativeRunner->expects($this->never())->method('processEventRecord');

    $handler = new IslandoraDerivativeHandler(
      $derivativeRunner,
      $projection,
      $executionLocks,
      $this->createMock(LoggerInterface::class),
    );

    $handler(new IslandoraDerivativeMessage(7, 'media', 'Generate Derivative', 18));
  }

}
