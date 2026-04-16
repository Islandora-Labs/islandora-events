<?php

namespace Drupal\islandora_events_fcrepo\Index\Target;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\GeneratedUrl;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\file\FileInterface;
use Drupal\islandora\MediaSource\MediaSourceService;
use Drupal\islandora_events\Index\IndexEventContext;
use Drupal\islandora_events\Index\IndexTargetInterface;
use Drupal\islandora_events\Message\FedoraIndexEventMessage;
use Drupal\jwt\Authentication\Provider\JwtAuth;
use Drupal\media\MediaInterface;
use Psr\Log\LoggerInterface;
use Drupal\sm_workers\Service\CircuitBreakerService;
use Drupal\islandora_events_fcrepo\Service\FcrepoIndexerServiceInterface;

/**
 * Direct Fedora/fcrepo indexing target.
 */
final class FcrepoIndexTarget implements IndexTargetInterface {

  /**
   * Constructs the target.
   */
  public function __construct(
    private ConfigFactoryInterface $configFactory,
    private JwtAuth $jwtAuth,
    private CircuitBreakerService $circuitBreakers,
    private FcrepoIndexerServiceInterface $indexer,
    private MediaSourceService $mediaSourceService,
    private FileUrlGeneratorInterface $fileUrlGenerator,
    private AccountProxyInterface $currentUser,
    private LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getTargetId(): string {
    return 'fedora';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return 'Fedora/fcrepo';
  }

  /**
   * {@inheritdoc}
   */
  public function getMessageClass(): string {
    return FedoraIndexEventMessage::class;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigName(): string {
    return 'islandora_events_fcrepo.settings';
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
    return in_array($entityType, ['node', 'media', 'file', 'taxonomy_term'], TRUE)
      && in_array($eventType, ['insert', 'update', 'delete'], TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function process(IndexEventContext $context): void {
    $config = $this->targetConfig();
    $endpoint = trim((string) ($config['endpoint'] ?? ''));
    if ($endpoint === '') {
      throw new \RuntimeException('Fedora/fcrepo indexing target has no endpoint configured.');
    }

    $breakerId = 'index:' . $this->getTargetId();
    $breakerLabel = 'Index target Fedora/fcrepo';
    $this->circuitBreakers->ensure($breakerId, $breakerLabel);
    $this->circuitBreakers->assertAllows($breakerId, $breakerLabel);

    try {
      $this->processContext($context, $endpoint);
      $this->circuitBreakers->recordSuccess($breakerId);
    }
    catch (\Throwable $exception) {
      $this->circuitBreakers->recordFailure($breakerId, $breakerLabel, $exception->getMessage());
      throw $exception;
    }
  }

  /**
   * Executes one context against the local embedded Fedora indexer.
   */
  private function processContext(IndexEventContext $context, string $endpoint): void {
    $payload = $context->payload;
    $metadata = is_array($payload['metadata'] ?? NULL) ? $payload['metadata'] : [];
    $authorization = $this->generateAuthorizationHeader();
    $createVersion = (bool) ($metadata['create_version'] ?? FALSE);
    $versionLabel = trim((string) ($metadata['revision_id'] ?? ''));

    if ($context->eventType === 'delete') {
      $uuid = $this->resolveDeleteUuid($context, $metadata);
      $this->indexer->deleteResource($uuid, $endpoint, $authorization);
      return;
    }

    if ($context->entityType === 'media') {
      $sourceField = (string) ($metadata['source_field'] ?? $this->resolveSourceField($context));
      $jsonUrl = (string) ($metadata['json_url'] ?? $this->buildFormattedUrl($context, 'json'));
      if ($sourceField === '' || $jsonUrl === '') {
        throw new \RuntimeException('Media indexing requires source_field and json_url metadata.');
      }
      $this->indexer->saveMedia($sourceField, $jsonUrl, $endpoint, $authorization);
      if ($createVersion) {
        $this->indexer->createMediaVersion($sourceField, $jsonUrl, $endpoint, $authorization, $versionLabel);
      }
      return;
    }

    if ($context->entityType === 'file') {
      $uuid = $this->resolveDeleteUuid($context, $metadata);
      $externalUrl = (string) ($metadata['external_url'] ?? $this->resolveExternalUrl($context));
      if ($externalUrl === '') {
        throw new \RuntimeException('File indexing requires an external_url value.');
      }
      $this->indexer->saveExternal($uuid, $externalUrl, $endpoint, $authorization);
      return;
    }

    $uuid = $this->resolveDeleteUuid($context, $metadata);
    $jsonldUrl = (string) ($metadata['jsonld_url'] ?? $this->buildFormattedUrl($context, 'jsonld'));
    if ($jsonldUrl === '') {
      throw new \RuntimeException('Content indexing requires a jsonld_url value.');
    }
    $this->indexer->saveNode($uuid, $jsonldUrl, $endpoint, $authorization);
    if ($createVersion) {
      $this->indexer->createVersion($uuid, $endpoint, $authorization, $versionLabel);
    }
  }

  /**
   * Resolves the target UUID for save/delete operations.
   *
   * @param \Drupal\islandora_events\Index\IndexEventContext $context
   *   Index event context.
   * @param array<string, mixed> $metadata
   *   Payload metadata.
   */
  private function resolveDeleteUuid(IndexEventContext $context, array $metadata): string {
    $uuid = (string) ($metadata['fcrepo_resource_uuid'] ?? $context->payload['entity_uuid'] ?? '');
    if ($uuid === '' && $context->entity) {
      $uuid = (string) $context->entity->uuid();
    }
    if ($uuid === '') {
      throw new \RuntimeException('Fedora/fcrepo indexing requires a UUID.');
    }

    return $uuid;
  }

  /**
   * Resolves a media source field name from the live entity when needed.
   */
  private function resolveSourceField(IndexEventContext $context): string {
    if (!$context->entity instanceof MediaInterface) {
      return '';
    }

    return $this->mediaSourceService->getSourceFieldName($context->entity->bundle());
  }

  /**
   * Resolves an external file URL from the live entity when needed.
   */
  private function resolveExternalUrl(IndexEventContext $context): string {
    if (!$context->entity instanceof FileInterface) {
      return '';
    }

    return $this->fileUrlGenerator->generateAbsoluteString($context->entity->getFileUri());
  }

  /**
   * Builds a formatted canonical URL from the live entity when available.
   */
  private function buildFormattedUrl(IndexEventContext $context, string $format): string {
    if ($context->entity === NULL) {
      return '';
    }

    try {
      $generated = $context->entity->toUrl('canonical', ['absolute' => TRUE])->toString(TRUE);
      $url = $generated instanceof GeneratedUrl
        ? $generated->getGeneratedUrl()
        : (string) $generated;
    }
    catch (\Throwable) {
      return '';
    }

    if ($url === '') {
      return '';
    }

    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . '_format=' . rawurlencode($format);
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
      throw new \RuntimeException('Unable to generate a JWT for Fedora/fcrepo indexing.');
    }

    $this->logJwtDebug($token);

    return 'Bearer ' . $token;
  }

  /**
   * Emits opt-in JWT debug details for Fedora indexing.
   */
  private function logJwtDebug(string $token): void {
    $debug = Settings::get('islandora_events_fcrepo_jwt_debug', []);
    if (!is_array($debug) || empty($debug['enabled'])) {
      return;
    }

    [$header, $payload] = $this->decodeJwt($token);
    $context = [
      'uid' => (int) $this->currentUser->id(),
      'name' => $this->currentUser->getAccountName(),
      'header' => $header,
      'payload' => $payload,
      'fingerprint' => substr(hash('sha256', $token), 0, 16),
    ];

    if (!empty($debug['log_full_token'])) {
      $context['token'] = $token;
    }

    $headerJson = json_encode($context['header'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $payloadJson = json_encode($context['payload'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $message = sprintf(
      'Fedora JWT debug for current user %d (%s). fingerprint=%s header=%s payload=%s%s',
      $context['uid'],
      $context['name'],
      $context['fingerprint'],
      $headerJson === FALSE ? '{}' : $headerJson,
      $payloadJson === FALSE ? '{}' : $payloadJson,
      isset($context['token']) ? ' token=' . $context['token'] : '',
    );

    // Emit to both Drupal logs and CLI stderr so Drush replay/consume runs show
    // the exact JWT context without depending on log-level configuration.
    $this->logger->notice($message);
    error_log('[islandora_events_fcrepo] ' . $message);
  }

  /**
   * Decodes JWT header and payload for debug logging.
   *
   * @return array{0: array<string, mixed>, 1: array<string, mixed>}
   *   Decoded header and payload.
   */
  private function decodeJwt(string $token): array {
    $parts = explode('.', $token);
    if (count($parts) < 2) {
      return [[], []];
    }

    return [
      $this->decodeJwtPart($parts[0]),
      $this->decodeJwtPart($parts[1]),
    ];
  }

  /**
   * Decodes one base64url JWT segment.
   *
   * @return array<string, mixed>
   *   Decoded data or an empty array.
   */
  private function decodeJwtPart(string $part): array {
    $decoded = base64_decode(strtr($part, '-_', '+/') . str_repeat('=', (4 - strlen($part) % 4) % 4), TRUE);
    if ($decoded === FALSE) {
      return [];
    }

    try {
      $data = json_decode($decoded, TRUE, 512, JSON_THROW_ON_ERROR);
      return is_array($data) ? $data : [];
    }
    catch (\Throwable) {
      return [];
    }
  }

}
