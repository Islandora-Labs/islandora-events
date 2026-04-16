<?php

namespace Drupal\islandora_events_backfill\Plugin\DerivativeScanner;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\islandora_events_backfill\Attribute\DerivativeScanner;
use Drupal\islandora_events_backfill\Plugin\DerivativeScannerBase;

/**
 * Scans for missing thumbnails.
 */
#[DerivativeScanner(
  id: 'thumbnails',
  label: new TranslatableMarkup('Thumbnails'),
  description: new TranslatableMarkup('Thumbnails for PDFs and images'),
  action: 'image_generate_a_thumbnail_from_an_original_file',
  entity_type: 'node',
  event_type: 'derivative_scan.thumbnails',
  frequency: 12,
  priority: 15,
)]
class Thumbnails extends DerivativeScannerBase {

  /**
   * {@inheritdoc}
   */
  protected function getScanQuery(): array {
    return [
      'sql' => "SELECT mo.field_media_of_target_id AS entity_id
      FROM media_field_data m
      INNER JOIN media__field_media_of mo ON m.mid = mo.entity_id
      INNER JOIN media__field_media_use mu ON m.mid = mu.entity_id
      " . $this->mediaUseJoin('mu', 'source_uri') . "
      INNER JOIN media__field_mime_type mt ON m.mid = mt.entity_id
      WHERE source_uri.field_external_uri_uri = :original
        AND mo.bundle NOT IN ('audio', 'video')
        AND mt.field_mime_type_value NOT IN ('application/zip', 'application/warc')
        AND mo.field_media_of_target_id NOT IN (
          SELECT mo2.field_media_of_target_id
          FROM media_field_data m2
          INNER JOIN media__field_media_of mo2 ON m2.mid = mo2.entity_id
          INNER JOIN media__field_media_use mu2 ON m2.mid = mu2.entity_id
          " . $this->mediaUseJoin('mu2', 'thumb_uri') . "
          WHERE thumb_uri.field_external_uri_uri = :thumbnail
        )
      GROUP BY mo.field_media_of_target_id",
      'args' => [
        ':original' => self::MEDIA_USE_ORIGINAL_FILE,
        ':thumbnail' => self::MEDIA_USE_THUMBNAIL,
      ],
    ];
  }

}
