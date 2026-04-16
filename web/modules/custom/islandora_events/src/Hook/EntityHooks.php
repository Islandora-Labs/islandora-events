<?php

namespace Drupal\islandora_events\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\file\FileInterface;
use Drupal\islandora\IslandoraUtils;
use Drupal\islandora\MediaSource\MediaSourceService;
use Drupal\islandora_events\Service\DeferredMediaFileReactionQueue;
use Drupal\islandora_events\Service\IndexEventService;
use Drupal\taxonomy\TermInterface;

/**
 * Hook implementations for entity operations.
 */
class EntityHooks {

  /**
   * Constructs a new EntityHooks instance.
   *
   * @param \Drupal\islandora\IslandoraUtils $islandoraUtils
   *   Islandora utility service.
   * @param \Drupal\islandora\MediaSource\MediaSourceService $mediaSourceService
   *   Islandora media source service.
   * @param \Drupal\islandora_events\Service\IndexEventService $indexEventService
   *   Index event queueing service.
   * @param \Drupal\islandora_events\Service\DeferredMediaFileReactionQueue $deferredMediaFileReactionQueue
   *   Deferred media-file reaction queue.
   */
  public function __construct(
    private IslandoraUtils $islandoraUtils,
    private MediaSourceService $mediaSourceService,
    private IndexEventService $indexEventService,
    private DeferredMediaFileReactionQueue $deferredMediaFileReactionQueue,
  ) {}

  /**
   * Implements hook_ENTITY_TYPE_insert() for media entities.
   */
  public function mediaInsert(EntityInterface $media): void {
    $this->islandoraUtils->executeMediaReactions(
      '\Drupal\islandora\Plugin\ContextReaction\IndexReaction',
      $media
    );
    $this->indexEventService->queueEntityEvent($media, 'insert');

    $node = $this->islandoraUtils->getParentNode($media);
    if ($node) {
      $this->islandoraUtils->executeDerivativeReactions(
        '\Drupal\islandora\Plugin\ContextReaction\DerivativeReaction',
        $node,
        $media
      );
    }

    $this->deferredMediaFileReactionQueue->defer($media);
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for media entities.
   */
  public function mediaUpdate(EntityInterface $media): void {
    $original = $media->getOriginal();
    if (!$original || !$this->islandoraUtils->haveFieldsChanged($media, $original)) {
      return;
    }

    $this->islandoraUtils->executeMediaReactions(
      '\Drupal\islandora\Plugin\ContextReaction\IndexReaction',
      $media
    );
    $this->indexEventService->queueEntityEvent($media, 'update');

    $sourceField = $this->mediaSourceService->getSourceFieldName($media->bundle());
    if (empty($sourceField)) {
      return;
    }

    if ($media->get($sourceField)->equals($original->get($sourceField))) {
      return;
    }

    $node = $this->islandoraUtils->getParentNode($media);
    if ($node) {
      $this->islandoraUtils->executeDerivativeReactions(
        '\Drupal\islandora\Plugin\ContextReaction\DerivativeReaction',
        $node,
        $media
      );
      $this->islandoraUtils->executeMediaReactions(
        '\Drupal\islandora\Plugin\ContextReaction\DerivativeFileReaction',
        $media
      );
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for media entities.
   */
  public function mediaDelete(EntityInterface $media): void {
    $this->islandoraUtils->executeMediaReactions(
      '\Drupal\islandora\Plugin\ContextReaction\DeleteReaction',
      $media
    );
    $this->indexEventService->queueEntityEvent($media, 'delete');
  }

  /**
   * Implements hook_ENTITY_TYPE_insert() for node entities.
   */
  public function nodeInsert(EntityInterface $node): void {
    $this->islandoraUtils->executeNodeReactions(
      '\Drupal\islandora\Plugin\ContextReaction\IndexReaction',
      $node
    );
    $this->indexEventService->queueEntityEvent($node, 'insert');
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for node entities.
   */
  public function nodeUpdate(EntityInterface $node): void {
    $original = $node->getOriginal();
    if (!$original || !$this->islandoraUtils->haveFieldsChanged($node, $original)) {
      return;
    }

    $this->islandoraUtils->executeNodeReactions(
      '\Drupal\islandora\Plugin\ContextReaction\IndexReaction',
      $node
    );
    $this->indexEventService->queueEntityEvent($node, 'update');
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for node entities.
   */
  public function nodeDelete(EntityInterface $node): void {
    $this->islandoraUtils->executeNodeReactions(
      '\Drupal\islandora\Plugin\ContextReaction\DeleteReaction',
      $node
    );
    $this->indexEventService->queueEntityEvent($node, 'delete');
  }

  /**
   * Implements hook_ENTITY_TYPE_insert() for file entities.
   */
  public function fileInsert(FileInterface $file): void {
    $this->islandoraUtils->executeFileReactions(
      '\Drupal\islandora\Plugin\ContextReaction\IndexReaction',
      $file
    );
    $this->indexEventService->queueEntityEvent($file, 'insert');
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for file entities.
   */
  public function fileUpdate(FileInterface $file): void {
    $original = $file->getOriginal();
    if ($file->hasField('sha1') && $original && $original->hasField('sha1')
      && $file->sha1->getString() === $original->sha1->getString()) {
      return;
    }

    $this->islandoraUtils->executeFileReactions(
      '\Drupal\islandora\Plugin\ContextReaction\IndexReaction',
      $file
    );
    $this->indexEventService->queueEntityEvent($file, 'update');

    foreach ($this->islandoraUtils->getReferencingMedia($file->id()) as $media) {
      $node = $this->islandoraUtils->getParentNode($media);
      if ($node) {
        $this->islandoraUtils->executeDerivativeReactions(
          '\Drupal\islandora\Plugin\ContextReaction\DerivativeReaction',
          $node,
          $media
        );
      }
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for file entities.
   */
  public function fileDelete(FileInterface $file): void {
    $this->islandoraUtils->executeFileReactions(
      '\Drupal\islandora\Plugin\ContextReaction\DeleteReaction',
      $file
    );
    $this->indexEventService->queueEntityEvent($file, 'delete');
  }

  /**
   * Implements hook_ENTITY_TYPE_insert() for taxonomy terms.
   */
  public function termInsert(TermInterface $term): void {
    $this->islandoraUtils->executeTermReactions(
      '\Drupal\islandora\Plugin\ContextReaction\IndexReaction',
      $term
    );
    $this->indexEventService->queueEntityEvent($term, 'insert');
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for taxonomy terms.
   */
  public function termUpdate(TermInterface $term): void {
    $original = $term->getOriginal();
    if (!$original || !$this->islandoraUtils->haveFieldsChanged($term, $original)) {
      return;
    }

    $this->islandoraUtils->executeTermReactions(
      '\Drupal\islandora\Plugin\ContextReaction\IndexReaction',
      $term
    );
    $this->indexEventService->queueEntityEvent($term, 'update');
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for taxonomy terms.
   */
  public function termDelete(TermInterface $term): void {
    $this->islandoraUtils->executeTermReactions(
      '\Drupal\islandora\Plugin\ContextReaction\DeleteReaction',
      $term
    );
    $this->indexEventService->queueEntityEvent($term, 'delete');
  }

}
