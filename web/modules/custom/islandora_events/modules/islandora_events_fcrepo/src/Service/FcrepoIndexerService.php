<?php

namespace Drupal\islandora_events_fcrepo\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Header;
use Islandora\Chullo\FedoraApi;
use Islandora\EntityMapper\EntityMapper;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs Fedora indexing locally using Milliner-style logic.
 */
final class FcrepoIndexerService implements FcrepoIndexerServiceInterface {

  /**
   * Pairtree mapper for Fedora resource paths.
   */
  private EntityMapper $mapper;

  /**
   * Constructs the indexer.
   */
  public function __construct(
    private ClientInterface $httpClient,
    private LoggerInterface $logger,
  ) {
    $this->mapper = new EntityMapper();
  }

  /**
   * Saves a Drupal RDF resource into Fedora.
   */
  public function saveNode(
    string $uuid,
    string $jsonldUrl,
    string $fedoraBaseUrl,
    ?string $authorization = NULL,
  ): void {
    $fedoraUrl = $this->buildFedoraUrl($fedoraBaseUrl, $uuid);
    $this->saveNodeToUrl($jsonldUrl, $fedoraBaseUrl, $fedoraUrl, $authorization);
  }

  /**
   * Saves Drupal RDF into a specific Fedora URL.
   */
  private function saveNodeToUrl(
    string $jsonldUrl,
    string $fedoraBaseUrl,
    string $fedoraUrl,
    ?string $authorization = NULL,
  ): void {
    $fedora = FedoraApi::create($fedoraBaseUrl);

    $headers = $this->authorizationHeaders($authorization);
    $fedoraResponse = $fedora->getResourceHeaders($fedoraUrl);
    if ($fedoraResponse->getStatusCode() === 404) {
      $drupalResponse = $this->httpClient->request('GET', $jsonldUrl, [
        'headers' => $headers,
      ]);
      $jsonld = json_decode((string) $drupalResponse->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
      $resource = $this->processJsonld($jsonld, $jsonldUrl, $fedoraUrl);
      $headers['Content-Type'] = 'application/ld+json';
      $headers['Prefer'] = 'return=minimal; handling=lenient';
      $this->assertSuccess(
        $fedora->saveResource($fedoraUrl, json_encode($resource, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $headers),
        'PUT',
        $fedoraUrl,
        [201, 204],
      );
      return;
    }

    $headers['Accept'] = 'application/ld+json';
    $headers['Prefer'] = 'return=representation; omit="http://fedora.info/definitions/v4/repository#ServerManaged"';
    $fedoraResource = $fedora->getResource($fedoraUrl, $headers);
    $this->assertSuccess($fedoraResource, 'GET', $fedoraUrl, [200]);

    $stateTokens = $fedoraResource->getHeader('X-State-Token');
    $stateToken = '"' . ltrim((string) reset($stateTokens), 'W/') . '"';
    $fedoraJsonld = json_decode((string) $fedoraResource->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    $fedoraModified = $this->safeModifiedTimestamp($fedoraJsonld);

    $drupalHeaders = $this->authorizationHeaders($authorization);
    $drupalResponse = $this->httpClient->request('GET', $jsonldUrl, [
      'headers' => $drupalHeaders,
    ]);
    $drupalJsonld = json_decode((string) $drupalResponse->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    $subjectUrl = $this->getLinkHeader($drupalResponse, 'describes') ?? $jsonldUrl;
    $drupalJsonld = $this->processJsonld($drupalJsonld, $subjectUrl, $fedoraUrl);
    $drupalModified = $this->getOptionalModifiedTimestamp($drupalJsonld);
    if ($drupalModified !== NULL && $drupalModified <= $fedoraModified) {
      throw new \RuntimeException(sprintf(
        'Not updating %s because RDF at %s is not newer.',
        $fedoraUrl,
        $jsonldUrl,
      ), 412);
    }

    $drupalHeaders['Content-Type'] = 'application/ld+json';
    $drupalHeaders['Prefer'] = 'handling=lenient';
    $drupalHeaders['X-If-State-Match'] = $stateToken;
    $this->assertSuccess($fedora->saveResource(
      $fedoraUrl,
      json_encode($drupalJsonld, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
      $drupalHeaders,
    ), 'PUT', $fedoraUrl, [201, 204]);
  }

  /**
   * Saves a media-described Fedora resource using the media JSON endpoint.
   */
  public function saveMedia(
    string $sourceField,
    string $jsonUrl,
    string $fedoraBaseUrl,
    ?string $authorization = NULL,
  ): void {
    $urls = $this->getMediaUrls($sourceField, $jsonUrl, $fedoraBaseUrl, $authorization);
    $this->saveNodeToUrl($urls['jsonld'], $fedoraBaseUrl, $urls['fedora'], $authorization);
  }

  /**
   * Creates a Fedora version for a Drupal RDF resource.
   */
  public function createVersion(
    string $uuid,
    string $fedoraBaseUrl,
    ?string $authorization = NULL,
    ?string $label = NULL,
  ): void {
    $fedoraUrl = $this->buildFedoraUrl($fedoraBaseUrl, $uuid);
    $this->createVersionAtUrl($fedoraUrl, $authorization, $label);
  }

  /**
   * Creates a Fedora version for a media-described Fedora resource.
   */
  public function createMediaVersion(
    string $sourceField,
    string $jsonUrl,
    string $fedoraBaseUrl,
    ?string $authorization = NULL,
    ?string $label = NULL,
  ): void {
    $urls = $this->getMediaUrls($sourceField, $jsonUrl, $fedoraBaseUrl, $authorization);
    $this->createVersionAtUrl($urls['fedora'], $authorization, $label);
  }

  /**
   * Saves an external file resource in Fedora.
   */
  public function saveExternal(
    string $uuid,
    string $externalUrl,
    string $fedoraBaseUrl,
    ?string $authorization = NULL,
  ): void {
    $fedoraUrl = $this->buildFedoraUrl($fedoraBaseUrl, $uuid);
    $fedora = FedoraApi::create($fedoraBaseUrl);
    $headers = $this->authorizationHeaders($authorization);

    try {
      $drupalResponse = $this->httpClient->request('HEAD', $externalUrl, [
        'headers' => $headers,
      ]);
    }
    catch (ClientException) {
      $drupalResponse = $this->httpClient->request('HEAD', $externalUrl);
    }

    $mimetype = trim(explode(';', $drupalResponse->getHeaderLine('Content-Type'))[0] ?? '');
    $headers['Link'] = sprintf(
      '<%s>; rel="http://fedora.info/definitions/fcrepo#ExternalContent"; handling="redirect"; type="%s"',
      $externalUrl,
      $mimetype,
    );
    $this->assertSuccess($fedora->saveResource($fedoraUrl, NULL, $headers), 'PUT', $fedoraUrl, [201, 204]);
  }

  /**
   * Deletes a Fedora resource for a Drupal UUID.
   */
  public function deleteResource(
    string $uuid,
    string $fedoraBaseUrl,
    ?string $authorization = NULL,
  ): void {
    $fedoraUrl = $this->buildFedoraUrl($fedoraBaseUrl, $uuid);
    $fedora = FedoraApi::create($fedoraBaseUrl);
    $response = $fedora->deleteResource($fedoraUrl, $this->authorizationHeaders($authorization));
    $this->assertSuccess($response, 'DELETE', $fedoraUrl, [204, 404, 410]);
  }

  /**
   * Builds the target Fedora URL for a Drupal UUID.
   */
  private function buildFedoraUrl(string $fedoraBaseUrl, string $uuid): string {
    return rtrim($fedoraBaseUrl, '/') . '/' . $this->mapper->getFedoraPath($uuid);
  }

  /**
   * Creates a version snapshot for one Fedora resource URL.
   */
  private function createVersionAtUrl(
    string $fedoraUrl,
    ?string $authorization = NULL,
    ?string $label = NULL,
  ): void {
    $headers = $this->authorizationHeaders($authorization);
    if ($label !== NULL && $label !== '') {
      $headers['Slug'] = $label;
    }

    $versionUrl = rtrim($fedoraUrl, '/') . '/fcr:versions';
    $response = $this->httpClient->request('POST', $versionUrl, [
      'headers' => $headers,
      'http_errors' => FALSE,
    ]);
    $this->assertSuccess($response, 'POST', $versionUrl, [201, 204, 409]);
  }

  /**
   * Returns auth headers when authorization is provided.
   *
   * @return array<string, string>
   *   HTTP headers.
   */
  private function authorizationHeaders(?string $authorization): array {
    return $authorization !== NULL && $authorization !== ''
      ? ['Authorization' => $authorization]
      : [];
  }

  /**
   * Normalizes Drupal JSON-LD into a Fedora-accepted single-resource graph.
   *
   * @param array<string, mixed> $jsonld
   *   Raw JSON-LD.
   * @param string $drupalUrl
   *   Drupal JSON-LD source URL.
   * @param string $fedoraUrl
   *   Target Fedora resource URL.
   *
   * @return array<int, array<string, mixed>>
   *   Processed resource graph.
   */
  private function processJsonld(array $jsonld, string $drupalUrl, string $fedoraUrl): array {
    $parts = parse_url($drupalUrl);
    $subjectUrl = ($parts['host'] ?? '') . ($parts['path'] ?? '');
    $graph = isset($jsonld['@graph']) && is_array($jsonld['@graph']) ? $jsonld['@graph'] : [];
    $resource = array_values(array_filter($graph, static function (array $item) use ($subjectUrl): bool {
      $parts = parse_url((string) ($item['@id'] ?? ''));
      $other = ($parts['host'] ?? '') . ($parts['path'] ?? '');
      return $other === $subjectUrl;
    }));

    if ($resource === []) {
      $describedBy = 'http://www.iana.org/assignments/relation/describedby';
      $resource = array_values(array_filter($graph, static function (array $item) use ($subjectUrl, $describedBy): bool {
        $references = $item[$describedBy] ?? [];
        if (!is_array($references)) {
          return FALSE;
        }
        foreach ($references as $reference) {
          if (!is_array($reference)) {
            continue;
          }
          $parts = parse_url((string) ($reference['@id'] ?? ''));
          $other = ($parts['host'] ?? '') . ($parts['path'] ?? '');
          if ($other === $subjectUrl) {
            return TRUE;
          }
        }
        return FALSE;
      }));
    }

    if ($resource === []) {
      throw new \RuntimeException(sprintf('Unable to locate subject %s in JSON-LD graph.', $drupalUrl));
    }

    $resource[0]['@id'] = $fedoraUrl;
    return $resource;
  }

  /**
   * Extracts the first matching Link header relation.
   */
  private function getLinkHeader(ResponseInterface $response, string $relName, ?string $type = NULL): ?string {
    $parsed = Header::parse($response->getHeader('Link'));
    foreach ($parsed as $header) {
      $hasRelation = isset($header['rel']) && $header['rel'] === $relName;
      $hasType = $type === NULL || (isset($header['type']) && $header['type'] === $type);
      if ($hasRelation && $hasType) {
        return trim((string) $header[0], '<>');
      }
    }

    return NULL;
  }

  /**
   * Resolves media JSON/JSON-LD/Fedora URLs using Milliner's legacy rules.
   *
   * @return array{drupal:string,fedora:string,jsonld:string}
   *   URL mapping.
   */
  private function getMediaUrls(
    string $sourceField,
    string $jsonUrl,
    string $fedoraBaseUrl,
    ?string $authorization = NULL,
  ): array {
    $drupalResponse = $this->httpClient->request('GET', $jsonUrl, [
      'headers' => $this->authorizationHeaders($authorization),
    ]);
    $jsonldUrl = $this->getLinkHeader($drupalResponse, 'alternate', 'application/ld+json');
    if ($jsonldUrl === NULL || $jsonldUrl === '') {
      $jsonldUrl = str_replace('_format=json', '_format=jsonld', $jsonUrl);
    }

    $drupalUrl = $this->getLinkHeader($drupalResponse, 'describes');
    if ($drupalUrl === NULL || $drupalUrl === '') {
      $drupalUrl = preg_replace('/\?_format=json$/', '', $jsonUrl) ?: $jsonUrl;
    }

    $mediaJson = json_decode((string) $drupalResponse->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    if (
      !isset($mediaJson[$sourceField][0]['target_uuid'])
      || !is_string($mediaJson[$sourceField][0]['target_uuid'])
      || $mediaJson[$sourceField][0]['target_uuid'] === ''
    ) {
      throw new \RuntimeException(sprintf(
        'Cannot parse file UUID from %s. Ensure %s exists on the media and is populated.',
        $jsonUrl,
        $sourceField,
      ));
    }

    $fileUuid = $mediaJson[$sourceField][0]['target_uuid'];
    $fedoraBaseUrl = rtrim($fedoraBaseUrl, '/');
    $pieces = explode('_flysystem/fedora/', $drupalUrl);
    $fedoraFilePath = count($pieces) > 1 ? (string) end($pieces) : $this->mapper->getFedoraPath($fileUuid);
    $fedoraFileUrl = $fedoraBaseUrl . '/' . $fedoraFilePath;

    $fedoraResponse = $this->httpClient->request('HEAD', $fedoraFileUrl, [
      'allow_redirects' => FALSE,
      'headers' => $this->authorizationHeaders($authorization),
    ]);
    $this->assertSuccess($fedoraResponse, 'HEAD', $fedoraFileUrl, [200, 307]);
    $fedoraUrl = $this->getLinkHeader($fedoraResponse, 'describedby');
    if ($fedoraUrl === NULL || $fedoraUrl === '') {
      throw new \RuntimeException(sprintf(
        'Cannot parse describedby Link header from response to HEAD %s.',
        $fedoraFileUrl,
      ));
    }

    return [
      'drupal' => $drupalUrl,
      'fedora' => $fedoraUrl,
      'jsonld' => $jsonldUrl,
    ];
  }

  /**
   * Extracts the modified timestamp from a Fedora/Drupal JSON-LD graph.
   *
   * @param array<int, array<string, mixed>> $jsonld
   *   Single-resource graph.
   */
  private function getModifiedTimestamp(array $jsonld): int {
    $predicate = 'http://schema.org/dateModified';
    $modified = $jsonld[0][$predicate][0]['@value'] ?? NULL;
    if (!is_string($modified) || $modified === '') {
      throw new \RuntimeException(sprintf(
        'Could not parse %s from JSON-LD payload.',
        $predicate,
      ));
    }

    $date = \DateTime::createFromFormat(\DateTimeInterface::W3C, $modified);
    if ($date === FALSE) {
      throw new \RuntimeException(sprintf('Invalid modified date value %s.', $modified));
    }

    return $date->getTimestamp();
  }

  /**
   * Returns the modified timestamp when present.
   *
   * @param array<int, array<string, mixed>> $jsonld
   *   Single-resource graph.
   */
  private function getOptionalModifiedTimestamp(array $jsonld): ?int {
    $predicate = 'http://schema.org/dateModified';
    $modified = $jsonld[0][$predicate][0]['@value'] ?? NULL;
    if ($modified === NULL || $modified === '') {
      return NULL;
    }

    if (!is_string($modified)) {
      throw new \RuntimeException(sprintf(
        'Could not parse %s from JSON-LD payload.',
        $predicate,
      ));
    }

    $date = \DateTime::createFromFormat(\DateTimeInterface::W3C, $modified);
    if ($date === FALSE) {
      throw new \RuntimeException(sprintf('Invalid modified date value %s.', $modified));
    }

    return $date->getTimestamp();
  }

  /**
   * Returns a modified timestamp or zero when missing.
   */
  private function safeModifiedTimestamp(array $jsonld): int {
    try {
      return $this->getOptionalModifiedTimestamp($jsonld) ?? 0;
    }
    catch (\Throwable) {
      return 0;
    }
  }

  /**
   * Throws on unexpected Fedora or Drupal response codes.
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   HTTP response to check.
   * @param string $method
   *   HTTP method used.
   * @param string $url
   *   Request URL.
   * @param int[] $allowedStatuses
   *   Allowed response codes.
   */
  private function assertSuccess(
    ResponseInterface $response,
    string $method,
    string $url,
    array $allowedStatuses,
  ): void {
    $status = $response->getStatusCode();
    if (in_array($status, $allowedStatuses, TRUE)) {
      return;
    }

    $reason = $response->getReasonPhrase();
    $message = sprintf(
      'Client error: `%s %s` resulted in a `%d %s` response: %s',
      $method,
      $url,
      $status,
      $reason,
      (string) $response->getBody(),
    );
    $this->logger->error($message);
    throw new \RuntimeException($message, $status);
  }

}
