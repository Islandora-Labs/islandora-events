<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events\Unit;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\islandora_events\Service\IndexLedgerReplayService;
use Drupal\islandora_events\Service\IndexRecordProcessorInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\sm_ledger\Entity\EventRecordInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for index ledger replay service.
 */
final class IndexLedgerReplayServiceUnitTest extends UnitTestCase {

  /**
   * Tests replay builds filtered query and replays matching records.
   */
  public function testReplayReprocessesMatchingLedgerRows(): void {
    $query = new TestEntityQuery([21, 22]);
    $storage = $this->createStorageDouble($query, [
      21 => $this->createRecordStub(21, 'fedora', 'node', 101, 'update', 'completed'),
      22 => $this->createRecordStub(22, 'fedora', 'node', 102, 'delete', 'abandoned'),
    ]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('event_record')->willReturn($storage);

    $processor = $this->createMock(IndexRecordProcessorInterface::class);
    $processor->expects($this->exactly(2))
      ->method('replayEventRecord')
      ->with($this->logicalOr($this->equalTo(21), $this->equalTo(22)));

    $service = new IndexLedgerReplayService(
      $entityTypeManager,
      $processor,
      $this->createMock(LoggerInterface::class),
    );

    $results = $service->replay([
      'target_id' => 'fedora',
      'entity_type' => 'node',
      'statuses' => ['completed', 'abandoned'],
    ], 25, FALSE);

    $this->assertCount(2, $results);
    $this->assertSame('fedora', $results[0]['target']);
    $this->assertSame('replayed', $results[0]['status']);
    $this->assertSame([
      ['event_kind', 'indexing', '='],
      ['target_system', 'fedora', '='],
      ['source_entity_type', 'node', '='],
      ['status', ['completed', 'abandoned'], 'IN'],
    ], $query->conditions);
    $this->assertSame(['id', 'ASC'], $query->sort);
    $this->assertSame([0, 25], $query->range);
    $this->assertTrue($query->accessCheck);
  }

  /**
   * Tests dry-run mode reports matching rows without replaying them.
   */
  public function testReplayDryRunDoesNotInvokeProcessor(): void {
    $query = new TestEntityQuery([30]);
    $storage = $this->createStorageDouble($query, [
      30 => $this->createRecordStub(30, 'blazegraph', 'node', 201, 'update', 'failed'),
    ]);

    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('event_record')->willReturn($storage);

    $processor = $this->createMock(IndexRecordProcessorInterface::class);
    $processor->expects($this->never())->method('replayEventRecord');

    $service = new IndexLedgerReplayService(
      $entityTypeManager,
      $processor,
      $this->createMock(LoggerInterface::class),
    );

    $results = $service->replay([], 10, TRUE);

    $this->assertSame('planned', $results[0]['status']);
    $this->assertSame('failed', $results[0]['ledger_status']);
  }

  /**
   * Creates an event record storage test double.
   */
  private function createStorageDouble(TestEntityQuery $query, array $records): EntityStorageInterface {
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturnCallback(
      static fn (array $ids): array => array_intersect_key($records, array_flip($ids)),
    );

    return $storage;
  }

  /**
   * Creates a lightweight event record stub.
   */
  private function createRecordStub(
    int $id,
    string $target,
    string $entityType,
    int $entityId,
    string $operation,
    string $status,
  ): EventRecordInterface {
    $record = $this->createMock(EventRecordInterface::class);
    $record->method('id')->willReturn($id);
    $record->method('get')->willReturnCallback(static function (string $name) use (
      $target,
      $entityType,
      $entityId,
      $operation,
      $status,
    ): object {
      return match ($name) {
        'target_system' => (object) ['value' => $target],
        'source_entity_type' => (object) ['value' => $entityType],
        'source_entity_id' => (object) ['value' => $entityId],
        'trigger_event_type' => (object) ['value' => $operation],
        'status' => (object) ['value' => $status],
        default => (object) ['value' => ''],
      };
    });

    return $record;
  }

}

/**
 * Minimal entity query double for replay service tests.
 */
final class TestEntityQuery {

  /**
   * Recorded query conditions.
   *
   * @var array<int, array{0:string,1:mixed,2:string}>
   */
  public array $conditions = [];

  /**
   * Recorded sort definition.
   *
   * @var array{0:string,1:string}|null
   */
  public ?array $sort = NULL;

  /**
   * Recorded range definition.
   *
   * @var array{0:int,1:int}|null
   */
  public ?array $range = NULL;

  /**
   * Whether accessCheck(FALSE) was requested.
   */
  public bool $accessCheck = FALSE;

  /**
   * Constructs the query double.
   *
   * @param int[] $resultIds
   *   Query result IDs.
   */
  public function __construct(
    private array $resultIds,
  ) {}

  /**
   * Records access-check state.
   */
  public function accessCheck(bool $accessCheck): self {
    $this->accessCheck = ($accessCheck === FALSE);
    return $this;
  }

  /**
   * Records a query condition.
   */
  public function condition(string $field, mixed $value, string $operator = '='): self {
    $this->conditions[] = [$field, $value, $operator];
    return $this;
  }

  /**
   * Records sort order.
   */
  public function sort(string $field, string $direction = 'ASC'): self {
    $this->sort = [$field, $direction];
    return $this;
  }

  /**
   * Records range.
   */
  public function range(int $start, int $length): self {
    $this->range = [$start, $length];
    return $this;
  }

  /**
   * Returns configured results.
   *
   * @return int[]
   *   Query result IDs.
   */
  public function execute(): array {
    return $this->resultIds;
  }

}
