<?php

namespace Drupal\islandora_events_backfill\Plugin;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;

/**
 * Provides a derivative scanner plugin manager.
 */
class DerivativeScannerManager extends DefaultPluginManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/DerivativeScanner',
      $namespaces,
      $module_handler,
      'Drupal\islandora_events_backfill\Plugin\DerivativeScannerInterface',
      'Drupal\islandora_events_backfill\Attribute\DerivativeScanner',
      'Drupal\islandora_events_backfill\Annotation\DerivativeScanner',
    );
    $this->alterInfo('islandora_events_backfill_derivative_scanner_info');
    $this->setCacheBackend($cache_backend, 'islandora_events_backfill_derivative_scanner_plugins');
  }

  /**
   * Returns scanner definitions ordered by ascending priority.
   */
  public function getSortedDefinitions(): array {
    $definitions = $this->getDefinitions();
    uasort($definitions, static function ($a, $b) {
      return (($a['priority'] ?? 0) <=> ($b['priority'] ?? 0));
    });
    return $definitions;
  }

}
