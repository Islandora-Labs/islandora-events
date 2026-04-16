<?php

namespace Drupal\islandora_events\Service;

use Drupal\Core\Action\ConfigurableActionInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\islandora\Event\StompHeaderEvent;
use Drupal\islandora\Event\StompHeaderEventInterface;
use Drupal\islandora\EventGenerator\EventGeneratorInterface;
use Drupal\islandora_events\Message\IslandoraDerivativeMessage;
use Drupal\sm_ledger\Entity\EventRecord;
use Drupal\sm_ledger\Service\LedgerDispatchService;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Persists generate-derivative jobs and dispatches them onto Messenger.
 *
 * This service is CLI-agnostic by design so it can be called from Drush today
 * and from Drupal core's Symfony Console entry point later.
 */
class DerivativeQueueService {

  /**
   * Action storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $actionStorage;

  /**
   * User storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $userStorage;

  /**
   * Constructs the queue service.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
    protected LedgerDispatchService $dispatch,
    protected LoggerInterface $logger,
    protected EventGeneratorInterface $eventGenerator,
    protected EventDispatcherInterface $eventDispatcher,
    protected DerivativeActionDataExtractor $derivativeActionDataExtractor,
  ) {
    $this->actionStorage = $entityTypeManager->getStorage('action');
    $this->userStorage = $entityTypeManager->getStorage('user');
  }

  /**
   * Builds and queues a derivative payload from a configured action entity.
   */
  public function enqueueConfiguredAction(
    EntityInterface $entity,
    string $actionId,
    ?string $triggerEventType = NULL,
    ?string $correlationKey = NULL,
    string $messageClass = IslandoraDerivativeMessage::class,
  ): void {
    $action = $this->actionStorage->load($actionId);
    if (!$action instanceof ConfigurableActionInterface) {
      throw new \RuntimeException(sprintf(
        'Derivative action "%s" could not be loaded.',
        $actionId,
      ));
    }

    $plugin = $action->getPlugin();

    $user = $this->userStorage->load((int) $this->currentUser->id());
    if (!$user) {
      throw new \RuntimeException(
        'Unable to load the current user for derivative payload generation.',
      );
    }

    $configuration = $plugin->getConfiguration();
    $data = $this->derivativeActionDataExtractor->extract($plugin, $entity);
    $headers = $this->buildHeaders($entity, $user, $data, $configuration);
    $payload = (string) $this->eventGenerator->generateEvent($entity, $user, $data);

    $this->enqueue(
      $entity,
      $payload,
      $headers,
      $configuration,
      $actionId,
      $triggerEventType,
      $correlationKey,
      $messageClass,
    );
  }

  /**
   * Queues a derivative action payload for SM-backed processing.
   *
   * With SQL-backed Messenger transports, the ledger row and Messenger enqueue
   * happen inside one database transaction. Non-SQL transports would need a
   * dedicated outbox relay to make the same guarantee.
   */
  public function enqueue(
    EntityInterface $entity,
    string $payload,
    array $headers,
    array $configuration,
    string $actionId,
    ?string $triggerEventType = NULL,
    ?string $correlationKey = NULL,
    string $messageClass = IslandoraDerivativeMessage::class,
  ): void {
    $queue = (string) ($configuration['queue'] ?? '');
    $actionId = $this->resolveActionIdentifier($configuration, $actionId);
    $triggerEventType ??= (string) ($configuration['event'] ?? 'Generate Derivative');
    $dedupeKey = $correlationKey ?? sprintf(
      'derivative:%s:%s:%d:%s',
      $queue,
      $entity->getEntityTypeId(),
      (int) $entity->id(),
      sha1($payload)
    );
    $correlationKey = $this->buildCorrelationKey($dedupeKey);

    if (!is_a($messageClass, IslandoraDerivativeMessage::class, TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        'Derivative message class "%s" must extend %s.',
        $messageClass,
        IslandoraDerivativeMessage::class
      ));
    }

    $queued = $this->dispatch->recordAndDispatch(
      $entity,
      $triggerEventType,
      $dedupeKey,
      new $messageClass(
        (int) $entity->id(),
        $entity->getEntityTypeId(),
        $triggerEventType,
        0,
        $correlationKey,
      ),
      [
        'event_kind' => EventRecord::KIND_DERIVATIVE,
        'target_system' => 'derivative_runner',
        'action_plugin_id' => $actionId,
        'correlation_key' => $correlationKey,
        'dedupe_key' => $dedupeKey,
        'queue_name' => $queue,
        'transport_mode' => EventRecord::TRANSPORT_NATIVE,
        'payload_json' => $payload,
        'transport_metadata' => json_encode([
          'queue' => $queue,
          'headers' => $this->sanitizeHeaders($headers),
          'configuration' => $configuration,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
      ],
    );
    if (!$queued) {
      $this->logger->debug(
        'Skipped derivative enqueue for {entity_type}:{entity_id} on {queue} '
        . 'because a matching record already exists inside the dedupe window.',
        [
          'entity_type' => $entity->getEntityTypeId(),
          'entity_id' => (int) $entity->id(),
          'queue' => $queue,
        ],
      );
      return;
    }

    $this->logger->info('Queued derivative job for {entity_type}:{entity_id} on {queue}', [
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => (int) $entity->id(),
      'queue' => $queue,
    ]);
  }

  /**
   * Dispatches the Islandora STOMP header event for a derivative payload.
   */
  protected function buildHeaders(
    EntityInterface $entity,
    AccountInterface $user,
    array $data,
    array $configuration,
  ): array {
    $event = $this->eventDispatcher->dispatch(
      new StompHeaderEvent($entity, $user, $data, $configuration),
      StompHeaderEventInterface::EVENT_NAME
    );
    return $event->getHeaders()->all();
  }

  /**
   * Removes sensitive headers before persistence.
   *
   * @param array $headers
   *   Transport headers.
   *
   * @return array
   *   Sanitized headers safe to persist.
   */
  protected function sanitizeHeaders(array $headers): array {
    unset($headers['Authorization'], $headers['JWT-Authorization']);
    return $headers;
  }

  /**
   * Resolves the stored action identifier from explicit input or configuration.
   */
  protected function resolveActionIdentifier(array $configuration, string $fallback): string {
    foreach (['id', 'action_id'] as $key) {
      $value = $configuration[$key] ?? NULL;
      if (is_string($value) && $value !== '') {
        return $value;
      }
    }

    return $fallback;
  }

  /**
   * Builds a transport-stable but row-unique correlation key.
   */
  protected function buildCorrelationKey(string $dedupeKey): string {
    return sprintf('%s:%s', $dedupeKey, bin2hex(random_bytes(8)));
  }

}
