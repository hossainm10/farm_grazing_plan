<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan\Plugin\QuickForm;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\farm_grazing_plan\GrazingEventWriterInterface;
use Drupal\farm_grazing_plan\GrazingPlanInterface;
use Drupal\farm_location\AssetLocationInterface;
use Drupal\farm_quick\Attribute\QuickForm;
use Drupal\farm_quick\Plugin\QuickForm\QuickFormBase;
use Drupal\farm_quick\Traits\QuickFormElementsTrait;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Complete grazing event quick form.
 *
 * Marks a pending grazing event as done and records the actual date, notes,
 * and geometry on its movement log. Graze height and attachments (photos and
 * documents, saved in the farm_attachments field) are recorded on an
 * observation log for the paddock the animals entered, and residual height on
 * an observation log for the paddock they left. Saving is done by the
 * grazing event writer service (farm_grazing_plan.event_writer).
 *
 * The form also shows notifications: pending grazing events that are past
 * their planned start (overdue), and the most recently completed grazing
 * events.
 */
#[QuickForm(
  id: 'grazing_event_complete',
  label: new TranslatableMarkup('Complete grazing event'),
  description: new TranslatableMarkup('Complete a pending grazing event in a grazing plan.'),
  helpText: new TranslatableMarkup('Use this form when animals move into the paddock of a pending grazing event. Record the actual date, the graze height of the paddock they are entering, and the residual height of the paddock they are leaving.'),
  permissions: [
    'update any activity log',
    'create observation log',
  ],
)]
class GrazingEventComplete extends QuickFormBase {

  use QuickFormElementsTrait;

  /**
   * The units that forage heights are recorded in.
   */
  public const HEIGHT_UNITS = 'in';

  /**
   * File types allowed by the farm_attachments field.
   */
  public const ATTACHMENT_EXTENSIONS = 'jpg jpeg png webp pdf docx';

  /**
   * Largest allowed attachment, matching the farm_attachments field (10 MB).
   */
  public const ATTACHMENT_MAX_BYTES = 10 * 1024 * 1024;

