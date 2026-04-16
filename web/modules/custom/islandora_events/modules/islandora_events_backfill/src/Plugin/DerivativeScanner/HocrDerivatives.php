<?php

namespace Drupal\islandora_events_backfill\Plugin\DerivativeScanner;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\islandora_events_backfill\Attribute\DerivativeScanner;
use Drupal\islandora_events_backfill\Plugin\DerivativeScannerBase;

/**
 * Scans for missing hOCR derivatives.
 */
#[DerivativeScanner(
  id: 'hocr_derivatives',
  label: new TranslatableMarkup('hOCR Derivatives'),
  description: new TranslatableMarkup('hOCR derivatives for TIFF/JP2 files'),
  action: 'generate_hocr_from_an_image',
  entity_type: 'node',
  event_type: 'derivative_scan.hocr_derivatives',
  frequency: 24,
  priority: 25,
)]
class HocrDerivatives extends DerivativeScannerBase {

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
        AND m.created > UNIX_TIMESTAMP() - 8640000
        AND (f.uri LIKE '%.tif' OR f.uri LIKE '%.tiff' OR f.uri LIKE '%.jp2')
        AND mo.field_media_of_target_id NOT IN (
          SELECT mo2.field_media_of_target_id
          FROM media_field_data m2
          INNER JOIN media__field_media_of mo2 ON m2.mid = mo2.entity_id
          INNER JOIN media__field_media_use mu2 ON m2.mid = mu2.entity_id
          " . $this->mediaUseJoin('mu2', 'hocr_uri') . "
          WHERE hocr_uri.field_external_uri_uri = :hocr
        )
      GROUP BY mo.field_media_of_target_id",
      'args' => [
        ':original' => self::MEDIA_USE_ORIGINAL_FILE,
        ':hocr' => self::MEDIA_USE_HOCR,
      ],
    ];
  }

}
