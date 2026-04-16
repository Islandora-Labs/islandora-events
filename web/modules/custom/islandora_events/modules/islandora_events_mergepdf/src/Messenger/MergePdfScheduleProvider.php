<?php

declare(strict_types=1);

namespace Drupal\islandora_events_mergepdf\Messenger;

use Drupal\islandora_events_mergepdf\Message\SweepPendingMergePdfMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Emits recurring Merge PDF reconciliation sweep messages.
 */
#[AsSchedule('islandora_events_mergepdf')]
final class MergePdfScheduleProvider implements ScheduleProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function getSchedule(): Schedule {
    return (new Schedule())->add(
      RecurringMessage::every('5 minutes', new SweepPendingMergePdfMessage()),
    );
  }

}
