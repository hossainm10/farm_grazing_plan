<?php

declare(strict_types=1);

namespace Drupal\farm_grazing_plan;

use Drupal\asset\Entity\AssetInterface;
use Drupal\farm_grazing_plan\Bundle\GrazingEventInterface;
use Drupal\plan\Entity\PlanInterface;

/**
 * Writes grazing event log and related observation data on form submit.
 *
 * Integration: inject GrazingEventWriterInterface (service ID
 * farm_grazing_plan.event_writer) from a QuickForm or other form submit
 * handler. Pass submitted values using the keys documented below. Quantity
 * labels/units/defaults are owned by the form UI; this service only persists
 * whatever quantity rows are submitted.
 *
 * Observation value shape for both complete and create:
 * @code
 * $values['observations'] = [
 *   'previous' => [
 *     'photos' => [1, 2], // file IDs from managed_file
 *     'quantities' => [
 *       ['label' => 'Residual left', 'value' => '4', 'units' => 'cm', 'measure' => 'length'],
 *     ],
 *     'notes' => ['value' => '...', 'format' => 'default'],
 *   ],
 *   'next' => [
 *     'photos' => [],
 *     'quantities' => [
 *       ['label' => 'Graze height', 'value' => '12', 'units' => 'cm', 'measure' => 'length'],
 *     ],
 *     'notes' => ['value' => '...', 'format' => 'default'],
 *   ],
 * ];
 * @endcode
 */
interface GrazingEventWriterInterface {

  /**
   * Update an existing grazing event's movement log as complete (Goal 1).
   *
   * Persists: status=done, actual timestamp, notes, geometry on the movement
   * log (not the plan record planned start), plus optional previous/next
   * paddock observation logs with photos and quantities.
   *
   * @param \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface $grazing_event
   *   The pending grazing event.
   * @param array $values
   *   Supported keys:
   *   - timestamp (int): actual date/time
   *   - notes: log notes value
   *   - geometry: WKT/geometry string for the movement log
   *   - observations: previous/next observation payloads (see class docs)
   *   - quick_id (string): optional quick form id for created observations
   *
   * @return bool
   *   TRUE if the log was updated.
   */
  public function completeGrazingEvent(GrazingEventInterface $grazing_event, array $values = []): bool;

  /**
   * Create a new grazing event and append it in plan order (Goal 2).
   *
   * Persists: done movement log, grazing event plan record after the last
   * event for the asset, plus optional previous/next paddock observation
   * logs with photos and quantities.
   *
   * @param \Drupal\plan\Entity\PlanInterface $plan
   *   The grazing plan.
   * @param \Drupal\asset\Entity\AssetInterface $asset
   *   The animal/group asset.
   * @param \Drupal\asset\Entity\AssetInterface $location
   *   The paddock/location asset.
   * @param array $values
   *   Supported keys:
   *   - timestamp (int): actual date/time (must be >= last event start)
   *   - duration_hours (int, required)
   *   - recovery_hours (int|null)
   *   - notes, geometry, observations, quick_id: same as completeGrazingEvent()
   *
   * @return \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface|null
   *   The created grazing event, or NULL on failure.
   */
  public function createGrazingEvent(PlanInterface $plan, AssetInterface $asset, AssetInterface $location, array $values = []): ?GrazingEventInterface;

}
