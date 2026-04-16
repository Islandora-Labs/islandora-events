<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events\Unit;

use Drupal\islandora_events\Message\FedoraIndexEventMessage;
use Drupal\islandora_events\MessageHandler\IndexEventHandler;
use Drupal\islandora_events\Service\IndexRecordProcessorInterface;
use Drupal\sm_ledger\Service\LedgerExecutionLockServiceInterface;
use Drupal\sm_ledger\Service\LedgerProjectionService;
use Drupal\sm_workers\Exception\CircuitBreakerOpenException;
use Drupal\Tests\UnitTestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for index event handler lifecycle behavior.
 */
final class IndexEventHandlerUnitTest extends UnitTestCase {

  /**
   * Tests successful processing delegates to IndexRecordProcessor.
   */
  public function testInvokeProcessesEnabledTarget(): void {
    $processor = $this->createMock(IndexRecordProcessorInterface::class);
    $processor->expects($this->once())->method('processEventRecord')->with(11);

    $projection = $this->createMock(LedgerProjectionService::class);
    $projection->expects($this->once())->method('isQueuedForProcessing')->with(11)->willReturn(TRUE);
    $projection->expects($this->once())->method('markProcessing')->with(11);
    $projection->expects($this->never())->method('markAbandoned');

    $executionLocks = $this->createMock(LedgerExecutionLockServiceInterface::class);
    $executionLocks->expects($this->once())->method('acquire')->willReturn(TRUE);

    $handler = new IndexEventHandler(
      $processor,
      $projection,
      $executionLocks,
      $this->createMock(LoggerInterface::class),
    );

    $handler(new FedoraIndexEventMessage(22, 'node', 'update', 'fedora', 11));
  }

  /**
   * Tests that processing is skipped when the record is not queued.
   */
  public function testInvokeMarksAbandonedWhenTargetDisabled(): void {
    $processor = $this->createMock(IndexRecordProcessorInterface::class);
    $processor->expects($this->never())->method('processEventRecord');

    $projection = $this->createMock(LedgerProjectionService::class);
    $projection->expects($this->once())->method('isQueuedForProcessing')->with(12)->willReturn(FALSE);

    $executionLocks = $this->createMock(LedgerExecutionLockServiceInterface::class);
    $executionLocks->expects($this->once())->method('acquire')->willReturn(TRUE);
    $executionLocks->expects($this->once())->method('release');

    $handler = new IndexEventHandler(
      $processor,
      $projection,
      $executionLocks,
      $this->createMock(LoggerInterface::class),
    );

    $handler(new FedoraIndexEventMessage(22, 'node', 'update', 'fedora', 12));
  }

  /**
   * Tests exception path rethrows for Messenger lifecycle handling.
   */
  public function testInvokeRethrowsExceptionForWorkerLifecycleHandling(): void {
    $processor = $this->createMock(IndexRecordProcessorInterface::class);
    $processor->method('processEventRecord')->willThrowException(new \RuntimeException('boom'));

    $projection = $this->createMock(LedgerProjectionService::class);
    $projection->expects($this->once())->method('isQueuedForProcessing')->with(13)->willReturn(TRUE);
    $projection->expects($this->once())->method('markProcessing')->with(13);

    $executionLocks = $this->createMock(LedgerExecutionLockServiceInterface::class);
    $executionLocks->expects($this->once())->method('acquire')->willReturn(TRUE);

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->once())->method('error');

    $handler = new IndexEventHandler(
      $processor,
      $projection,
      $executionLocks,
      $logger,
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('boom');
    $handler(new FedoraIndexEventMessage(22, 'node', 'update', 'fedora', 13));
  }

