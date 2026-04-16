<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events\Unit;

use Drupal\islandora_events\Index\IndexTargetInterface;
use Drupal\islandora_events\Index\IndexTargetManager;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for index target manager.
 */
final class IndexTargetManagerUnitTest extends UnitTestCase {

  /**
   * Tests enabled target filtering by support and config state.
   */
  public function testGetEnabledTargetIdsFor(): void {
    $fedora = $this->createMock(IndexTargetInterface::class);
    $fedora->method('getTargetId')->willReturn('fedora');
    $fedora->method('isEnabled')->willReturn(TRUE);
    $fedora->method('supports')->with('node', 'update')->willReturn(TRUE);

    $blazegraph = $this->createMock(IndexTargetInterface::class);
    $blazegraph->method('getTargetId')->willReturn('blazegraph');
    $blazegraph->method('isEnabled')->willReturn(FALSE);

    $manager = new IndexTargetManager([$fedora, $blazegraph]);
    $ids = $manager->getEnabledTargetIdsFor('node', 'update');

    static::assertSame(['fedora'], $ids);
    static::assertSame(['fedora' => $fedora, 'blazegraph' => $blazegraph], $manager->all());
    static::assertSame($fedora, $manager->getTarget('fedora'));
    static::assertNull($manager->getTarget('unknown'));
  }

}
