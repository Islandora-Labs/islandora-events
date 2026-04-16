<?php

namespace Drupal\islandora_events_backfill\Plugin\DerivativeScanner;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\islandora_events_backfill\Attribute\DerivativeScanner;
use Drupal\islandora_events_backfill\Plugin\DerivativeScannerBase;

/**
 * Scans for missing FITS metadata.
 */
#[DerivativeScanner(
  id: 'fits_metadata',
  label: new TranslatableMarkup('FITS Metadata'),
  description: new TranslatableMarkup('FITS technical metadata'),
  action: 'generate_a_technical_metadata_derivative',
  entity_type: 'node',
  event_type: 'derivative_scan.fits_metadata',
  frequency: 24,
  priority: 30,
)]
class FitsMetadata extends DerivativeScannerBase {

  /**
   * {@inheritdoc}
   */
  protected function getScanQuery(): array {
    return [
      'sql' => "SELECT DISTINCT mo.field_media_of_target_id AS entity_id
      FROM media__field_media_of mo
      INNER JOIN media__field_media_use mu ON mo.entity_id = mu.entity_id
      " . $this->mediaUseJoin('mu', 'source_uri') . "
      WHERE source_uri.field_external_uri_uri = :original
        AND NOT EXISTS (
          SELECT 1
          FROM media__field_media_of mo2
          INNER JOIN media__field_media_use mu2 ON mo2.entity_id = mu2.entity_id
          " . $this->mediaUseJoin('mu2', 'fits_uri') . "
          WHERE mo2.field_media_of_target_id = mo.field_media_of_target_id
            AND fits_uri.field_external_uri_uri = :fits
        )",
      'args' => [
        ':original' => self::MEDIA_USE_ORIGINAL_FILE,
        ':fits' => self::MEDIA_USE_FITS,
      ],
    ];
  }

}
