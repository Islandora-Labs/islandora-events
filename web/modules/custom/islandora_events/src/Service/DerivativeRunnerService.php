<?php

namespace Drupal\islandora_events\Service;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\jwt\Authentication\Provider\JwtAuth;
use Drupal\sm_ledger\Entity\EventRecord;
use Drupal\sm_ledger\Entity\EventRecordInterface;
use Drupal\sm_ledger\Service\LedgerProjectionService;
use Drupal\sm_ledger\Service\LedgerRecoveryService;
use Drupal\sm_workers\ExecutionStrategy\WorkerExecutionContext;
use Drupal\sm_workers\ExecutionStrategy\WorkerExecutionManager;
use Psr\Log\LoggerInterface;

/**
 * Executes derivative jobs from database-backed queues.
 *
 * The command surface is intentionally thin; this service owns queue draining
 * and invocation so it can be reused by future Drupal CLI commands.
 */
class DerivativeRunnerService {

  /**
   * Event record storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected EntityStorageInterface $storage;

  /**
   * User storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected EntityStorageInterface $userStorage;

  /**
   * Constructs the derivative runner.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    protected LedgerProjectionService $projection,
    protected LedgerRecoveryService $recovery,
    protected LoggerInterface $logger,
    protected AccountSwitcherInterface $accountSwitcher,
    protected JwtAuth $jwtAuth,
    protected DerivativePayloadNormalizer $payloadNormalizer,
    protected DerivativeRunnerConfigResolver $runnerConfigResolver,
    protected DerivativeWriteBackService $writeBackService,
    protected WorkerExecutionManager $executionManager,
  ) {
    $this->storage = $entityTypeManager->getStorage('event_record');
    $this->userStorage = $entityTypeManager->getStorage('user');
  }

  /**
   * Processes native database-backed derivative jobs.
   */
  public function processNativeQueue(
    ?string $queue,
    int $limit,
    bool $dryRun = FALSE,
    array $overrides = [],
  ): array {
    $ids = $dryRun
      ? $this->loadPendingRecordIds($queue, $limit)
      : $this->recovery->claimRecordsForProcessing(
        EventRecord::KIND_DERIVATIVE,
        $queue,
        $limit,
      );

    if (empty($ids)) {
      return [];
    }

    $records = $this->storage->loadMultiple($ids);
    $results = [];
    foreach ($records as $record) {
      if (count($results) >= $limit) {
        break;
      }

      $results[] = $dryRun
        ? $this->processRecord((int) $record->id(), TRUE, $overrides)
        : $this->processClaimedRecord((int) $record->id(), $overrides);
    }

    return $results;
  }

  /**
   * Loads pending event record IDs for dry-run inspection.
   *
   * @return list<int>
   *   Matching event record IDs.
   */
  protected function loadPendingRecordIds(?string $queue, int $limit): array {
    $query = $this->storage->getQuery()
      // Worker-side ledger replay needs to inspect records regardless of the
      // current account because access was already enforced when the record was
      // created and the worker may run without an interactive user.
      ->accessCheck(FALSE)
      ->condition('event_kind', EventRecord::KIND_DERIVATIVE)
      ->condition('needs_processing', 1)
      ->sort('id', 'ASC');

    if ($queue !== NULL && $queue !== '') {
      $query->condition('queue_name', $queue);
    }

    return $query
      ->range(0, $limit)
      ->execute();
  }

  /**
   * Processes a single event-record-backed job.
   */
  protected function processRecord(
    int $recordId,
    bool $dryRun,
    array $overrides,
  ): array {
    try {
      return $dryRun
        ? $this->describeEventRecord($recordId, $overrides)
        : $this->processEventRecord($recordId, $overrides);
    }
    catch (\Throwable $e) {
      $record = $this->loadEventRecord($recordId);
      $queue = $record ? $this->getRecordQueue($record) : '';
      return [
        'status' => 'failed',
        'queue' => $queue,
        'message' => $e->getMessage(),
      ];
    }
  }

