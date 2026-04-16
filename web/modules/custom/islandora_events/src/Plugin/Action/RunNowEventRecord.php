<?php

namespace Drupal\islandora_events\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\sm_ledger\Entity\EventRecordInterface;
use Drupal\sm_ledger\Service\LedgerOperatorService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Executes ledger records immediately.
 *
 * @Action(
 *   id = "islandora_events_run_now_event_record",
 *   label = @Translation("Run now"),
 *   type = "event_record"
 * )
 */
final class RunNowEventRecord extends ActionBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs the action plugin.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private LedgerOperatorService $ledgerOperator,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('sm_ledger.operator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL): void {
    if (!$entity instanceof EventRecordInterface) {
      return;
    }

    if (!$this->ledgerOperator->canRunNow($entity)) {
      return;
    }

    $this->ledgerOperator->runNow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE) {
    $access = AccessResult::allowedIfHasPermission($account, 'administer sm ledger');
    if ($object instanceof EventRecordInterface) {
      $access = $access->andIf(AccessResult::allowedIf($this->ledgerOperator->canRunNow($object)));
    }

    return $return_as_object ? $access : $access->isAllowed();
  }

}