  /**
   * Tests open breakers short-circuit without worker-error logging.
   */
  public function testInvokeSkipsLoggingAndRethrowWhenBreakerIsOpen(): void {
    $processor = $this->createMock(IndexRecordProcessorInterface::class);
    $processor->method('processEventRecord')->willThrowException(
      new CircuitBreakerOpenException('Circuit breaker is open.', FALSE, 1700000600),
    );

    $projection = $this->createMock(LedgerProjectionService::class);
    $projection->expects($this->once())->method('isQueuedForProcessing')->with(17)->willReturn(TRUE);
    $projection->expects($this->once())->method('markProcessing')->with(17);
    $projection->expects($this->once())
      ->method('markRetryDue')
      ->with(17, 0, 1700000600, 'Circuit breaker is open.');

    $executionLocks = $this->createMock(LedgerExecutionLockServiceInterface::class);
    $executionLocks->expects($this->once())->method('acquire')->willReturn(TRUE);
    $executionLocks->expects($this->once())->method('release');

    $logger = $this->createMock(LoggerInterface::class);
    $logger->expects($this->never())->method('error');

    $handler = new IndexEventHandler(
      $processor,
      $projection,
      $executionLocks,
      $logger,
    );

    $handler(new FedoraIndexEventMessage(22, 'node', 'update', 'fedora', 17));
  }

  /**
   * Tests redelivered closed records are ignored.
   */
  public function testInvokeSkipsClosedEventRecord(): void {
    $processor = $this->createMock(IndexRecordProcessorInterface::class);
    $processor->expects($this->never())->method('processEventRecord');

    $projection = $this->createMock(LedgerProjectionService::class);
    $projection->expects($this->once())->method('isQueuedForProcessing')->with(14)->willReturn(FALSE);

    $executionLocks = $this->createMock(LedgerExecutionLockServiceInterface::class);
    $executionLocks->expects($this->once())->method('acquire')->willReturn(TRUE);
    $executionLocks->expects($this->once())->method('release');

    $handler = new IndexEventHandler(
      $processor,
      $projection,
      $executionLocks,
      $this->createMock(LoggerInterface::class),
    );

    $handler(new FedoraIndexEventMessage(22, 'node', 'update', 'fedora', 14));
  }

  /**
   * Tests correlation-key messages resolve the record before processing.
   */
  public function testInvokeResolvesEventRecordIdFromCorrelationKey(): void {
    $processor = $this->createMock(IndexRecordProcessorInterface::class);
    $processor->expects($this->once())->method('processEventRecord')->with(15);

    $projection = $this->createMock(LedgerProjectionService::class);
    $projection->expects($this->once())
      ->method('findOpenByCorrelationKey')
      ->with('index:fedora:node:22:update')
      ->willReturn(15);
    $projection->expects($this->once())->method('isQueuedForProcessing')->with(15)->willReturn(TRUE);
    $projection->expects($this->once())->method('markProcessing')->with(15);

    $executionLocks = $this->createMock(LedgerExecutionLockServiceInterface::class);
    $executionLocks->expects($this->once())->method('acquire')->willReturn(TRUE);

    $handler = new IndexEventHandler(
      $processor,
      $projection,
      $executionLocks,
      $this->createMock(LoggerInterface::class),
    );

    $handler(new FedoraIndexEventMessage(22, 'node', 'update', 'fedora', 0, 'index:fedora:node:22:update'));
  }

  /**
   * Tests duplicate deliveries are ignored when another worker owns the lock.
   */
  public function testInvokeSkipsWhenExecutionLockIsAlreadyHeld(): void {
    $processor = $this->createMock(IndexRecordProcessorInterface::class);
    $processor->expects($this->never())->method('processEventRecord');

    $projection = $this->createMock(LedgerProjectionService::class);
    $projection->expects($this->never())->method('isQueuedForProcessing');
    $projection->expects($this->never())->method('markProcessing');

    $executionLocks = $this->createMock(LedgerExecutionLockServiceInterface::class);
    $executionLocks->expects($this->once())->method('acquire')->willReturn(FALSE);

    $handler = new IndexEventHandler(
      $processor,
      $projection,
      $executionLocks,
      $this->createMock(LoggerInterface::class),
    );

    $handler(new FedoraIndexEventMessage(22, 'node', 'update', 'fedora', 16));
  }

}
