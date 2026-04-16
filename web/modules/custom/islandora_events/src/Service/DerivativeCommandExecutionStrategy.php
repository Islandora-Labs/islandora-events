<?php

namespace Drupal\islandora_events\Service;

use Drupal\sm_workers\CommandToken\WorkerCommandTokenRegistry;
use Drupal\sm_workers\ExecutionStrategy\WorkerExecutionContext;
use Drupal\sm_workers\ExecutionStrategy\WorkerExecutionStrategyInterface;
use Drupal\sm_workers\ExecutionStrategy\WorkerExecutionResult;
use Drupal\sm_workers\Service\WorkerCommandBuilder;
use Drupal\sm_workers\Service\WorkerCommandExecutor;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Executes derivative payloads through a local command.
 */
final class DerivativeCommandExecutionStrategy implements WorkerExecutionStrategyInterface {

  /**
   * Constructs the command strategy.
   */
  public function __construct(
    private ClientInterface $httpClient,
    private LoggerInterface $logger,
    private ?DerivativeCommandPolicyInterface $commandPolicy = NULL,
    private ?WorkerCommandTokenRegistry $tokenRegistry = NULL,
    private ?WorkerCommandExecutor $executor = NULL,
    private ?WorkerCommandBuilder $commandBuilder = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function supports(string $mode): bool {
    return $mode === 'command';
  }

  /**
   * {@inheritdoc}
   */
  public function execute(WorkerExecutionContext $context): WorkerExecutionResult {
    $payload = $context->payload();
    $content = $context->metadata();
    $runner = $context->worker();
    $authorization = $context->authorization();
    if (!$this->isCommandExecutionEnabled()) {
      throw new \RuntimeException('Command-mode derivative execution is disabled.');
    }

    $configPath = (string) ($runner['config_path'] ?? '');
    if ($configPath !== '') {
      return $this->runCommandWithConfigPath($payload, $content, $runner, $authorization, $configPath, $context);
    }

    $cmd = trim((string) ($runner['cmd'] ?? ''));
    if ($cmd !== '') {
      return $this->runCommandWithTokens($payload, $content, $runner, $authorization, $cmd, $context);
    }

    $commandTemplate = (string) ($runner['command'] ?? '');
    if ($commandTemplate === '') {
      throw new \RuntimeException('No command configured for this derivative queue.');
    }

    $command = $this->buildCommandArguments($commandTemplate, $content);
    $binary = $command[0] ?? '';
    if (!$this->isAllowedCommandBinary($binary)) {
      $this->logger->warning('Derivative command binary is not allowlisted.', [
        'binary' => $binary,
        'queue' => $runner['queue'] ?? '',
      ]);
      throw new \RuntimeException('Derivative command binary is not allowlisted.');
    }

    $sourceUri = (string) ($content['source_uri'] ?? '');
    if ($sourceUri === '') {
      throw new \RuntimeException('Derivative payload is missing source_uri.');
    }

    $response = $this->httpClient->request('GET', $sourceUri, [
      'headers' => array_filter(['Authorization' => $authorization]),
      'timeout' => (int) ($runner['timeout'] ?? 300),
    ]);
    $executor = $this->executor ?? new WorkerCommandExecutor($this->logger);
    $stdout = $executor->run(
      $command,
      (string) $response->getBody(),
      [
        'binary' => $binary,
        'queue' => $runner['queue'] ?? '',
      ],
      (int) ($runner['timeout'] ?? 300),
      $runner['working_directory'] ?: NULL,
      NULL,
      $context->heartbeat(...),
      (int) ($runner['heartbeat_interval'] ?? 30),
    );

    return new WorkerExecutionResult(
      $stdout,
      (string) ($content['mimetype'] ?? 'application/octet-stream'),
    );
  }

  /**
   * Returns whether privileged command-mode execution is enabled.
   */
  private function isCommandExecutionEnabled(): bool {
    return $this->commandPolicy?->isExecutionEnabled() ?? FALSE;
  }

  /**
   * Returns whether a command binary is allowlisted.
   */
  private function isAllowedCommandBinary(string $binary): bool {
    return $this->commandPolicy?->isAllowedBinary($binary) ?? FALSE;
  }

  /**
   * Executes a worker-definition-backed command via stdin/stdout.
   *
   * This path is used when the runner carries a 'cmd' key (the binary to
   * execute) and optionally an 'args' list with %token placeholders. Tokens
   * are resolved from all registered WorkerCommandTokenProviders so that
   * modules can inject context-specific values without touching this class.
   *
   * The source resource is fetched and piped to stdin. Stdout becomes the
   * derivative body, which the caller writes back to Drupal.
   */
  private function runCommandWithTokens(
    string $payload,
    array $content,
    array $runner,
    ?string $authorization,
    string $cmd,
    WorkerExecutionContext $context,
  ): WorkerExecutionResult {
    if (!$this->isAllowedCommandBinary($cmd)) {
      $this->logger->warning('Derivative command binary is not allowlisted.', [
        'binary' => $cmd,
        'queue' => $runner['queue'] ?? '',
      ]);
      throw new \RuntimeException('Derivative command binary is not allowlisted.');
    }

    $sourceUri = (string) ($content['source_uri'] ?? '');
    if ($sourceUri === '') {
      throw new \RuntimeException('Derivative payload is missing source_uri.');
    }

    $forwardAuth = isset($runner['forward_auth']) ? (bool) $runner['forward_auth'] : TRUE;
    $timeout = (int) ($runner['timeout'] ?? 300);

    $response = $this->httpClient->request('GET', $sourceUri, [
      'headers' => array_filter(['Authorization' => $forwardAuth ? $authorization : NULL]),
      'timeout' => $timeout,
    ]);

    $tokens = $this->tokenRegistry?->resolveTokens([
      'payload' => $payload,
      'metadata' => $content,
      'worker' => $runner,
      'authorization' => $authorization,
    ]) ?? [];

    $definition = [
      'execution' => [
        'cmd' => $cmd,
        'args' => (array) ($runner['args'] ?? []),
      ],
    ];
    $argv = $this->commandBuilder?->buildCommandArgv($definition, $tokens) ?? [$cmd];

    $executor = $this->executor ?? new WorkerCommandExecutor($this->logger);
    $stdout = $executor->run(
      $argv,
      (string) $response->getBody(),
      ['queue' => $runner['queue'] ?? ''],
      $timeout,
      NULL,
      NULL,
      $context->heartbeat(...),
      (int) ($runner['heartbeat_interval'] ?? 30),
    );

    return new WorkerExecutionResult(
      $stdout,
      (string) ($content['mimetype'] ?? 'application/octet-stream'),
    );
  }

  /**
   * Executes a config-backed local command runner.
   */
  private function runCommandWithConfigPath(
    string $payload,
    array $content,
    array $runner,
    ?string $authorization,
    string $configPath,
    WorkerExecutionContext $executionContext,
  ): WorkerExecutionResult {
    if (!is_file($configPath)) {
      $this->logger->error('Configured derivative runner file was not found.', [
        'queue' => $runner['queue'] ?? '',
        'config_path' => $configPath,
      ]);
      throw new \RuntimeException('Configured derivative runner file was not found.');
    }

    $timeout = (int) ($runner['timeout'] ?? 300);
    $context = $this->buildInvocationContext($payload, $content, $authorization, $timeout);
    $source = $this->fetchSourceResource($context['source_uri'], $authorization, $context['timeout']);
    if (($content['source_mimetype'] ?? '') === '' && $source['content_type'] !== '') {
      $context['destination_mime_type'] = (string) ($content['mimetype'] ?? 'application/octet-stream');
    }

    $binary = (string) ($runner['command'] ?? '/usr/bin/scyllaridae');
    if (!$this->isAllowedCommandBinary($binary)) {
      $this->logger->warning('Derivative command binary is not allowlisted.', [
        'binary' => $binary,
        'queue' => $runner['queue'] ?? '',
      ]);
      throw new \RuntimeException('Derivative command binary is not allowlisted.');
    }
    $command = [
      $binary,
      '--yml',
      $configPath,
      '--message',
      $context['payload_base64'],
    ];

    $environment = [
      'PATH' => '/usr/bin:/bin',
      'SCYLLARIDAE_BASE_DIR' => dirname($configPath),
    ];
    foreach ($this->resolveAllowedEnvironment($runner) as $name => $value) {
      $environment[$name] = $value;
    }
    $endpoint = (string) ($runner['endpoint'] ?? '');
    if ($endpoint !== '') {
      $environment['SCYLLARIDAE_REMOTE_ENDPOINT'] = $endpoint;
    }
    $executor = $this->executor ?? new WorkerCommandExecutor($this->logger);
    $stdout = trim($executor->run(
      $command,
      $source['body'],
      [
        'binary' => $binary,
        'queue' => $runner['queue'] ?? '',
        'config_path' => $configPath,
      ],
      $timeout,
      $runner['working_directory'] ?: dirname($configPath),
      $environment,
      $executionContext->heartbeat(...),
      (int) ($runner['heartbeat_interval'] ?? 30),
    ));

    return new WorkerExecutionResult(
      $this->consumeOutputFile($stdout),
      (string) ($content['mimetype'] ?? 'application/octet-stream'),
    );
  }

  /**
   * Returns explicitly allowlisted environment variables for the child process.
   *
   * @param array<string, mixed> $runner
   *   Resolved runner configuration.
   *
   * @return array<string, string>
   *   Allowed env vars copied from the current process.
   */
  private function resolveAllowedEnvironment(array $runner): array {
    $names = is_array($runner['env_vars'] ?? NULL) ? $runner['env_vars'] : [];
    $allowed = [];

    foreach ($names as $name) {
      $name = trim((string) $name);
      if ($name === '') {
        continue;
      }

      $value = getenv($name);
      if (is_string($value)) {
        $allowed[$name] = $value;
      }
    }

    return $allowed;
  }

  /**
   * Builds the shared derivative invocation context.
   *
   * @return array<string, mixed>
   *   Invocation data for local command runners.
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

  /**
   * Fetches the source file body and content type for local runner execution.
   *
   * @return array{body:string,content_type:string}
   *   Source body and MIME type.
   */
  private function fetchSourceResource(string $sourceUri, ?string $authorization, int $timeout): array {
    $response = $this->httpClient->request('GET', $sourceUri, [
      'headers' => array_filter(['Authorization' => $authorization]),
      'timeout' => $timeout,
    ]);

    $contentType = trim(explode(';', $response->getHeaderLine('Content-Type'))[0] ?? '');

    return [
      'body' => (string) $response->getBody(),
      'content_type' => $contentType,
    ];
  }

  /**
   * Builds a shell-free argv array for command-mode derivative execution.
   *
   * @param array|string $commandTemplate
   *   Command template string with supported placeholders.
   * @param array<string, mixed> $content
   *   Attachment content metadata.
   *
   * @return string[]
   *   Command arguments.
   */
  private function buildCommandArguments(array|string $commandTemplate, array $content): array {
    $commandTemplate = (string) $commandTemplate;
    $tokens = str_getcsv($commandTemplate, ' ', '"', '\\');
    $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));

