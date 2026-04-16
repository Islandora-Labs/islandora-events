<?php

namespace Drupal\islandora_events_backfill\Plugin\DerivativeScanner;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\islandora_events_backfill\Attribute\DerivativeScanner;
use Drupal\islandora_events_backfill\Plugin\DerivativeScannerBase;

/**
 * Scans for missing OCR derivatives.
 */
#[DerivativeScanner(
  id: 'ocr_derivatives',
  label: new TranslatableMarkup('OCR Derivatives'),
  description: new TranslatableMarkup('OCR derivatives for digital documents'),
  action: 'get_ocr_from_image',
  entity_type: 'node',
  event_type: 'derivative_scan.ocr_derivatives',
  frequency: 6,
  priority: 5,
)]
class OcrDerivatives extends DerivativeScannerBase {

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
      WHERE m.created > UNIX_TIMESTAMP() - 8640000
        AND source_uri.field_external_uri_uri = :original
        AND m.bundle IN ('document')
        AND mo.field_media_of_target_id NOT IN (
          SELECT mo2.field_media_of_target_id
          FROM media_field_data m2
          INNER JOIN media__field_media_of mo2 ON m2.mid = mo2.entity_id
          INNER JOIN media__field_media_use mu2 ON m2.mid = mu2.entity_id
          " . $this->mediaUseJoin('mu2', 'ocr_uri') . "
          WHERE ocr_uri.field_external_uri_uri = :extracted_text
        )
      GROUP BY mo.field_media_of_target_id",
      'args' => [
        ':original' => self::MEDIA_USE_ORIGINAL_FILE,
        ':extracted_text' => self::MEDIA_USE_EXTRACTED_TEXT,
      ],
    ];
  }

}
