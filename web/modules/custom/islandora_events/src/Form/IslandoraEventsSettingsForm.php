<?php

namespace Drupal\islandora_events\Form;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\islandora_events\Index\IndexTargetManager;
use Drupal\islandora_events\Service\DerivativeCommandPolicyInterface;
use Drupal\islandora_events\Service\DerivativeRunnerConfigRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings form for index target endpoints.
 */
class IslandoraEventsSettingsForm extends ConfigFormBase {

  /**
   * Constructs the settings form.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    protected DerivativeCommandPolicyInterface $commandPolicy,
    protected IndexTargetManager $indexTargetManager,
    protected DerivativeRunnerConfigRegistry $runnerConfigRegistry,
  ) {
    parent::__construct($config_factory);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get(DerivativeCommandPolicyInterface::class),
      $container->get('islandora_events.index_target_manager'),
      $container->get('islandora_events.derivative_runner_config_registry'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    $configNames = [];
    foreach ($this->indexTargetManager->all() as $target) {
      $configNames[] = $target->getConfigName();
    }
    foreach ($this->runnerConfigRegistry->all() as $provider) {
      $configNames[] = $provider->getConfigName();
    }

    return array_values(array_unique($configNames));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'islandora_events_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $targetDefinitions = $this->indexTargetManager->all();
    $targetIds = array_keys($targetDefinitions);
    sort($targetIds);

    foreach ($targetIds as $targetId) {
      $target = $targetDefinitions[$targetId];
      $detailsKey = 'target_' . $targetId;
      $enabledKey = $targetId . '_enabled';
      $endpointKey = $targetId . '_endpoint';
      $timeoutKey = $targetId . '_timeout';
      $namedGraphKey = $targetId . '_named_graph';
      $label = $target->getLabel();

      $form[$detailsKey] = [
        '#type' => 'details',
        '#title' => $this->t('@label indexing target', ['@label' => $label]),
        '#open' => TRUE,
      ];
      $form[$detailsKey][$enabledKey] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable @label indexing target', ['@label' => $label]),
        '#default_value' => (bool) $this->getTargetConfigValue($target, 'enabled'),
      ];
      $form[$detailsKey][$endpointKey] = [
        '#type' => 'url',
        '#title' => $this->t('@label endpoint', ['@label' => $label]),
        '#default_value' => (string) $this->getTargetConfigValue($target, 'endpoint'),
        '#description' => $this->t('Connection endpoint used by the @label indexing target. For direct Fedora/fcrepo indexing this should be the Fedora REST base URL.', ['@label' => $label]),
      ];
      $form[$detailsKey][$timeoutKey] = [
        '#type' => 'number',
        '#title' => $this->t('@label timeout (seconds)', ['@label' => $label]),
        '#default_value' => (int) $this->getTargetConfigValue($target, 'timeout'),
        '#min' => 1,
      ];
      if ($targetId === 'blazegraph') {
        $form[$detailsKey][$namedGraphKey] = [
          '#type' => 'textfield',
          '#title' => $this->t('Named graph'),
          '#default_value' => (string) $this->getTargetConfigValue($target, 'named_graph'),
          '#description' => $this->t('Optional named graph URI to wrap Blazegraph SPARQL updates in `GRAPH <...> { ... }`. Leave blank to write to the default graph.'),
        ];
      }
    }

    $form['derivative_runners'] = [
      '#type' => 'details',
      '#title' => $this->t('Derivative queue runners'),
      '#open' => FALSE,
    ];
    foreach ($this->runnerConfigRegistry->all() as $provider) {
      $elementName = $this->runnerProviderElementKey($provider->getConfigName());
      $definitions = $this->getDerivativeRunnerDefinitions($provider->getConfigName());
      $form['derivative_runners'][$elementName] = [
        '#type' => 'textarea',
        '#title' => $this->t('@label definitions', ['@label' => $provider->getLabel()]),
        '#default_value' => Yaml::encode($definitions),
        '#description' => $this->t('YAML keyed by queue name. Each module owns its own derivative runner config object instead of storing all queues in one monolithic settings blob.'),
        '#rows' => 12,
      ];
    }
    $form['derivative_runners']['command_policy_notice'] = [
      '#type' => 'item',
      '#title' => $this->t('Command-mode policy'),
      '#markup' => $this->t('Command-mode approval is read from `settings.php`, not editable in the UI. Current status: @status.', [
        '@status' => $this->commandPolicy->isExecutionEnabled() ? 'enabled' : 'disabled',
      ]),
    ];

    $form['runtime_defaults'] = [
      '#type' => 'details',
      '#title' => $this->t('Shared Runtime Settings'),
      '#open' => FALSE,
    ];
    $form['runtime_defaults']['runtime_defaults_help'] = [
      '#type' => 'item',
      '#markup' => $this->t('Circuit-breaker thresholds, worker timeouts, and related shared runtime defaults now live under <code>/admin/config/services/sm-workers/settings</code>. Islandora Events owns queue and target mappings here; SM Workers owns generic worker runtime policy.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    foreach ($this->indexTargetManager->all() as $targetId => $target) {
      $enabledKey = $targetId . '_enabled';
      $endpointKey = $targetId . '_endpoint';
      if ($form_state->getValue($enabledKey) && !$form_state->getValue($endpointKey)) {
        $form_state->setErrorByName(
          $endpointKey,
          $this->t('@label endpoint is required when enabled.', ['@label' => $target->getLabel()]),
        );
      }
    }
    foreach ($this->runnerConfigRegistry->all() as $provider) {
      $elementName = $this->runnerProviderElementKey($provider->getConfigName());
      $this->validateRunnerYaml(
        $form_state,
        $elementName,
        trim((string) $form_state->getValue($elementName)),
      );
    }

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    foreach ($this->indexTargetManager->all() as $targetId => $target) {
      $config = $this->configFactory->getEditable($target->getConfigName());
      $config
        ->set('enabled', (bool) $form_state->getValue($targetId . '_enabled'))
        ->set('endpoint', (string) $form_state->getValue($targetId . '_endpoint'))
        ->set('timeout', (int) $form_state->getValue($targetId . '_timeout'));
      if ($targetId === 'blazegraph') {
        $config->set(
          'named_graph',
          trim((string) $form_state->getValue($targetId . '_named_graph')),
        );
      }
      $config->save();
    }

    foreach ($this->runnerConfigRegistry->all() as $provider) {
      $runnerDefinitions = [];
      $elementName = $this->runnerProviderElementKey($provider->getConfigName());
      $runnerYaml = trim((string) $form_state->getValue($elementName));
      if ($runnerYaml !== '') {
        $runnerDefinitions = Yaml::decode($runnerYaml);
        $runnerDefinitions = is_array($runnerDefinitions) ? $runnerDefinitions : [];
      }

      $this->configFactory->getEditable($provider->getConfigName())
        ->set('runners', $runnerDefinitions)
        ->save();
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Extracts the binary from a command template.
   */
  private function extractCommandBinary(string $command): string {
    $tokens = str_getcsv($command, ' ', '"', '\\');
    $tokens = array_values(array_filter($tokens, static fn (string $token): bool => $token !== ''));
    return $tokens[0] ?? '';
  }

