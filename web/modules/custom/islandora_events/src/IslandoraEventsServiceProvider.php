<?php

namespace Drupal\islandora_events;

use Drupal\Core\DependencyInjection\ServiceProviderInterface;
use Drupal\islandora_events\DependencyInjection\SmContainerRegistration;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Registers Islandora Events messenger transports and routing with SM.
 *
 * This happens at container compile time because drupal/sm reads its transport
 * and routing definitions from compiled container parameters, not from runtime
 * config objects or ordinary service definitions.
 */
final class IslandoraEventsServiceProvider implements ServiceProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $moduleRoot = dirname(__DIR__);
    SmContainerRegistration::registerModuleFiles(
      $container,
      $moduleRoot . '/config/sm.transports.yml',
      $moduleRoot . '/config/sm.routing.yml',
    );
  }

}
