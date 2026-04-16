<?php

namespace Drupal\islandora_events\Form;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\sm_ledger\Entity\EventRecordInterface;
use Drupal\sm_ledger\Service\LedgerOperatorService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for running an event record immediately.
 */
class EventRecordRunNowConfirmForm extends ConfirmFormBase {

  /**
   * The event record.
   *
   * @var \Drupal\sm_ledger\Entity\EventRecordInterface|null
   */
  protected ?EventRecordInterface $eventRecord = NULL;

  /**
   * Constructs the run-now confirm form.
   */
  public function __construct(
    private LedgerOperatorService $ledgerOperator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('sm_ledger.operator'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'islandora_events_event_record_run_now_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): TranslatableMarkup {
    return $this->t('Run event record @id now?', ['@id' => $this->eventRecord?->id() ?? 0]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): TranslatableMarkup {
    return $this->t('This will execute the stored event immediately in the current request.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Run now');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return $this->eventRecord
      ? $this->eventRecord->toUrl('canonical')
      : Url::fromRoute('entity.event_record.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
    ?EventRecordInterface $event_record = NULL,
  ): array {
    $this->eventRecord = $event_record;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->eventRecord) {
      $this->messenger()->addError($this->t('Event record was not found.'));
      $form_state->setRedirectUrl(Url::fromRoute('entity.event_record.collection'));
      return;
    }

    if (!$this->ledgerOperator->canRunNow($this->eventRecord)) {
      $this->messenger()->addError($this->t(
        'This event record cannot be run immediately by the current ledger handlers.'
      ));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    try {
      $this->ledgerOperator->runNow($this->eventRecord);
      $this->messenger()->addStatus($this->t(
        'Event record @id completed.',
        ['@id' => $this->eventRecord->id()]
      ));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t(
        'Event record @id failed: @message',
        [
          '@id' => $this->eventRecord->id(),
          '@message' => $e->getMessage(),
        ]
      ));
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
