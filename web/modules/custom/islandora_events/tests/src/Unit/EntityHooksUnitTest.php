<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events\Unit;

use Drupal\islandora\IslandoraUtils;
use Drupal\islandora\MediaSource\MediaSourceService;
use Drupal\islandora_events\Hook\EntityHooks;
use Drupal\islandora_events\Service\DeferredMediaFileReactionQueue;
use Drupal\islandora_events\Service\IndexEventService;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for entity hook orchestration.
 */
final class EntityHooksUnitTest extends UnitTestCase {

  /**
   * Tests that media inserts execute Islandora reactions.
   */
  public function testMediaInsertExecutesConfiguredReactions(): void {
    $media = $this->createMock(MediaInterface::class);

    $islandoraUtils = $this->createMock(IslandoraUtils::class);
    $islandoraUtils->expects($this->once())
      ->method('executeMediaReactions')
      ->with('\Drupal\islandora\Plugin\ContextReaction\IndexReaction', $media);
    $islandoraUtils->expects($this->once())
      ->method('getParentNode')
      ->with($media)
      ->willReturn($node = $this->createMock(NodeInterface::class));
    $islandoraUtils->expects($this->once())
      ->method('executeDerivativeReactions')
      ->with('\Drupal\islandora\Plugin\ContextReaction\DerivativeReaction', $node, $media);

    $mediaSourceService = $this->createMock(MediaSourceService::class);
    $indexEventService = $this->createMock(IndexEventService::class);
    $indexEventService->expects($this->once())
      ->method('queueEntityEvent')
      ->with($media, 'insert');
    $deferredMediaFileReactionQueue = $this->createMock(DeferredMediaFileReactionQueue::class);
    $deferredMediaFileReactionQueue->expects($this->once())
      ->method('defer')
      ->with($media);
    $hooks = new EntityHooks($islandoraUtils, $mediaSourceService, $indexEventService, $deferredMediaFileReactionQueue);
    $hooks->mediaInsert($media);
  }

  /**
   * Tests that node deletes execute delete reactions.
   */
  public function testNodeDeleteExecutesDeleteReaction(): void {
    $node = $this->createMock(NodeInterface::class);

    $islandoraUtils = $this->createMock(IslandoraUtils::class);
    $islandoraUtils->expects($this->once())
      ->method('executeNodeReactions')
      ->with('\Drupal\islandora\Plugin\ContextReaction\DeleteReaction', $node);
    $mediaSourceService = $this->createMock(MediaSourceService::class);
    $indexEventService = $this->createMock(IndexEventService::class);
    $indexEventService->expects($this->once())
      ->method('queueEntityEvent')
      ->with($node, 'delete');
    $hooks = new EntityHooks($islandoraUtils, $mediaSourceService, $indexEventService, $this->createMock(DeferredMediaFileReactionQueue::class));
    $hooks->nodeDelete($node);
  }

  /**
   * Tests node updates are skipped when no fields changed.
   */
  public function testNodeUpdateSkipsWhenFieldsUnchanged(): void {
    $original = $this->createMock(NodeInterface::class);
    $node = $this->createMock(NodeInterface::class);
    $node->method('getOriginal')->willReturn($original);

    $islandoraUtils = $this->createMock(IslandoraUtils::class);
    $islandoraUtils->expects($this->once())
      ->method('haveFieldsChanged')
      ->with($node, $original)
      ->willReturn(FALSE);
    $islandoraUtils->expects($this->never())->method('executeNodeReactions');

    $mediaSourceService = $this->createMock(MediaSourceService::class);
    $indexEventService = $this->createMock(IndexEventService::class);
    $indexEventService->expects($this->never())->method('queueEntityEvent');
    $hooks = new EntityHooks($islandoraUtils, $mediaSourceService, $indexEventService, $this->createMock(DeferredMediaFileReactionQueue::class));
    $hooks->nodeUpdate($node);
  }

  /**
   * Tests file inserts queue index events.
   */
  public function testFileInsertQueuesIndexEvent(): void {
    $file = $this->createMock(FileInterface::class);

    $islandoraUtils = $this->createMock(IslandoraUtils::class);
    $islandoraUtils->expects($this->once())
      ->method('executeFileReactions')
      ->with('\Drupal\islandora\Plugin\ContextReaction\IndexReaction', $file);

    $mediaSourceService = $this->createMock(MediaSourceService::class);
    $indexEventService = $this->createMock(IndexEventService::class);
    $indexEventService->expects($this->once())
      ->method('queueEntityEvent')
      ->with($file, 'insert');
    $hooks = new EntityHooks($islandoraUtils, $mediaSourceService, $indexEventService, $this->createMock(DeferredMediaFileReactionQueue::class));
    $hooks->fileInsert($file);
  }

  /**
   * Tests term deletes queue index events.
   */
  public function testTermDeleteQueuesIndexEvent(): void {
    $term = $this->createMock(TermInterface::class);

    $islandoraUtils = $this->createMock(IslandoraUtils::class);
    $islandoraUtils->expects($this->once())
      ->method('executeTermReactions')
      ->with('\Drupal\islandora\Plugin\ContextReaction\DeleteReaction', $term);

    $mediaSourceService = $this->createMock(MediaSourceService::class);
    $indexEventService = $this->createMock(IndexEventService::class);
    $indexEventService->expects($this->once())
      ->method('queueEntityEvent')
      ->with($term, 'delete');
    $hooks = new EntityHooks($islandoraUtils, $mediaSourceService, $indexEventService, $this->createMock(DeferredMediaFileReactionQueue::class));
    $hooks->termDelete($term);
  }

  /**
   * Tests term updates are skipped when no fields changed.
   */
  public function testTermUpdateSkipsWhenFieldsUnchanged(): void {
    $original = $this->createMock(TermInterface::class);
    $term = $this->createMock(TermInterface::class);
    $term->method('getOriginal')->willReturn($original);

    $islandoraUtils = $this->createMock(IslandoraUtils::class);
    $islandoraUtils->expects($this->once())
      ->method('haveFieldsChanged')
      ->with($term, $original)
      ->willReturn(FALSE);
    $islandoraUtils->expects($this->never())->method('executeTermReactions');

    $mediaSourceService = $this->createMock(MediaSourceService::class);
    $indexEventService = $this->createMock(IndexEventService::class);
    $indexEventService->expects($this->never())->method('queueEntityEvent');
    $hooks = new EntityHooks($islandoraUtils, $mediaSourceService, $indexEventService, $this->createMock(DeferredMediaFileReactionQueue::class));
    $hooks->termUpdate($term);
  }

}
