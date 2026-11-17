<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\asset\Entity\AssetInterface;
use Drupal\farm_grazing_plan\Bundle\GrazingEventInterface;
use Drupal\farm_quick\Traits\QuickQuantityTrait;
use Drupal\file\FileInterface;
use Drupal\log\Entity\Log;
use Drupal\plan\Entity\PlanInterface;
use Drupal\plan\Entity\PlanRecord;

/**
 * Persists grazing event logs, plan records, photos, and quantities.
 */
class GrazingEventWriter implements GrazingEventWriterInterface {

  use QuickQuantityTrait;
  use StringTranslationTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected GrazingPlanInterface $grazingPlan,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function completeGrazingEvent(GrazingEventInterface $grazing_event, array $values = []): bool {
    $log = $grazing_event->getLog();
    $timestamp = (int) ($values['timestamp'] ?? time());

    $log->set('status', 'done');
    $log->set('timestamp', $timestamp);
    if (array_key_exists('notes', $values)) {
      $log->set('notes', $values['notes']);
    }
    if (array_key_exists('geometry', $values)) {
      $log->set('geometry', $values['geometry']);
    }
    $log->setNewRevision(TRUE);
    $log->setRevisionLogMessage($this->t('Grazing event completed.')->render());
    $log->save();

    $this->createObservations(
      $grazing_event->getPlan(),
      $grazing_event->getAsset(),
      $grazing_event->getLocation(),
      $timestamp,
      $values['observations'] ?? [],
      $grazing_event,
      $values['quick_id'] ?? NULL,
    );

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function createGrazingEvent(PlanInterface $plan, AssetInterface $asset, AssetInterface $location, array $values = []): ?GrazingEventInterface {
    $timestamp = (int) ($values['timestamp'] ?? time());
    if (!$this->canAppendAt($plan, $asset, $timestamp)) {
      return NULL;
    }

    if (empty($values['duration_hours'])) {
      return NULL;
    }
    $duration_hours = (int) $values['duration_hours'];
    $recovery_hours = isset($values['recovery_hours']) && $values['recovery_hours'] !== '' && $values['recovery_hours'] !== NULL
      ? (int) $values['recovery_hours']
      : NULL;

    $log_values = [
      'type' => 'activity',
      'name' => $this->t('Move @asset to @location', [
        '@asset' => $asset->label(),
        '@location' => $location->label(),
      ]),
      'timestamp' => $timestamp,
      'asset' => [$asset],
      'location' => [$location],
      'status' => 'done',
      'is_movement' => TRUE,
    ];
    if (array_key_exists('notes', $values)) {
      $log_values['notes'] = $values['notes'];
    }
    if (array_key_exists('geometry', $values)) {
      $log_values['geometry'] = $values['geometry'];
    }

    $log = Log::create($log_values);
    if (!empty($values['quick_id'])) {
      $log->get('quick')->appendItem($values['quick_id']);
    }
    $log->setNewRevision(TRUE);
    $log->setRevisionLogMessage($this->t('Grazing event created.')->render());
    $log->save();

    /** @var \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface $grazing_event */
    $grazing_event = PlanRecord::create([
      'type' => 'grazing_event',
      'plan' => $plan->id(),
      'log' => $log->id(),
      'start' => $timestamp,
      'duration' => $duration_hours,
      'recovery' => $recovery_hours,
    ]);
    $grazing_event->save();

    $this->createObservations(
      $plan,
      $asset,
      $location,
      $timestamp,
      $values['observations'] ?? [],
      $grazing_event,
      $values['quick_id'] ?? NULL,
    );

    return $grazing_event;
  }

  /**
   * Whether a new event can be appended at the given timestamp.
   */
  protected function canAppendAt(PlanInterface $plan, AssetInterface $asset, int $timestamp): bool {
    $events = $this->grazingPlan->getGrazingEventsByAsset($plan)[$asset->id()] ?? [];
    if (empty($events)) {
      return TRUE;
    }
    /** @var \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface $last_event */
    $last_event = end($events);
    return $timestamp >= (int) $last_event->get('start')->value;
  }

  /**
   * Create previous/next paddock observation logs when values are provided.
   */
  protected function createObservations(PlanInterface $plan, AssetInterface $asset, AssetInterface $next_paddock, int $timestamp, array $observations, ?GrazingEventInterface $grazing_event = NULL, ?string $quick_id = NULL): void {
    $paddocks = $this->getAdjacentPaddocks($plan, $asset, $next_paddock, $grazing_event);
    foreach (['previous', 'next'] as $key) {
      $paddock = $paddocks[$key] ?? NULL;
      if (!$paddock || empty($observations[$key])) {
        continue;
      }
      $this->createPaddockObservationLog($paddock, $asset, $timestamp, $observations[$key], $key, $quick_id);
    }
  }

  /**
   * Resolve previous and next paddock assets.
   */
  protected function getAdjacentPaddocks(PlanInterface $plan, AssetInterface $asset, AssetInterface $next_paddock, ?GrazingEventInterface $grazing_event = NULL): array {
    $previous = NULL;
    $events = array_values($this->grazingPlan->getGrazingEventsByAsset($plan)[$asset->id()] ?? []);

    if ($grazing_event) {
      foreach ($events as $index => $event) {
        if ((int) $event->id() === (int) $grazing_event->id()) {
          if ($index > 0) {
            $previous = $events[$index - 1]->getLocation();
          }
          break;
        }
      }
    }
    elseif (!empty($events)) {
      $previous = end($events)->getLocation();
    }

    return [
      'previous' => $previous,
      'next' => $next_paddock,
    ];
  }

  /**
   * Create an observation log for a paddock.
   */
  protected function createPaddockObservationLog(AssetInterface $paddock, AssetInterface $animal_asset, int $timestamp, array $values, string $key, ?string $quick_id = NULL): void {
    $quantity_entities = [];
    foreach ($values['quantities'] ?? [] as $quantity) {
      if ($quantity['value'] === '' || $quantity['value'] === NULL) {
        continue;
      }
      $quantity_entities[] = $this->createQuantity([
        'type' => 'standard',
        'label' => $quantity['label'] ?? '',
        'measure' => $quantity['measure'] ?? 'length',
        'value' => $quantity['value'],
        'units' => !empty($quantity['units']) ? $quantity['units'] : 'cm',
      ], 'observation');
    }

    $images = $this->prepareUploadedImages($values['photos'] ?? []);
    $notes = $values['notes'] ?? NULL;
    $has_notes = is_array($notes) ? !empty($notes['value']) : !empty($notes);
    if (empty($quantity_entities) && empty($images) && !$has_notes) {
      return;
    }

    $name = $key === 'previous'
      ? $this->t('Previous paddock observation: @paddock', ['@paddock' => Markup::create($paddock->label())])
      : $this->t('Next paddock observation: @paddock', ['@paddock' => Markup::create($paddock->label())]);

    $log = Log::create([
      'type' => 'observation',
      'name' => $name,
      'timestamp' => $timestamp,
      'asset' => [$paddock, $animal_asset],
      'location' => [$paddock],
      'status' => 'done',
      'quantity' => $quantity_entities,
      'image' => $images,
    ]);
    if ($has_notes) {
      $log->set('notes', $notes);
    }
    if (!empty($quick_id) && $log->hasField('quick')) {
      $log->get('quick')->appendItem($quick_id);
    }
    $log->save();
  }

  /**
   * Make uploaded image files permanent and return file IDs.
   */
  protected function prepareUploadedImages(array $fids): array {
    $images = [];
    foreach ($fids as $fid) {
      $file = $this->entityTypeManager->getStorage('file')->load($fid);
      if (!$file instanceof FileInterface) {
        continue;
      }
      $file->setPermanent();
      $file->save();
      $images[] = (int) $file->id();
    }
    return $images;
  }

}
