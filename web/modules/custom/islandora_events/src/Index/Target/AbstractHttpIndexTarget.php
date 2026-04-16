<?php

namespace Drupal\islandora_events\Index\Target;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\jwt\Authentication\Provider\JwtAuth;
use Drupal\islandora_events\Index\IndexEventContext;
use Drupal\islandora_events\Index\IndexTargetInterface;
use Drupal\islandora_events\Message\CustomIndexEventMessage;
use Drupal\sm_workers\Service\CircuitBreakerService;
use GuzzleHttp\ClientInterface;

/**
 * Shared HTTP implementation for index targets.
 */
abstract class AbstractHttpIndexTarget implements IndexTargetInterface {

  /**
   * Event types handled by default HTTP targets.
   *
   * Subclasses can override this list to narrow or expand support.
   *
   * @var string[]
   */
  protected const SUPPORTED_EVENT_TYPES = ['insert', 'update', 'delete'];

  /**
   * Entity types handled by default HTTP targets.
   *
   * An empty list means the target accepts any entity type.
   *
   * @var string[]
   */
  protected const SUPPORTED_ENTITY_TYPES = [];

  /**
   * Constructs an HTTP index target.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected ConfigFactoryInterface $configFactory,
    protected JwtAuth $jwtAuth,
    protected CircuitBreakerService $circuitBreakers,
    protected AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function isEnabled(): bool {
    return (bool) ($this->targetConfig()['enabled'] ?? FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    $words = str_replace('_', ' ', $this->getTargetId());
    return ucwords($words);
  }

  /**
   * {@inheritdoc}
   */
  public function getMessageClass(): string {
    return CustomIndexEventMessage::class;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigName(): string {
    return 'islandora_events.target.' . $this->getTargetId();
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $entityType, string $eventType): bool {
    if (!in_array($eventType, static::SUPPORTED_EVENT_TYPES, TRUE)) {
      return FALSE;
    }

    if (static::SUPPORTED_ENTITY_TYPES === []) {
      return TRUE;
    }

    return in_array($entityType, static::SUPPORTED_ENTITY_TYPES, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function process(IndexEventContext $context): void {
    $config = $this->targetConfig();
    $endpoint = trim((string) ($config['endpoint'] ?? ''));

    if ($endpoint === '') {
      throw new \RuntimeException(sprintf('Index target "%s" has no endpoint configured.', $this->getTargetId()));
    }
    $breakerId = 'index:' . $this->getTargetId();
    $breakerLabel = sprintf('Index target %s', $this->getTargetId());
    $this->circuitBreakers->ensure($breakerId, $breakerLabel);
    $this->circuitBreakers->assertAllows($breakerId, $breakerLabel);

    try {
      $response = $this->httpClient->request('POST', $endpoint, [
        'headers' => [
          'Authorization' => $this->generateAuthorizationHeader(),
        ],
        'json' => [
          'target' => $context->targetId,
          'event_type' => $context->eventType,
          'entity_type' => $context->entityType,
          'entity_id' => $context->entityId,
          'payload' => $context->payload,
        ],
        'timeout' => (float) ($config['timeout'] ?? 30),
      ]);
    }
    catch (\Throwable $e) {
      $this->circuitBreakers->recordFailure($breakerId, $breakerLabel, $e->getMessage());
      throw $e;
    }

    if ($response->getStatusCode() >= 400) {
      $message = sprintf(
        'Index target "%s" responded with HTTP %d.',
        $this->getTargetId(),
        $response->getStatusCode()
      );
      $this->circuitBreakers->recordFailure($breakerId, $breakerLabel, $message);
      throw new \RuntimeException(sprintf(
        'Index target "%s" responded with HTTP %d.',
        $this->getTargetId(),
        $response->getStatusCode()
      ));
    }

    $this->circuitBreakers->recordSuccess($breakerId);
  }

  /**
   * Gets target-specific config.
   *
   * @return array<string, mixed>
   *   Config values.
   */
  protected function targetConfig(): array {
    $settings = $this->configFactory->get($this->getConfigName());
    return [
      'enabled' => $settings->get('enabled'),
      'endpoint' => $settings->get('endpoint'),
      'timeout' => $settings->get('timeout'),
      'named_graph' => $settings->get('named_graph'),
    ];
  }

  /**
   * Generates a fresh bearer token for worker-side HTTP requests.
   */
  protected function generateAuthorizationHeader(): string {
    $method = new \ReflectionMethod($this->jwtAuth, 'generateToken');
    $token = $method->getNumberOfParameters() > 0
      ? trim((string) $this->jwtAuth->generateToken($this->currentUser))
      : trim((string) $this->jwtAuth->generateToken());
    if ($token === '') {
      throw new \RuntimeException(sprintf('Unable to generate a JWT for index target "%s".', $this->getTargetId()));
    }

    return 'Bearer ' . $token;
  }

}