  /**
   * Processes a record that was already claimed by the current worker.
   */
  protected function processClaimedRecord(
    int $recordId,
    array $overrides,
  ): array {
    try {
      $result = $this->processEventRecord(
        $recordId,
        $overrides,
        DerivativeLifecycleMode::ManageFailureOnly,
      );
      $this->projection->markCompleted($recordId);
      return $result;
    }
    catch (\Throwable $e) {
      $record = $this->loadEventRecord($recordId);
      $queue = $record ? $this->getRecordQueue($record) : '';
      return [
        'status' => 'failed',
        'queue' => $queue,
        'message' => $e->getMessage(),
      ];
    }
  }

  /**
   * Processes an event-record-backed job with the requested lifecycle mode.
   *
   * @throws \Throwable
   */
  public function processEventRecord(
    int $recordId,
    array $overrides = [],
    DerivativeLifecycleMode $lifecycleMode = DerivativeLifecycleMode::ManageAll,
  ): array {
    $record = $this->loadEventRecord($recordId);
    if (!$record) {
      throw new \RuntimeException(sprintf('Event record %d was not found.', $recordId));
    }

    $switched = FALSE;
    $initiatingUserId = (int) $record->get('initiating_user_id')->target_id;
    if ($initiatingUserId > 0) {
      $user = $this->loadUser($initiatingUserId);
      if ($user) {
        $this->accountSwitcher->switchTo($user);
        $switched = TRUE;
      }
    }

    try {
      $metadata = $this->decodeTransportMetadata(
        (string) $record->get('transport_metadata')->value,
      );
      $payload = (string) $record->get('payload_json')->value;
      $queue = (string) ($metadata['queue'] ?? '');
      $headers = is_array($metadata['headers'] ?? NULL)
        ? $metadata['headers']
        : [];
      $runner = $this->runnerConfigResolver->resolve($queue, $overrides);
      $runner['event_record_id'] = $recordId;

      if ($payload === '') {
        throw new \RuntimeException(sprintf('Event record %d has no derivative payload.', $recordId));
      }

      if ($lifecycleMode->shouldMarkProcessing()) {
        $this->projection->markProcessing($recordId);
      }

      try {
        $result = $this->processPayload(
          $queue,
          $payload,
          $headers,
          $runner,
          FALSE,
        );
        if ($lifecycleMode->shouldMarkCompleted()) {
          $this->projection->markCompleted($recordId);
        }
        return $result;
      }
      catch (\Throwable $e) {
        if ($lifecycleMode->shouldMarkFailed()) {
          $this->projection->markFailed($recordId, $e->getMessage());
        }
        throw $e;
      }
    }
    finally {
      if ($switched) {
        $this->accountSwitcher->switchBack();
      }
    }
  }

  /**
   * Describes what would happen for an event record without executing it.
   */
  protected function describeEventRecord(
    int $recordId,
    array $overrides = [],
  ): array {
    $record = $this->loadEventRecord($recordId);
    if (!$record) {
      throw new \RuntimeException(sprintf('Event record %d was not found.', $recordId));
    }

    $queue = $this->getRecordQueue($record);
    $runner = $this->runnerConfigResolver->resolve($queue, $overrides);

    return [
      'status' => 'dry-run',
      'queue' => $queue,
      'message' => sprintf(
        'Would execute %s runner for event record %d',
        $runner['execution_mode'],
        $recordId,
      ),
    ];
  }

