<?php

declare(strict_types=1);

namespace Drupal\islandora_events_metrics\EventSubscriber;

use Drupal\islandora_events_metrics\Service\MetricsStore;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;

/**
 * Records low-cost worker counters for Prometheus scraping.
 */
final class WorkerMetricsSubscriber implements EventSubscriberInterface {

  /**
   * In-memory per-envelope start timestamps for one worker process.
   *
   * @var array<int, float>
   */
  private array $startedAt = [];

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    private MetricsStore $store,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      WorkerMessageReceivedEvent::class => 'onReceived',
      WorkerMessageHandledEvent::class => 'onHandled',
      WorkerMessageRetriedEvent::class => 'onRetried',
      WorkerMessageFailedEvent::class => 'onFailed',
    ];
  }

  /**
   * Tracks when one message started work in this process.
   */
  public function onReceived(WorkerMessageReceivedEvent $event): void {
    $this->startedAt[spl_object_id($event->getEnvelope())] = microtime(TRUE);
  }

  /**
   * Tracks successful message handling.
   */
  public function onHandled(WorkerMessageHandledEvent $event): void {
    $labels = [
      'message' => $event->getEnvelope()->getMessage()::class,
    ];
    $this->store->increment('islandora_events_worker_handled_total', $labels);
    $this->recordDuration($event->getEnvelope(), $labels);
  }

  /**
   * Tracks retry scheduling.
   */
  public function onRetried(WorkerMessageRetriedEvent $event): void {
    $this->store->increment('islandora_events_worker_retry_total', [
      'message' => $event->getEnvelope()->getMessage()::class,
    ]);
  }

  /**
   * Tracks terminal failures.
   */
  public function onFailed(WorkerMessageFailedEvent $event): void {
    $labels = [
      'message' => $event->getEnvelope()->getMessage()::class,
    ];
    $this->recordDuration($event->getEnvelope(), $labels);

    if ($event->willRetry()) {
      return;
    }

    $this->store->increment('islandora_events_worker_failed_total', $labels);
  }

  /**
   * Records worker duration counters for one handled or failed message.
   *
   * @param object $envelope
   *   The processed Messenger envelope object.
   * @param array<string, string> $labels
   *   Metric labels.
   */
  private function recordDuration(object $envelope, array $labels): void {
    $id = spl_object_id($envelope);
    $startedAt = $this->startedAt[$id] ?? NULL;
    unset($this->startedAt[$id]);

    if (!is_float($startedAt)) {
      return;
    }

    $duration = max(0, microtime(TRUE) - $startedAt);
    $this->store->increment('islandora_events_worker_duration_seconds_sum', $labels, $duration);
    $this->store->increment('islandora_events_worker_duration_seconds_count', $labels);
  }

}
