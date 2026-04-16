<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events\Unit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\islandora_events\Index\IndexPayloadBuilder;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for index payload builder.
 */
final class IndexPayloadBuilderUnitTest extends UnitTestCase {

  /**
   * Tests payload contains expected normalized keys.
   */
  public function testBuild(): void {
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('getEntityTypeId')->willReturn('node');
    $entity->method('id')->willReturn(42);
    $entity->method('uuid')->willReturn('u-42');

    $builder = new IndexPayloadBuilder();
    $payload = $builder->build($entity, 'update', 'fedora');

    static::assertSame('node', $payload['entity_type']);
    static::assertSame(42, $payload['entity_id']);
    static::assertSame('u-42', $payload['entity_uuid']);
    static::assertSame('update', $payload['operation']);
    static::assertSame('fedora', $payload['target']);
    static::assertArrayHasKey('metadata', $payload);
  }

}