  /**
   * Executes a derivative payload through the configured runner.
   */
  protected function processPayload(
    string $queue,
    string $payload,
    array $headers,
    array $runner,
    bool $dryRun,
  ): array {
    if ($dryRun) {
      return [
        'status' => 'dry-run',
        'queue' => $queue,
        'message' => sprintf('Would execute %s runner', $runner['execution_mode']),
      ];
    }

    $event = json_decode($payload, TRUE, 512, JSON_THROW_ON_ERROR);
    $content = $event['attachment']['content'] ?? [];
    [$payload, $content] = $this->payloadNormalizer
      ->normalizeDerivativePayload($payload, $content, $event);
    $authorization = $this->generateAuthorizationHeader();

    $executionMode = (string) ($runner['execution_mode'] ?? 'http');
    $result = $this->executionManager->execute(
        $executionMode,
        new WorkerExecutionContext(
          $payload,
          $content,
          $runner,
          $authorization,
          $this->buildHeartbeatCallback($runner),
        ),
      );
    $this->emitHeartbeat($runner);
    $body = $result->body();
    $contentType = $result->contentType();

    $destinationUri = (string) ($content['destination_uri'] ?? '');
    $writeBack = $this->runnerConfigResolver
      ->normalizeBoolean($runner['write_back'] ?? TRUE);
    if ($writeBack && $destinationUri === '') {
      throw new \RuntimeException('Derivative payload is missing destination_uri.');
    }

    if ($writeBack) {
      $this->emitHeartbeat($runner);
      $this->writeBackService->writeBack(
        $destinationUri,
        $body,
        $contentType
        ?: (string) ($content['mimetype'] ?? 'application/octet-stream'),
        isset($content['file_upload_uri']) ? (string) $content['file_upload_uri'] : NULL,
        $authorization,
        (int) ($runner['timeout'] ?? 300),
      );
    }

    return [
      'status' => 'completed',
      'queue' => $queue,
      'message' => $writeBack
        ? sprintf('Processed derivative to %s via %s', $destinationUri, $executionMode)
        : sprintf(
          'Processed derivative via %s with self-managed output handling',
          $executionMode,
      ),
    ];
  }

  /**
   * Builds a heartbeat callback for long-running native replay work.
   */
  protected function buildHeartbeatCallback(array $runner): ?callable {
    $recordId = (int) ($runner['event_record_id'] ?? 0);
    if ($recordId <= 0) {
      return NULL;
    }

    return function () use ($recordId): void {
      $this->projection->markHeartbeat($recordId);
    };
  }

  /**
   * Emits an immediate heartbeat when a native record is actively executing.
   */
  protected function emitHeartbeat(array $runner): void {
    $heartbeat = $this->buildHeartbeatCallback($runner);
    if ($heartbeat !== NULL) {
      $heartbeat();
    }
  }

  /**
   * Generates a fresh bearer token for worker-side HTTP requests.
   */
  protected function generateAuthorizationHeader(): string {
    $method = new \ReflectionMethod($this->jwtAuth, 'generateToken');
    $token = $method->getNumberOfParameters() > 0
      ? trim((string) $this->jwtAuth->generateToken(\Drupal::currentUser()))
      : trim((string) $this->jwtAuth->generateToken());
    if ($token === '') {
      throw new \RuntimeException('Unable to generate a JWT for derivative processing.');
    }

    return 'Bearer ' . $token;
  }

  /**
   * Loads a user account by ID.
   */
  protected function loadUser(int $userId): ?object {
    $user = $this->userStorage->load($userId);
    return $user ?: NULL;
  }

  /**
   * Loads an event record by ID.
   */
  protected function loadEventRecord(int $recordId): ?EventRecordInterface {
    $record = $this->storage->load($recordId);
    return $record instanceof EventRecordInterface ? $record : NULL;
  }

  /**
   * Gets the configured queue name from a stored event record.
   */
  protected function getRecordQueue(EventRecordInterface $record): string {
    $metadata = $this->decodeTransportMetadata(
        (string) $record->get('transport_metadata')->value,
      );
    return (string) ($metadata['queue'] ?? '');
  }

  /**
   * Decodes stored transport metadata.
   */
  protected function decodeTransportMetadata(string $metadata): array {
    return $this->payloadNormalizer->decodeTransportMetadata($metadata);
  }

}
