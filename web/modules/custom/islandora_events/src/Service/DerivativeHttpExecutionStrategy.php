<?php

namespace Drupal\islandora_events\Service;

use Drupal\sm_workers\ExecutionStrategy\WorkerExecutionStrategyInterface;
use Drupal\sm_workers\ExecutionStrategy\WorkerExecutionContext;
use Drupal\sm_workers\ExecutionStrategy\WorkerExecutionResult;
use Drupal\sm_workers\Service\CircuitBreakerService;
use GuzzleHttp\ClientInterface;

/**
 * Executes derivative payloads against an HTTP endpoint.
 */
final class DerivativeHttpExecutionStrategy implements WorkerExecutionStrategyInterface {

  /**
   * Constructs the HTTP strategy.
   */
  public function __construct(
    private ClientInterface $httpClient,
    private ?CircuitBreakerService $circuitBreakers = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function supports(string $mode): bool {
    return $mode === 'http';
  }

  /**
   * {@inheritdoc}
   */
  public function execute(WorkerExecutionContext $context): WorkerExecutionResult {
    $payload = $context->payload();
    $content = $context->metadata();
    $runner = $context->worker();
    $authorization = $context->authorization();
    $endpoint = (string) ($runner['endpoint'] ?? '');
    if ($endpoint === '') {
      throw new \RuntimeException('No HTTP endpoint configured for this derivative queue.');
    }

    $queue = (string) ($runner['queue'] ?? '');
    $breakerId = 'derivative:' . ($queue !== '' ? $queue : sha1($endpoint));
    $breakerLabel = $queue !== ''
      ? sprintf('Derivative queue %s', $queue)
      : sprintf('Derivative endpoint %s', $endpoint);
    if ($this->circuitBreakers) {
      $this->circuitBreakers->ensure($breakerId, $breakerLabel);
      $this->circuitBreakers->assertAllows($breakerId, $breakerLabel);
    }

    $requestContext = $this->buildInvocationContext(
      $payload,
      $content,
      $authorization,
      (int) ($runner['timeout'] ?? 300),
    );

    try {
      $context->heartbeat();
      $response = $this->httpClient->request('GET', $endpoint, [
        'headers' => $requestContext['headers'],
        'timeout' => $requestContext['timeout'],
      ]);
      $context->heartbeat();
      if ($this->circuitBreakers) {
        $this->circuitBreakers->recordSuccess($breakerId);
      }
    }
    catch (\Throwable $e) {
      if ($this->circuitBreakers) {
        $this->circuitBreakers->recordFailure($breakerId, $breakerLabel, $e->getMessage());
      }
      throw $e;
    }

    return new WorkerExecutionResult(
      (string) $response->getBody(),
      $response->getHeaderLine('Content-Type') ?: $requestContext['destination_mime_type'],
    );
  }

  /**
   * Builds the shared derivative invocation context.
   *
   * @return array<string, mixed>
   *   HTTP request context for the remote derivative service.
   */
  private function buildInvocationContext(
    string $payload,
    array $content,
    ?string $authorization,
    int $timeout,
  ): array {
    $sourceUri = (string) ($content['source_uri'] ?? '');
    if ($sourceUri === '') {
      throw new \RuntimeException('Derivative payload is missing source_uri.');
    }

    $payloadBase64 = base64_encode($payload);
    $destinationMimeType = (string) ($content['mimetype'] ?? 'application/octet-stream');

    return [
      'payload_base64' => $payloadBase64,
      'source_uri' => $sourceUri,
      'destination_mime_type' => $destinationMimeType,
      'timeout' => $timeout,
      'headers' => array_filter([
        'Authorization' => $authorization,
        'Accept' => $destinationMimeType,
        'X-Islandora-Args' => $content['args'] ?? NULL,
        'Apix-Ldp-Resource' => $sourceUri,
        'X-Islandora-Event' => $payloadBase64,
      ], static fn ($value): bool => $value !== NULL && $value !== ''),
    ];
  }

}
