<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events\Unit;

use Drupal\islandora\IslandoraUtils;
use Drupal\islandora_events\Service\DeferredMediaFileReactionQueue;
use Drupal\media\MediaInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for deferred media-file reactions.
 */
final class DeferredMediaFileReactionQueueUnitTest extends UnitTestCase {

  /**
   * Tests deferred media reactions flush once per queued media object.
   */
  public function testFlushExecutesDeferredMediaReactions(): void {
    $mediaA = $this->createMock(MediaInterface::class);
    $mediaB = $this->createMock(MediaInterface::class);

    $utils = $this->createMock(IslandoraUtils::class);
    $utils->expects($this->exactly(2))
      ->method('executeMediaReactions')
      ->willReturnCallback(function (string $reaction, object $media) use ($mediaA, $mediaB): void {
        static::assertSame('\Drupal\islandora\Plugin\ContextReaction\DerivativeFileReaction', $reaction);
        static $seen = [];
        $seen[] = $media;
        static::assertContains($media, [$mediaA, $mediaB]);
      });

    $queue = new DeferredMediaFileReactionQueue($utils);
    $queue->defer($mediaA);
    $queue->defer($mediaA);
    $queue->defer($mediaB);

    $queue->flush();
    $queue->flush();
  }

}
