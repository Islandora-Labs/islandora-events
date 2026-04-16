<?php

namespace Drupal\islandora_events\EventSubscriber;

use Drupal\islandora_events\Service\DeferredMediaFileReactionQueue;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Flushes deferred media derivative-file reactions at request termination.
 */
final class DeferredMediaFileReactionSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the subscriber.
   */
  public function __construct(
    private DeferredMediaFileReactionQueue $deferredMediaFileReactionQueue,
  ) {}

  /**
   * Flushes deferred media reactions.
   */
  public function flush(): void {
    $this->deferredMediaFileReactionQueue->flush();
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::TERMINATE => 'flush',
      ConsoleEvents::TERMINATE => 'flush',
    ];
  }

}
