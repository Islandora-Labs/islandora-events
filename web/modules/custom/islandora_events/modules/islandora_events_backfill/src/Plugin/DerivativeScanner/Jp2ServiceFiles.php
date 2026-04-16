<?php

namespace Drupal\islandora_events_backfill\Plugin\DerivativeScanner;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\islandora_events_backfill\Attribute\DerivativeScanner;
use Drupal\islandora_events_backfill\Plugin\DerivativeScannerBase;

/**
 * Scans for missing JP2 service files.
 */
#[DerivativeScanner(
  id: 'jp2_service_files',
  label: new TranslatableMarkup('JP2 Service Files'),
  description: new TranslatableMarkup('JP2 service files for TIFF/JP2 originals'),
  action: 'generate_a_jp2_service_file',
  entity_type: 'node',
  event_type: 'derivative_scan.jp2_service_files',
  frequency: 24,
  priority: 10,
)]
class Jp2ServiceFiles extends DerivativeScannerBase {

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
      INNER JOIN media__field_media_file mf ON mf.entity_id = mu.entity_id
      INNER JOIN file_managed f ON f.fid = mf.field_media_file_target_id
      WHERE source_uri.field_external_uri_uri = :original
        AND (f.uri LIKE '%.tif' OR f.uri LIKE '%.tiff' OR f.uri LIKE '%.jp2')
        AND mo.field_media_of_target_id NOT IN (
          SELECT mo2.field_media_of_target_id
          FROM media_field_data m2
          INNER JOIN media__field_media_of mo2 ON m2.mid = mo2.entity_id
          INNER JOIN media__field_media_use mu2 ON m2.mid = mu2.entity_id
          " . $this->mediaUseJoin('mu2', 'service_uri') . "
          WHERE service_uri.field_external_uri_uri = :service
        )
      GROUP BY mo.field_media_of_target_id",
      'args' => [
        ':original' => self::MEDIA_USE_ORIGINAL_FILE,
        ':service' => self::MEDIA_USE_SERVICE_FILE,
      ],
    ];
  }

}