    if ($tokens === []) {
      throw new \RuntimeException('Derivative command template did not produce any executable arguments.');
    }

    $replacements = [
      '{source_uri}' => (string) ($content['source_uri'] ?? ''),
      '{destination_uri}' => (string) ($content['destination_uri'] ?? ''),
      '{file_upload_uri}' => (string) ($content['file_upload_uri'] ?? ''),
      '{mimetype}' => (string) ($content['mimetype'] ?? ''),
    ];

    $arguments = [];
    foreach ($tokens as $token) {
      if ($token === '{args}') {
        $arguments = array_merge(
          $arguments,
          $this->commandPolicy?->parsePassedArgs((string) ($content['args'] ?? '')) ?? [],
        );
        continue;
      }

      if (str_contains($token, '{args}')) {
        throw new \RuntimeException('The {args} placeholder must be used as a standalone token.');
      }

      $arguments[] = strtr($token, $replacements);
    }

    return $arguments;
  }

  /**
   * Reads and removes a command output file from the system temp directory.
   */
  private function consumeOutputFile(string $path): string {
    $path = trim($path);
    if ($path === '') {
      throw new \RuntimeException('Configured derivative command did not return an output file path.');
    }

    $realPath = realpath($path);
    $tempDir = realpath(sys_get_temp_dir());
    if ($realPath === FALSE || $tempDir === FALSE || !is_file($realPath) || !str_starts_with($realPath, rtrim($tempDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
      $this->logger->error('Configured derivative command returned an invalid output path.', [
        'path' => $path,
        'resolved_path' => $realPath ?: '',
        'temp_dir' => $tempDir ?: '',
      ]);
      throw new \RuntimeException('Configured derivative command returned an invalid output file path.');
    }

    $body = file_get_contents($realPath);
    if ($body === FALSE) {
      $this->logger->error('Unable to read configured derivative command output file.', [
        'path' => $realPath,
      ]);
      throw new \RuntimeException('Unable to read configured derivative command output.');
    }

    if (!unlink($realPath)) {
      $this->logger->warning('Unable to remove configured derivative command output file.', [
        'path' => $realPath,
      ]);
    }

    return (string) $body;
  }

}
