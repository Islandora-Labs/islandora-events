<?php

declare(strict_types=1);

namespace Drupal\islandora_events_otel\EventSubscriber;

use Drupal\islandora_events_otel\Service\LedgerTelemetryService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * Persists OTEL lifecycle metadata for Messenger worker activity.
 */
class WorkerTelemetrySubscriber implements EventSubscriberInterface {

  /**
   * Constructs a worker telemetry subscriber.
   */
  public function __construct(
    private LedgerTelemetryService $ledgerTelemetry,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      WorkerMessageReceivedEvent::class => 'onReceived',
      WorkerMessageHandledEvent::class => 'onHandled',
      WorkerMessageFailedEvent::class => 'onFailed',
    ];
  }

  /**
   * Tracks message receipt.
   */
  public function onReceived(WorkerMessageReceivedEvent $event): void {
    $this->ledgerTelemetry->recordWorkerLifecycle('received', $event->getEnvelope());
  }

  /**
   * Tracks successful handling.
   */
  public function onHandled(WorkerMessageHandledEvent $event): void {
    $this->ledgerTelemetry->recordWorkerLifecycle('handled', $event->getEnvelope());
  }

  /**
   * Tracks failed handling.
   */
  public function onFailed(WorkerMessageFailedEvent $event): void {
    $this->ledgerTelemetry->recordWorkerLifecycle('failed', $event->getEnvelope());
  }

}
