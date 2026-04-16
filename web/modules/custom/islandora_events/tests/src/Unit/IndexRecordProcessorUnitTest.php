<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\islandora_events\Index\IndexTargetInterface;
use Drupal\islandora_events\Index\IndexTargetManager;
use Drupal\islandora_events\Service\IndexRecordProcessor;
use Drupal\Tests\UnitTestCase;
use Drupal\sm_ledger\Entity\EventRecordInterface;
use Drupal\sm_ledger\Service\LedgerProjectionService;

/**
 * Unit tests for index record processor replay behavior.
 */
final class IndexRecordProcessorUnitTest extends UnitTestCase {

  /**
   * Tests replay leaves ledger status untouched for disabled targets.
   */
  public function testReplayDoesNotRewriteLedgerStatusWhenTargetDisabled(): void {
    $record = $this->createMock(EventRecordInterface::class);
    $record->method('id')->willReturn(41);
    $record->method('get')->willReturnCallback(static function (string $name): object {
      return match ($name) {
        'target_system' => (object) ['value' => 'fedora'],
        'payload_json' => (object) ['value' => '{}'],
        'source_entity_type' => (object) ['value' => 'node'],
        'source_entity_id' => (object) ['value' => 99],
        'trigger_event_type' => (object) ['value' => 'update'],
        'initiating_user_id' => (object) ['target_id' => NULL],
        default => (object) ['value' => ''],
      };
    });

    $recordStorage = $this->createMock(EntityStorageInterface::class);
    $recordStorage->expects($this->once())->method('load')->with(41)->willReturn($record);

    $userStorage = $this->createMock(EntityStorageInterface::class);
    $entityStorage = $this->createMock(EntityStorageInterface::class);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['event_record', $recordStorage],
        ['user', $userStorage],
        ['node', $entityStorage],
      ]);

    $target = $this->createMock(IndexTargetInterface::class);
    $target->method('isEnabled')->willReturn(FALSE);
    $target->expects($this->never())->method('process');

    $targetManager = $this->createMock(IndexTargetManager::class);
    $targetManager->expects($this->once())->method('getTarget')->with('fedora')->willReturn($target);

    $projection = $this->createMock(LedgerProjectionService::class);
    $projection->expects($this->never())->method('markAbandoned');
    $projection->expects($this->never())->method('markFailed');
    $projection->expects($this->never())->method('markCompleted');

    $processor = new IndexRecordProcessor(
      $entityTypeManager,
      $targetManager,
      $projection,
      $this->createMock(AccountSwitcherInterface::class),
    );

    $processor->replayEventRecord(41);
  }

  /**
   * Tests stale 412 no-op updates are marked completed during managed runs.
   */
  public function testProcessMarksCompletedWhenTargetReportsNotNewer(): void {
    $record = $this->createMock(EventRecordInterface::class);
    $record->method('id')->willReturn(42);
    $record->method('get')->willReturnCallback(static function (string $name): object {
      return match ($name) {
        'target_system' => (object) ['value' => 'fedora'],
        'payload_json' => (object) ['value' => '{}'],
        'source_entity_type' => (object) ['value' => 'taxonomy_term'],
        'source_entity_id' => (object) ['value' => 907],
        'trigger_event_type' => (object) ['value' => 'update'],
        'initiating_user_id' => (object) ['target_id' => NULL],
        default => (object) ['value' => ''],
      };
    });

    $recordStorage = $this->createMock(EntityStorageInterface::class);
    $recordStorage->expects($this->once())->method('load')->with(42)->willReturn($record);

    $userStorage = $this->createMock(EntityStorageInterface::class);
    $entityStorage = $this->createMock(EntityStorageInterface::class);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')
      ->willReturnMap([
        ['event_record', $recordStorage],
        ['user', $userStorage],
        ['taxonomy_term', $entityStorage],
      ]);

    $target = $this->createMock(IndexTargetInterface::class);
    $target->method('isEnabled')->willReturn(TRUE);
    $target->expects($this->once())
      ->method('process')
      ->willThrowException(new \RuntimeException(
        'Not updating http://fcrepo:8080/fcrepo/rest/example because RDF at http://islandora.traefik.me/taxonomy/term/907?_format=jsonld is not newer.',
        412,
      ));

    $targetManager = $this->createMock(IndexTargetManager::class);
    $targetManager->expects($this->once())->method('getTarget')->with('fedora')->willReturn($target);

    $projection = $this->createMock(LedgerProjectionService::class);
    $projection->expects($this->once())->method('markProcessing')->with(42);
    $projection->expects($this->once())->method('markCompleted')->with(42);
    $projection->expects($this->never())->method('markFailed');

    $processor = new IndexRecordProcessor(
      $entityTypeManager,
      $targetManager,
      $projection,
      $this->createMock(AccountSwitcherInterface::class),
    );

    $processor->processEventRecord(42, TRUE);
  }

}
