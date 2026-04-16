<?php

namespace Drupal\islandora_events_backfill\Messenger;

use Drupal\islandora_events_backfill\Message\BackfillScanMessage;
use Drupal\islandora_events_backfill\Plugin\DerivativeScannerManager;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Emits recurring backfill scan messages for configured scanner plugins.
 */
#[AsSchedule('islandora_events_backfill')]
final class BackfillScanScheduleProvider implements ScheduleProviderInterface {

  /**
   * Constructs the provider.
   */
  public function __construct(
    private DerivativeScannerManager $scannerManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getSchedule(): Schedule {
    $schedule = new Schedule();
    foreach ($this->scannerManager->getSortedDefinitions() as $pluginId => $definition) {
      $frequencyHours = max(1, (int) ($definition['frequency'] ?? 24));
      $schedule->add(
        RecurringMessage::every(sprintf('%d hours', $frequencyHours), new BackfillScanMessage($pluginId)),
      );
    }

    return $schedule;
  }

}