  /**
   * Returns configured derivative runners as a plain mapping.
   *
   * @return array<string, array<string, mixed>>
   *   Queue definitions.
   */
  private function getDerivativeRunnerDefinitions(string $configName): array {
    $definitions = $this->config($configName)->get('runners');
    return is_array($definitions) ? $definitions : [];
  }

  /**
   * Validates one derivative runner YAML textarea.
   */
  private function validateRunnerYaml(FormStateInterface $formState, string $elementName, string $runnerYaml): void {
    if ($runnerYaml === '') {
      return;
    }

    try {
      $parsed = Yaml::decode($runnerYaml);
      if (!is_array($parsed)) {
        $formState->setErrorByName($elementName, $this->t('Derivative runner YAML must decode to a mapping keyed by queue name.'));
        return;
      }

      foreach ($parsed as $queue => $runner) {
        if (!is_array($runner)) {
          continue;
        }

        $mode = (string) ($runner['execution_mode'] ?? 'http');
        if ($mode !== 'command') {
          continue;
        }

        if (!$this->commandPolicy->isExecutionEnabled()) {
          $formState->setErrorByName($elementName, $this->t('Queue %queue uses `execution_mode: command`, but command-mode derivative execution is disabled.', ['%queue' => (string) $queue]));
          continue;
        }

        $command = trim((string) ($runner['command'] ?? ''));
        $binary = $this->extractCommandBinary($command);
        if ($binary === '') {
          $formState->setErrorByName($elementName, $this->t('Queue %queue uses command mode but does not declare a command.', ['%queue' => (string) $queue]));
          continue;
        }
        if (!$this->commandPolicy->isAllowedBinary($binary)) {
          $formState->setErrorByName($elementName, $this->t('Queue %queue uses disallowed command binary %binary.', [
            '%queue' => (string) $queue,
            '%binary' => $binary,
          ]));
        }
      }
    }
    catch (\Throwable $e) {
      $formState->setErrorByName($elementName, $this->t('Invalid derivative runner YAML: @message', ['@message' => $e->getMessage()]));
    }
  }

  /**
   * Builds a stable form element name for one provider config object.
   */
  private function runnerProviderElementKey(string $configName): string {
    return 'derivative_runners__' . strtr($configName, ['.' => '_']);
  }

  /**
   * Returns one target config value.
   */
  private function getTargetConfigValue(object $target, string $key): mixed {
    return $this->config($target->getConfigName())->get($key);
  }

}
