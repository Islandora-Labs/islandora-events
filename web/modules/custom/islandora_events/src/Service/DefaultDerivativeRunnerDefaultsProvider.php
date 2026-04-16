<?php

namespace Drupal\islandora_events\Service;

/**
 * Provides built-in runner defaults for the standard derivative queues.
 *
 * These defaults are intentionally static. Environment-specific overrides
 * belong in Drupal configuration or settings.php config overrides rather than
 * getenv()/$_ENV lookups here.
 */
final class DefaultDerivativeRunnerDefaultsProvider implements DerivativeRunnerDefaultsProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function getDefaults(): array {
    return [
      'islandora-connector-fits' => [
        'execution_mode' => 'command',
        'endpoint' => 'http://fits:8080/',
        'timeout' => 300,
        'config_path' => '/opt/scyllaridae/crayfits/scyllaridae.yml',
        'command' => '/usr/bin/scyllaridae',
        'env_vars' => ['CRAYFITS_WEBSERVICE_URI'],
      ],
      'islandora-connector-homarus' => [
        'execution_mode' => 'command',
        'endpoint' => 'http://homarus:8080/',
        'timeout' => 300,
        'config_path' => '/opt/scyllaridae/homarus/scyllaridae.yml',
        'command' => '/usr/bin/scyllaridae',
      ],
      'islandora-connector-houdini' => [
        'execution_mode' => 'command',
        'endpoint' => 'http://houdini:8080/',
        'timeout' => 300,
        'config_path' => '/opt/scyllaridae/houdini/scyllaridae.yml',
        'command' => '/usr/bin/scyllaridae',
      ],
      'islandora-connector-ocr' => [
        'execution_mode' => 'command',
        'endpoint' => 'http://hypercube:8080/',
        'timeout' => 300,
        'config_path' => '/opt/scyllaridae/hypercube/scyllaridae.yml',
        'command' => '/usr/bin/scyllaridae',
      ],
      'islandora-connector-transkribus' => [
        'execution_mode' => 'http',
        'endpoint' => 'http://transkribus:5000/',
        'timeout' => 300,
      ],
      'islandora-connector-mergepdf' => [
        'execution_mode' => 'command',
        'endpoint' => 'http://mergepdf:8080/',
        'timeout' => 300,
        'config_path' => '/opt/scyllaridae/mergepdf/scyllaridae.yml',
        'command' => '/usr/bin/scyllaridae',
        'write_back' => FALSE,
      ],
    ];
  }

}
