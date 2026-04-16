<?php

namespace Drupal\islandora_events_blazegraph\Service;

use EasyRdf\Graph;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Header;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs Blazegraph indexing locally using embedded SPARQL update logic.
 */
final class BlazegraphIndexerService implements BlazegraphIndexerServiceInterface {

  /**
   * Constructs the indexer.
   */
  public function __construct(
    private ClientInterface $httpClient,
    private LoggerInterface $logger,
  ) {}

  /**
   * Upserts one subject into Blazegraph from Drupal JSON-LD.
   */
  public function updateResource(
    string $jsonldUrl,
    string $subjectUrl,
    string $endpoint,
    ?string $authorization = NULL,
    string $namedGraph = '',
  ): void {
    $response = $this->httpClient->request('GET', $jsonldUrl, [
      'headers' => $this->authorizationHeaders($authorization),
      'http_errors' => FALSE,
    ]);
    $this->assertSuccess($response, 'GET', $jsonldUrl, [200]);

    $resolvedSubjectUrl = $this->resolveSubjectUrl($response, $subjectUrl, $jsonldUrl);
    $sparql = $this->deleteWhere($resolvedSubjectUrl, $namedGraph) . ";\n"
      . $this->insertData($this->serializeNtriples((string) $response->getBody(), $jsonldUrl), $namedGraph);

    $this->sendUpdate($endpoint, $sparql, $authorization);
  }

  /**
   * Deletes one subject from Blazegraph.
   */
  public function deleteResource(
    string $subjectUrl,
    string $endpoint,
    ?string $authorization = NULL,
    string $namedGraph = '',
  ): void {
    $this->sendUpdate($endpoint, $this->deleteWhere($subjectUrl, $namedGraph), $authorization);
  }

  /**
   * Sends one SPARQL update request.
   */
  private function sendUpdate(string $endpoint, string $sparql, ?string $authorization = NULL): void {
    $headers = $this->authorizationHeaders($authorization);
    $headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=utf-8';

    $response = $this->httpClient->request('POST', $endpoint, [
      'headers' => $headers,
      'body' => 'update=' . rawurlencode($sparql),
      'http_errors' => FALSE,
    ]);
    $this->assertSuccess($response, 'POST', $endpoint, [200, 204]);
  }

  /**
   * Serializes JSON-LD as N-Triples for SPARQL INSERT DATA.
   */
  private function serializeNtriples(string $jsonld, string $baseUrl): string {
    $graph = new Graph($baseUrl);
    $graph->parse($jsonld, 'jsonld', $baseUrl);
    $serialized = $graph->serialise('ntriples');
    if (!is_string($serialized) || trim($serialized) === '') {
      throw new \RuntimeException(sprintf('Unable to serialize JSON-LD from %s as N-Triples.', $baseUrl));
    }

    return $serialized;
  }

  /**
   * Resolves the RDF subject URL using response metadata where possible.
   */
  private function resolveSubjectUrl(ResponseInterface $response, string $subjectUrl, string $jsonldUrl): string {
    $describes = $this->getLinkHeader($response, 'describes');
    if ($describes !== NULL && $describes !== '') {
      return $describes;
    }

    if ($subjectUrl !== '') {
      return $subjectUrl;
    }

    $fallback = preg_replace('/\?_format=jsonld$/', '', $jsonldUrl);
    return is_string($fallback) ? $fallback : $jsonldUrl;
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
   * Creates a DELETE WHERE statement for one subject.
   */
  private function deleteWhere(string $subjectUrl, string $namedGraph = ''): string {
    $statement = 'DELETE WHERE { ';
    if ($namedGraph !== '') {
      $statement .= 'GRAPH <' . $this->encodeUri($namedGraph) . '> { ';
    }
    $statement .= '<' . $this->encodeUri($subjectUrl) . '> ?p ?o ';
    if ($namedGraph !== '') {
      $statement .= '} ';
    }
    $statement .= '}';

    return $statement;
  }

  /**
   * Creates an INSERT DATA statement for serialized triples.
   */
  private function insertData(string $serializedGraph, string $namedGraph = ''): string {
    $query = 'INSERT DATA { ';
    if ($namedGraph !== '') {
      $query .= 'GRAPH <' . $this->encodeUri($namedGraph) . '> { ';
    }
    $query .= $serializedGraph;
    if ($namedGraph !== '') {
      $query .= '} ';
    }
    $query .= '}';

    return $query;
  }

  /**
   * Performs minimal SPARQL-safe URI encoding.
   */
  private function encodeUri(string $uri): string {
    return str_replace(['<', '>', '"', ' '], ['%3C', '%3E', '%22', '%20'], $uri);
  }

  /**
   * Throws on unexpected response codes.
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
