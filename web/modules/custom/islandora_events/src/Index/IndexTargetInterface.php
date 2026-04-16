<?php

namespace Drupal\islandora_events\Index;

/**
 * Interface for pluggable indexing targets.
 */
interface IndexTargetInterface {

  /**
   * Gets this target's stable ID.
   */
  public function getTargetId(): string;

  /**
   * Gets this target's human-readable label.
   */
  public function getLabel(): string;

  /**
   * Gets the message class used when queueing events for this target.
   *
   * This controls which Messenger message subclass is dispatched for this
   * target. Returning the base IndexEventMessage keeps the target on the
   * generic custom-index transport unless routing is overridden elsewhere.
   * Returning a dedicated subclass allows a module to add target-specific
   * transport routing and workers.
   *
   * @return class-string<\Drupal\islandora_events\Message\IndexEventMessage>
   *   Message class name.
   */
  public function getMessageClass(): string;

  /**
   * Gets the config object that owns this target's settings.
   */
  public function getConfigName(): string;

  /**
   * Returns TRUE when this target is enabled in configuration.
   *
   * Disabled targets are treated as non-runnable by handlers and synchronous
   * run-now flows. Queue rows for disabled targets are abandoned rather than
   * retried indefinitely.
   */
  public function isEnabled(): bool;

  /**
   * Returns TRUE if this target should process the given source event.
   */
  public function supports(string $entityType, string $eventType): bool;

  /**
   * Processes one indexing event.
   *
   * Implementations should either complete successfully or throw an exception.
   * Returning normally signals success. Throwing signals failure and allows the
   * caller to apply retry, failure, and ledger lifecycle policy. Implementers
   * should not mark ledger state directly from this method.
   */
  public function process(IndexEventContext $context): void;

}