  /**
   * How many recently completed grazing events to show.
   */
  public const COMPLETED_LIMIT = 10;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    protected GrazingPlanInterface $grazingPlan,
    protected RequestStack $requestStack,
    protected AssetLocationInterface $assetLocation,
    protected GrazingEventWriterInterface $grazingEventWriter,
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $current_user);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    // Get the grazing events the user can see, and the options for the
    // grazing event select element.
    $event_lists = $this->getGrazingEventLists();
    $options = $this->getGrazingEventOptions($event_lists);

    // If a grazing event is specified in the query string, preselect it, as
    // long as it is one of the available options.
    $grazing_event_id = (int) $this->requestStack->getCurrentRequest()->query->get('grazing_event');
    $default = array_key_exists($grazing_event_id, $options) ? $grazing_event_id : NULL;

    // Show overdue and recently completed grazing events.
    $form['notifications'] = $this->buildNotifications($event_lists, $options);

    // Select the grazing event to complete.
    $form['grazing_event'] = [
      '#type' => 'select',
      '#title' => $this->t('Grazing event'),
      '#description' => $this->t('Select a pending grazing event. Only events from "active" plans are included.'),
      '#options' => $options,
      '#default_value' => $default,
      '#required' => TRUE,
    ];

    // Actual start date (log timestamp).
    $form['timestamp'] = [
      '#type' => 'datetime',
      '#title' => $this->t('Actual start date'),
      '#default_value' => new DrupalDateTime('now', $this->currentUser->getTimeZone()),
      '#required' => TRUE,
    ];

    // Graze and residual heights, side by side, with the units after each
    // field.
    $form['heights'] = $this->buildInlineContainer();
    $form['heights']['graze_height'] = [
      '#type' => 'number',
      '#title' => $this->t('Graze height'),
      '#description' => $this->t('Forage height in the paddock the animals are entering.'),
      '#field_suffix' => static::HEIGHT_UNITS,
      '#min' => 0,
      '#step' => 0.1,
    ];
    $form['heights']['residual_height'] = [
      '#type' => 'number',
      '#title' => $this->t('Residual height'),
      '#description' => $this->t('Forage height left in the paddock the animals are leaving.'),
      '#field_suffix' => static::HEIGHT_UNITS,
      '#min' => 0,
      '#step' => 0.1,
    ];

    // Photos and documents. These are saved in the private "Attachments"
    // field from the farm_attachments module, with the same folder, file
    // types, and size limit as that field.
    $form['attachments'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Photos and documents'),
      '#description' => $this->t('Photos or documents about the paddock the animals are entering. Allowed types: @types. Up to 10 MB each.', [
        '@types' => static::ATTACHMENT_EXTENSIONS,
      ]),
      '#multiple' => TRUE,
      '#upload_location' => 'private://attachments/' . date('Y-m'),
      '#upload_validators' => [
        'FileExtension' => [
          'extensions' => static::ATTACHMENT_EXTENSIONS,
        ],
        'FileSizeLimit' => [
          'fileLimit' => static::ATTACHMENT_MAX_BYTES,
        ],
      ],
    ];

    // Notes.
    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notes'),
      '#rows' => 3,
    ];

    // Geometry. If a grazing event is preselected, start from its paddock's
    // shape, so the map opens on the paddock.
    $default_geometry = '';
    if ($default !== NULL) {
      /** @var \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface|null $preselected */
      $preselected = $this->entityTypeManager->getStorage('plan_record')->load($default);
      if ($preselected) {
        $default_geometry = $this->assetLocation->getGeometry($preselected->getLocation());
      }
    }
    $form['geometry'] = [
      '#type' => 'farm_map_input',
      '#title' => $this->t('Geometry'),
      '#description' => $this->t('Optionally draw or edit the area that was grazed. Leave it as is to use the whole paddock.'),
      '#default_value' => $default_geometry,
    ];

    // Remember the starting geometry, so that submitForm() can tell whether
    // the user actually drew or edited anything.
    $form['default_geometry'] = [
      '#type' => 'value',
      '#value' => $default_geometry,
    ];

    return $form;
  }

  /**
   * Get the grazing events the user can see, one ordered list per asset.
   *
   * Only events in "active" grazing plans that the user can update, for
   * assets the user can view, are included.
   *
   * @return \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface[][]
   *   A list of grazing event lists. Each inner list is one asset's grazing
   *   events in one plan, in plan order.
   */
  protected function getGrazingEventLists(): array {
    $event_lists = [];

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

        $event_lists[] = $grazing_events;
      }
    }

    return $event_lists;
  }

  /**
   * Get the options for the grazing event select element.
   *
   * Each asset's first pending grazing event is an option, keyed by the
   * grazing event ID.
   *
   * @param \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface[][] $event_lists
   *   Grazing event lists from getGrazingEventLists().
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   Returns the option labels, keyed by grazing event ID.
   */
  protected function getGrazingEventOptions(array $event_lists): array {
    $options = [];

    foreach ($event_lists as $grazing_events) {
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
    /** @var \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface|null $grazing_event */
    $grazing_event = $this->entityTypeManager->getStorage('plan_record')->load($form_state->getValue('grazing_event'));

    // If the selected grazing event is missing or not pending, show an error.
    if (!$grazing_event || $grazing_event->bundle() !== 'grazing_event' || $grazing_event->getStatus() !== 'pending') {
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

    // Get the actual date as a timestamp.
    $date = $form_state->getValue('timestamp');
    $timestamp = $date instanceof DrupalDateTime ? $date->getTimestamp() : time();

    // Build the values for the grazing event writer. Graze height and
    // attachments go on the paddock the animals entered ("next"), and
    // residual height on the paddock they left ("previous").
    $values = [
      'timestamp' => $timestamp,
      'notes' => (string) $form_state->getValue('notes'),
      'quick_id' => $this->getQuickId(),
      'observations' => [
        'next' => [
          'quantities' => [
            $this->buildHeightQuantity($this->t('Graze height'), $form_state->getValue('graze_height')),
          ],
          'attachments' => array_values(array_map('intval', array_filter((array) $form_state->getValue('attachments')))),
        ],
        'previous' => [
          'quantities' => [
            $this->buildHeightQuantity($this->t('Residual height'), $form_state->getValue('residual_height')),
          ],
        ],
      ],
    ];

    // Only save geometry if the user drew or edited one. The map starts with
    // the preselected event's paddock, so an unchanged map is skipped. This
    // also stops one paddock's shape being saved on another event when the
    // user picks a different event in the dropdown.
    $geometry = (string) $form_state->getValue('geometry');
    if ($geometry !== '' && $geometry !== (string) $form_state->getValue('default_geometry')) {
      $values['geometry'] = $geometry;
    }

    // Complete the grazing event: mark the movement log done on the actual
    // date, append notes, and create the paddock observation logs.
    $this->grazingEventWriter->completeGrazingEvent($grazing_event, $values);

    // The residual height can only be saved if there is a paddock the animals
    // left. Warn the user if they entered one for the asset's first event.
    $residual_height = $form_state->getValue('residual_height');
    if ($residual_height !== '' && $residual_height !== NULL && !$this->grazingEventWriter->getPreviousPaddock($grazing_event)) {
      $this->messenger()->addWarning($this->t('The residual height was not saved, because this is the first grazing event for %asset in the plan.', [
        '%asset' => $grazing_event->getAsset()->label(),
      ]));
    }

    // Tell the user that the grazing event was completed.
    $this->messenger()->addMessage($this->t('Completed grazing event: %label', ['%label' => $grazing_event->label()]));

    // Redirect to the plan.
    $form_state->setRedirect('entity.plan.canonical', ['plan' => $plan->id()]);
  }

  /**
   * Build a forage height quantity for the grazing event writer.
   *
   * @param \Stringable|string $label
   *   The quantity label.
   * @param mixed $value
   *   The submitted height, or an empty value. Empty heights are skipped by
   *   the writer.
   *
   * @return array
   *   The quantity values.
   */
  protected function buildHeightQuantity($label, $value): array {
    return [
      'label' => (string) $label,
      'value' => $value,
      'units' => static::HEIGHT_UNITS,
      'measure' => 'length',
    ];
  }

  /**
   * Build the notifications section: overdue and recently completed events.
   *
   * Both lists are read from the grazing plans themselves, so they include
   * events completed before this form existed or outside of it.
   *
   * @param \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface[][] $event_lists
   *   Grazing event lists from getGrazingEventLists().
   * @param array $options
   *   The grazing event select options, keyed by grazing event ID.
   *
   * @return array
   *   A details render element.
   */
  protected function buildNotifications(array $event_lists, array $options): array {
    $now = $this->time->getCurrentTime();

    // Sort the events into overdue (pending, past their planned start) and
    // completed (done).
    $overdue = [];
    $completed = [];
    foreach ($event_lists as $grazing_events) {
      foreach ($grazing_events as $grazing_event) {
        $status = $grazing_event->getStatus();
        if ($status === 'pending' && (int) $grazing_event->get('start')->value < $now) {
          $overdue[] = $grazing_event;
        }
        elseif ($status === 'done') {
          $completed[] = $grazing_event;
        }
      }
    }

    // Most overdue first.
    usort($overdue, function ($a, $b) {
      return (int) $a->get('start')->value <=> (int) $b->get('start')->value;
    });

    // Most recently completed first, by the actual date on the movement log.
    usort($completed, function ($a, $b) {
      return (int) $b->getLog()->get('timestamp')->value <=> (int) $a->getLog()->get('timestamp')->value;
    });
    $completed = array_slice($completed, 0, static::COMPLETED_LIMIT);

    $element = [
      '#type' => 'details',
      '#title' => $this->formatPlural(count($overdue), 'Notifications (1 overdue)', 'Notifications (@count overdue)'),
      // Open by default when something needs attention.
      '#open' => !empty($overdue),
      '#weight' => -100,
    ];

    // Overdue grazing events. Events that can be completed now link to this
    // form with the event preselected.
    $items = [];
    foreach ($overdue as $grazing_event) {
      $days = (int) floor(($now - (int) $grazing_event->get('start')->value) / 86400);
      $text = $this->t('@label: @overdue', [
        '@label' => $grazing_event->label(),
        '@overdue' => $days > 0
          ? $this->formatPlural($days, '1 day overdue', '@count days overdue')
          : $this->t('due today'),
      ]);
      if (isset($options[$grazing_event->id()])) {
        $items[] = [
          '#type' => 'link',
          '#title' => $text,
          '#url' => Url::fromRoute('<current>', [], ['query' => ['grazing_event' => $grazing_event->id()]]),
        ];
      }
      else {
        $items[] = $this->t('@text (complete the earlier event first)', ['@text' => $text]);
      }
    }
    $element['overdue'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Overdue'),
      '#items' => $items,
      '#empty' => $this->t('No grazing events are overdue.'),
    ];

    // Recently completed grazing events, linking to their movement logs.
    $items = [];
    foreach ($completed as $grazing_event) {
      $log = $grazing_event->getLog();
      $items[] = [
        '#type' => 'link',
        '#title' => $this->t('@asset to @location (@plan), completed @date', [
          '@asset' => $grazing_event->getAsset()->label(),
          '@location' => $grazing_event->getLocation()->label(),
          '@plan' => $grazing_event->getPlan()->label(),
          '@date' => $this->dateFormatter->format((int) $log->get('timestamp')->value, 'short'),
        ]),
        '#url' => $log->toUrl(),
      ];
    }
    $element['completed'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Recently completed'),
      '#items' => $items,
      '#empty' => $this->t('No grazing events have been completed yet.'),
    ];

    return $element;
  }

}
