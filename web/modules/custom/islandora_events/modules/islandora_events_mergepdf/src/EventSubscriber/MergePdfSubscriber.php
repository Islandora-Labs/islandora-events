<?php

namespace Drupal\islandora_events_mergepdf\EventSubscriber;

use Drupal\islandora_events_mergepdf\Service\MergePdfPendingStore;
use Drupal\media\MediaInterface;
use Psr\Log\LoggerInterface;

/**
 * Reacts to media hooks for mergepdf orchestration.
 */
class MergePdfSubscriber {

  /**
   * Constructs a new MergePdfSubscriber.
   */
  public function __construct(
    private LoggerInterface $logger,
    private MergePdfPendingStore $pendingStore,
  ) {}

  /**
   * Responds to media insert events and schedules mergepdf aggregation.
   */
  public function mediaInsert(MediaInterface $media): void {
    $this->onMediaChange($media);
  }

  /**
   * Responds to media update events and schedules mergepdf aggregation.
   */
  public function mediaUpdate(MediaInterface $media): void {
    $this->onMediaChange($media);
  }

  /**
   * Shared media-change logic.
   */
  private function onMediaChange(MediaInterface $media): void {
    if (!$media->hasField('field_media_of') ||
      $media->field_media_of->isEmpty() ||
      is_null($media->field_media_of->entity)) {
      return;
    }

    if (!$media->hasField('field_media_use') ||
      $media->field_media_use->isEmpty() ||
      is_null($media->field_media_use->entity)) {
      return;
    }

    $mediaUseUri = $media->field_media_use->entity->field_external_uri->uri ?? '';
    if ($mediaUseUri !== 'http://pcdm.org/use#ServiceFile') {
      return;
    }

    foreach ($media->field_media_of as $fieldMediaOf) {
      if (is_null($fieldMediaOf->entity)) {
        continue;
      }

      $node = $fieldMediaOf->entity;
      if (!$node->hasField('field_model') ||
        $node->field_model->isEmpty() ||
        is_null($node->field_model->entity) ||
        $node->field_model->entity->field_external_uri->uri !== 'http://id.loc.gov/ontologies/bibframe/part') {
        continue;
      }

      foreach ($node->field_member_of as $parent) {
        if (is_null($parent->entity)) {
          continue;
        }

        $this->pendingStore->add('node', (int) $parent->entity->id());
      }
    }

    $this->logger->info('Marked mergepdf reconciliation pending for media @mid', [
      '@mid' => $media->id(),
    ]);
  }

}
