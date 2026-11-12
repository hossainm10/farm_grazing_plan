<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Plugin\QuickForm;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\farm_grazing_plan\Bundle\GrazingEventInterface;
use Drupal\farm_grazing_plan\GrazingEventWriterInterface;
use Drupal\farm_grazing_plan\GrazingPlanInterface;
use Drupal\farm_quick\Attribute\QuickForm;
use Drupal\farm_quick\Plugin\QuickForm\QuickFormBase;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Complete grazing event quick form.
 */
#[QuickForm(
  id: 'grazing_event_complete',
  label: new TranslatableMarkup('Complete grazing event'),
  description: new TranslatableMarkup('Complete a pending grazing event in a grazing plan.'),
  helpText: new TranslatableMarkup('Use this form to mark a pending grazing event as complete.'),
  permissions: [
    'update any activity log',
    'create observation log',
  ],
)]
class GrazingEventComplete extends QuickFormBase {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    protected GrazingPlanInterface $grazingPlan,
    protected RequestStack $requestStack,
    protected GrazingEventWriterInterface $grazingEventWriter,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $current_user);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    // Get the options for the grazing event select element.
    $options = $this->getGrazingEventOptions();

    // If a grazing event is specified in the query string, preselect it, as
    // long as it is one of the available options.
    $grazing_event_id = (int) $this->requestStack->getCurrentRequest()->query->get('grazing_event');
    $default = array_key_exists($grazing_event_id, $options) ? $grazing_event_id : NULL;

    // Select the grazing event to complete.
    $form['grazing_event'] = [
      '#type' => 'select',
      '#title' => $this->t('Grazing event'),
      '#description' => $this->t('Select a pending grazing event. Only events from "active" plans are included.'),
      '#options' => $options,
      '#default_value' => $default,
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * Get the options for the grazing event select element.
   *
   * The options are keyed by the grazing event ID.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   Returns the option labels, keyed by grazing event ID.
   */
  protected function getGrazingEventOptions(): array {
    $options = [];

    // Load all grazing plans with a status of "active".
    /** @var \Drupal\plan\Entity\PlanInterface[] $plans */
    $plans = $this->entityTypeManager->getStorage('plan')->loadByProperties([
      'type' => 'grazing',
      'status' => 'active',
    ]);

    // Iterate through the plans.
    foreach ($plans as $plan) {

      // If the user does not have access to update this plan, skip it.
      if (!$plan->access('update')) {
        continue;
      }

      // Iterate through each asset's grazing events.
      foreach ($this->grazingPlan->getGrazingEventsByAsset($plan) as $asset_id => $grazing_events) {

        // Load the asset.
        /** @var \Drupal\asset\Entity\AssetInterface $asset */
        $asset = $this->entityTypeManager->getStorage('asset')->load($asset_id);

        // If the user does not have access to this asset, continue to the
        // next.
        if (!$asset->access('view')) {
          continue;
        }

        // Iterate through grazing events.
        foreach ($grazing_events as $grazing_event) {

          // If the grazing event is not pending, skip it.
          if ($grazing_event->getStatus() !== 'pending') {
            continue;
          }

          // Build the option label, keeping the start value for sorting.
          $options[$grazing_event->id()] = [
            'start' => (int) $grazing_event->get('start')->value,
            'label' => $grazing_event->label(),
          ];

          // Only include the first pending event.
          break;
        }
      }
    }

    // Sort the options by their planned start dates.
    uasort($options, function ($a, $b) {
      return $a['start'] <=> $b['start'];
    });

    // Return the list of options (just the labels, keyed by grazing event ID).
    return array_map(function ($option) {
      return $option['label'];
    }, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Load the selected grazing event.
    $grazing_event = $this->loadPendingGrazingEvent($form_state->getValue('grazing_event'));

    // If the selected grazing event is missing or not pending, show an error.
    if (!$grazing_event) {
      $this->messenger()->addError($this->t('The selected grazing event does not exist or is not pending.'));
      return;
    }

    // Get the plan and log.
    $plan = $grazing_event->getPlan();
    $log = $grazing_event->getLog();

    // If the user does not have access to update the plan or the log, show an
    // error.
    if (!$plan->access('update') || !$log->access('update')) {
      $this->messenger()->addError($this->t('You do not have access to the selected grazing event.'));
      return;
    }

    // Map submitted values into the writer. Extra keys (date, notes, geometry,
    // observations) are expected from UI fields added by the form owner.
    $values = [
      'timestamp' => $this->resolveSubmittedTimestamp($form_state),
      'observations' => $form_state->getValue('observations') ?? [],
      'quick_id' => $this->getQuickId(),
    ];
    $notes= trim((string) $form_state->getValue('notes'));

    if($notes !== ''){
      $existing = (string) $log->get('notes)->value;
      $values['notes'] = [
        'value' => $existing !== ''? $existing . "\n\n" . $notes : @notes,
        'format' => $log->get('notes')->format ?? 'default',
      ];
    }

    $geometry =  $form_state->getValue('geometry');
    if (!empty($geometry)){
      $values['geometry']=$geometry;
    }

    $this->grazingEventWriter->completeGrazingEvent($grazing_event, $values);

    // Tell the user that the grazing event was completed.
    $this->messenger()->addMessage($this->t('Completed grazing event: %label', ['%label' => $grazing_event->label()]));

    // Redirect to the plan.
    $form_state->setRedirect('entity.plan.canonical', ['plan' => $plan->id()]);
  }

  /**
   * Load a pending grazing event by ID.
   *
   * @param int|string|null $grazing_event_id
   *   The grazing event ID.
   *
   * @return \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface|null
   *   The grazing event, or NULL if missing/not pending.
   */
  protected function loadPendingGrazingEvent(int|string|null $grazing_event_id): ?GrazingEventInterface {
    if (empty($grazing_event_id)) {
      return NULL;
    }

    /** @var \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface|null $grazing_event */
    $grazing_event = $this->entityTypeManager->getStorage('plan_record')->load($grazing_event_id);
    if (!$grazing_event || $grazing_event->bundle() !== 'grazing_event' || $grazing_event->getStatus() !== 'pending') {
      return NULL;
    }
    return $grazing_event;
  }

  /**
   * Resolve an actual timestamp from submitted form values.
   */
  protected function resolveSubmittedTimestamp(FormStateInterface $form_state): int {
    $date = $form_state->getValue('date');
    if ($date instanceof DrupalDateTime) {
      return $date->getTimestamp();
    }
    if (is_array($date) && isset($date['object']) && $date['object'] instanceof DrupalDateTime) {
      return $date['object']->getTimestamp();
    }
    if (is_numeric($date)) {
      return (int) $date;
    }
    if ($form_state->hasValue('timestamp') && is_numeric($form_state->getValue('timestamp'))) {
      return (int) $form_state->getValue('timestamp');
    }
    return time();
  }

}
