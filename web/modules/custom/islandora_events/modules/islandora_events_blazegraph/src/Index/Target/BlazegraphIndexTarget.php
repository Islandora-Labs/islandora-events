<?php

namespace Drupal\islandora_events_blazegraph\Index\Target;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\islandora_events\Index\IndexEventContext;
use Drupal\islandora_events\Index\IndexTargetInterface;
use Drupal\islandora_events\Message\BlazegraphIndexEventMessage;
use Drupal\islandora_events_blazegraph\Service\BlazegraphIndexerServiceInterface;
use Drupal\jwt\Authentication\Provider\JwtAuth;
use Drupal\sm_workers\Service\CircuitBreakerService;

/**
 * Direct Blazegraph/triplestore indexing target.
 */
final class BlazegraphIndexTarget implements IndexTargetInterface {

  /**
   * Constructs the target.
   */
  public function __construct(
    private ConfigFactoryInterface $configFactory,
    private JwtAuth $jwtAuth,
    private CircuitBreakerService $circuitBreakers,
    private BlazegraphIndexerServiceInterface $indexer,
    private AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getTargetId(): string {
    return 'blazegraph';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return 'Blazegraph';
  }

  /**
   * {@inheritdoc}
   */
  public function getMessageClass(): string {
    return BlazegraphIndexEventMessage::class;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigName(): string {
    return 'islandora_events_blazegraph.settings';
  }

  /**
   * {@inheritdoc}
   */
  public function isEnabled(): bool {
    return (bool) ($this->targetConfig()['enabled'] ?? FALSE);
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $entityType, string $eventType): bool {
    return in_array($entityType, ['node', 'media', 'taxonomy_term'], TRUE)
      && in_array($eventType, ['insert', 'update', 'delete'], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function process(IndexEventContext $context): void {
    $config = $this->targetConfig();
    $endpoint = trim((string) ($config['endpoint'] ?? ''));
    if ($endpoint === '') {
      throw new \RuntimeException('Blazegraph indexing target has no endpoint configured.');
    }

    $breakerId = 'index:' . $this->getTargetId();
    $breakerLabel = 'Index target Blazegraph';
    $this->circuitBreakers->ensure($breakerId, $breakerLabel);
    $this->circuitBreakers->assertAllows($breakerId, $breakerLabel);

    try {
      $this->processContext($context, $endpoint, trim((string) ($config['named_graph'] ?? '')));
      $this->circuitBreakers->recordSuccess($breakerId);
    }
    catch (\Throwable $exception) {
      $this->circuitBreakers->recordFailure($breakerId, $breakerLabel, $exception->getMessage());
      throw $exception;
    }
  }

  /**
   * Executes one context against the local embedded Blazegraph indexer.
   */
  private function processContext(IndexEventContext $context, string $endpoint, string $namedGraph): void {
    $payload = $context->payload;
    $metadata = is_array($payload['metadata'] ?? NULL) ? $payload['metadata'] : [];
    $authorization = $this->generateAuthorizationHeader();
    $subjectUrl = trim((string) ($metadata['subject_url'] ?? $metadata['canonical_url'] ?? $this->buildCanonicalUrl($context)));

    if ($subjectUrl === '') {
      throw new \RuntimeException('Blazegraph indexing requires a subject_url value.');
    }

    if ($context->eventType === 'delete') {
      $this->indexer->deleteResource($subjectUrl, $endpoint, $authorization, $namedGraph);
      return;
    }

    $jsonldUrl = trim((string) ($metadata['jsonld_url'] ?? $this->appendFormatQuery($subjectUrl, 'jsonld')));
    if ($jsonldUrl === '') {
      throw new \RuntimeException('Blazegraph indexing requires a jsonld_url value.');
    }

    $this->indexer->updateResource($jsonldUrl, $subjectUrl, $endpoint, $authorization, $namedGraph);
  }

  /**
   * Returns the target configuration.
   *
   * @return array<string, mixed>
   *   Config values.
   */
  private function targetConfig(): array {
    $settings = $this->configFactory->get($this->getConfigName());
    return [
      'enabled' => $settings->get('enabled'),
      'endpoint' => $settings->get('endpoint'),
      'timeout' => $settings->get('timeout'),
      'named_graph' => $settings->get('named_graph'),
    ];
  }

  /**
   * Generates a fresh worker-side JWT.
   */
  private function generateAuthorizationHeader(): string {
    $method = new \ReflectionMethod($this->jwtAuth, 'generateToken');
    $token = $method->getNumberOfParameters() > 0
      ? trim((string) $this->jwtAuth->generateToken($this->currentUser))
      : trim((string) $this->jwtAuth->generateToken());
    if ($token === '') {
      throw new \RuntimeException('Unable to generate a JWT for Blazegraph indexing.');
    }

    return 'Bearer ' . $token;
  }

  /**
   * Builds an absolute canonical URL from the live entity when available.
   */
  private function buildCanonicalUrl(IndexEventContext $context): string {
    if ($context->entity === NULL) {
      return '';
    }

    try {
      $generated = $context->entity->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE);
      return $generated instanceof GeneratedUrl
        ? $generated->getGeneratedUrl()
        : trim((string) $generated);
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Adds or replaces the _format query argument on a URL.
   */
  private function appendFormatQuery(string $url, string $format): string {
    if ($url === '') {
      return '';
    }

    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . '_format=' . rawurlencode($format);
  }

}
