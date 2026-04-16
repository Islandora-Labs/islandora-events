<?php

namespace Drupal\islandora_events_mergepdf;

use Drupal\Core\DependencyInjection\ServiceProviderInterface;
use Drupal\islandora_events\DependencyInjection\SmContainerRegistration;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Registers Merge PDF messenger transports and routing with SM.
 *
 * This happens at container compile time because drupal/sm consumes routing
 * and transport definitions from compiled container parameters.
 */
final class IslandoraEventsMergepdfServiceProvider implements ServiceProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $moduleRoot = dirname(__DIR__);
    SmContainerRegistration::registerModuleFiles(
      $container,
      $moduleRoot . '/config/install/sm.transports.yml',
      $moduleRoot . '/config/install/sm.routing.yml',
    );
  }

}
