<?php

declare(strict_types=1);

namespace Drupal\Tests\islandora_events\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\islandora\IslandoraUtils;
use Drupal\islandora\MediaSource\MediaSourceService;
use Drupal\islandora_events\Hook\EntityHooks;
use Drupal\islandora_events\Index\IndexTargetManager;
use Drupal\islandora_events\Message\BlazegraphIndexEventMessage;
use Drupal\islandora_events\Message\FedoraIndexEventMessage;
use Drupal\islandora_events\Message\CustomIndexEventMessage;
use Drupal\islandora_events\Message\IndexEventMessage;
use Drupal\islandora_events\Message\IslandoraDerivativeMessage;
use Drupal\islandora_events\Service\DerivativeRunnerService;
use Drupal\islandora_events\Service\IndexEventService;
use Drupal\islandora_events\Service\DerivativeQueueService;
use Drupal\sm_ledger\Entity\EventRecord;
use Drupal\sm_ledger\Service\EventRecordService;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

/**
 * Verifies runtime container integration with Islandora and SM.
 */
#[RunTestsInSeparateProcesses]
final class IslandoraEventsIntegrationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'action',
    'basic_auth',
    'block',
    'content_translation',
    'context',
    'context_ui',
    'ctools',
    'eva',
    'file',
    'file_replace',
    'filehash',
    'filter',
    'flysystem',
    'hal',
    'jsonld',
    'jwt',
    'key',
    'language',
    'link',
    'media',
    'migrate_plus',
    'migrate_source_csv',
    'node',
    'options',
    'path',
    'prepopulate',
    'rdf',
    'rest',
    'search_api',
    'serialization',
    'sm',
    'sm_ledger',
    'sm_workers',
    'system',
    'taxonomy',
    'text',
    'token',
    'user',
    'views',
    'views_ui',
    'islandora',
    'islandora_events',
    'islandora_events_blazegraph',
    'islandora_events_fcrepo',
  ];

  /**
   * Tests service aliases required for Drupal 11 autowiring.
   */
  public function testServiceAliasesAndHookSubscriberAreResolvable(): void {
    $container = \Drupal::getContainer();

    static::assertSame(
      $container->get('sm_ledger.event_record'),
      $container->get(EventRecordService::class),
    );
    static::assertSame(
      $container->get('islandora_events.index_event'),
      $container->get(IndexEventService::class),
    );
    static::assertSame(
      $container->get('islandora_events.derivative_queue'),
      $container->get(DerivativeQueueService::class),
    );
    static::assertSame(
      $container->get('islandora_events.derivative_runner'),
      $container->get(DerivativeRunnerService::class),
    );
    static::assertSame(
      $container->get('islandora.utils'),
      $container->get(IslandoraUtils::class),
    );
    static::assertSame(
      $container->get('islandora.media_source_service'),
      $container->get(MediaSourceService::class),
    );

    static::assertTrue($container->has(EntityHooks::class));
    static::assertInstanceOf(EntityHooks::class, $container->get(EntityHooks::class));
  }

  /**
   * Tests SM routing and transports are registered for Islandora Events.
   */
  public function testSmRoutingAndReceiversAreRegistered(): void {
    $definition = $this->container->getDefinition(SendersLocatorInterface::class);
    $messageToSendersMapping = $definition->getArgument(0);
    $smRouting = $this->container->getParameter('sm.routing');

    static::assertSame(
      ['islandora_derivatives'],
      $this->normalizeSenders($messageToSendersMapping[IslandoraDerivativeMessage::class] ?? NULL),
    );
    static::assertSame(
      ['islandora_index_fedora'],
      $this->normalizeSenders($messageToSendersMapping[FedoraIndexEventMessage::class] ?? NULL),
    );
    static::assertSame(
      ['islandora_index_blazegraph'],
      $this->normalizeSenders($messageToSendersMapping[BlazegraphIndexEventMessage::class] ?? NULL),
    );
    static::assertSame(
      ['islandora_index_custom'],
      $this->normalizeSenders($messageToSendersMapping[CustomIndexEventMessage::class] ?? NULL),
    );
    static::assertSame(
      ['islandora_derivatives'],
      $this->normalizeSenders($smRouting[IslandoraDerivativeMessage::class] ?? NULL),
    );
    static::assertSame(
      ['islandora_index_fedora'],
      $this->normalizeSenders($smRouting[FedoraIndexEventMessage::class] ?? NULL),
    );
    static::assertSame(
      ['islandora_index_blazegraph'],
      $this->normalizeSenders($smRouting[BlazegraphIndexEventMessage::class] ?? NULL),
    );
    static::assertSame(
      ['islandora_index_custom'],
      $this->normalizeSenders($smRouting[CustomIndexEventMessage::class] ?? NULL),
    );

    static::assertTrue($this->container->hasDefinition('sm.transport.islandora_derivatives'));
    static::assertTrue($this->container->hasDefinition('sm.transport.islandora_index_fedora'));
    static::assertTrue($this->container->hasDefinition('sm.transport.islandora_index_blazegraph'));
    static::assertTrue($this->container->hasDefinition('sm.transport.islandora_index_custom'));

    $receiverLocatorArg = $definition->getArgument(1);
    static::assertInstanceOf(Reference::class, $receiverLocatorArg);
    $receiverLocator = $this->container->get((string) $receiverLocatorArg);
    $providedServices = $receiverLocator->getProvidedServices();

    static::assertArrayHasKey('islandora_derivatives', $providedServices);
    static::assertArrayHasKey('islandora_index_fedora', $providedServices);
    static::assertArrayHasKey('islandora_index_blazegraph', $providedServices);
    static::assertArrayHasKey('islandora_index_custom', $providedServices);
    static::assertArrayHasKey('sm.transport.islandora_derivatives', $providedServices);
    static::assertArrayHasKey('sm.transport.islandora_index_fedora', $providedServices);
    static::assertArrayHasKey('sm.transport.islandora_index_blazegraph', $providedServices);
    static::assertArrayHasKey('sm.transport.islandora_index_custom', $providedServices);
  }

  /**
   * Tests message handlers are registered on the default SM bus.
   */
  public function testMessageHandlersAreRegistered(): void {
    $handlersLocator = $this->container->getDefinition('sm.bus.default.messenger.handlers_locator');
    $handlersByMessage = $handlersLocator->getArgument(0);

    static::assertArrayHasKey(IslandoraDerivativeMessage::class, $handlersByMessage);
    static::assertArrayHasKey(IndexEventMessage::class, $handlersByMessage);
  }

  /**
   * Tests direct index submodules register their tagged targets.
   */
  public function testDirectIndexTargetsAreDiscoverable(): void {
    $manager = $this->container->get('islandora_events.index_target_manager');
    static::assertInstanceOf(IndexTargetManager::class, $manager);

    $targets = $manager->all();
    static::assertArrayHasKey('fedora', $targets);
    static::assertArrayHasKey('blazegraph', $targets);
    static::assertSame('Fedora/fcrepo', $targets['fedora']->getLabel());
    static::assertSame('Blazegraph', $targets['blazegraph']->getLabel());
  }

  /**
   * Normalizes SM sender mappings from container definitions.
   */
  private function normalizeSenders(mixed $mapping): array {
    if (is_array($mapping) && isset($mapping['senders']) && is_array($mapping['senders'])) {
      return array_values($mapping['senders']);
    }

    if (!is_array($mapping)) {
      return [];
    }

    return array_values($mapping);
  }

  /**
   * Tests enum fields expose allowed values suitable for Views option filters.
   */
  public function testEnumFieldsUseListStorageDefinitions(): void {
    $definitions = $this->container
      ->get('entity_field.manager')
      ->getFieldStorageDefinitions('event_record');

    foreach ([
      'status' => EventRecord::getStatusOptions(),
      'event_kind' => EventRecord::getEventKindOptions(),
      'transport_mode' => EventRecord::getTransportModeOptions(),
    ] as $field_name => $allowed_values) {
      static::assertSame('list_string', $definitions[$field_name]->getType());
      static::assertSame($allowed_values, $definitions[$field_name]->getSetting('allowed_values'));
    }
  }

}
