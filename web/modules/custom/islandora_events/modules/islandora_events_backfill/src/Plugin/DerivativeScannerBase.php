<?php

namespace Drupal\islandora_events_backfill\Plugin;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for derivative scanner plugins.
 */
abstract class DerivativeScannerBase extends PluginBase implements DerivativeScannerInterface, ContainerFactoryPluginInterface {

  protected const MEDIA_USE_ORIGINAL_FILE = 'http://pcdm.org/use#OriginalFile';
  protected const MEDIA_USE_SERVICE_FILE = 'http://pcdm.org/use#ServiceFile';
  protected const MEDIA_USE_EXTRACTED_TEXT = 'http://pcdm.org/use#ExtractedText';
  protected const MEDIA_USE_THUMBNAIL = 'http://pcdm.org/use#ThumbnailImage';
  protected const MEDIA_USE_HOCR = 'https://discoverygarden.ca/use#hocr';
  protected const MEDIA_USE_FITS = 'https://projects.iq.harvard.edu/fits';

  /**
   * Constructs a scanner.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected Connection $database,
    protected LoggerInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('database'),
      $container->get('logger.channel.islandora_events_backfill'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string {
    return (string) $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return (string) $this->pluginDefinition['description'];
  }

  /**
   * {@inheritdoc}
   */
  public function getAction(): string {
    return (string) $this->pluginDefinition['action'];
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityType(): string {
    return (string) $this->pluginDefinition['entity_type'];
  }

  /**
   * {@inheritdoc}
   */
  public function getEventType(): string {
    return (string) $this->pluginDefinition['event_type'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFrequency(): int {
    return (int) $this->pluginDefinition['frequency'];
  }

  /**
   * {@inheritdoc}
   */
  public function getPriority(): int {
    return (int) ($this->pluginDefinition['priority'] ?? 0);
  }

  /**
   * {@inheritdoc}
   */
  public function findMissingDerivatives(): array {
    try {
      $query = $this->getScanQuery();
      return $this->database->query($query['sql'], $query['args'] ?? [])->fetchCol();
    }
    catch (\Exception $e) {
      $this->logger->error('Error running derivative scan query for @plugin: @message', [
        '@plugin' => $this->getPluginId(),
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Builds the common join for a media-use term external URI field.
   */
  protected function mediaUseJoin(string $mediaUseAlias, string $uriAlias): string {
    return "INNER JOIN taxonomy_term_revision__field_external_uri {$uriAlias} ON {$uriAlias}.entity_id = {$mediaUseAlias}.field_media_use_target_id AND {$uriAlias}.deleted = 0";
  }

  /**
   * Gets the parameterized query for finding missing derivatives.
   *
   * @return array{sql: string, args?: array<string, mixed>}
   *   Query text plus optional named parameters.
   */
  abstract protected function getScanQuery(): array;

}
