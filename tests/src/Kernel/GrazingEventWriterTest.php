<?php

declare(strict_types=1);

namespace Drupal\Tests\farm_grazing_plan\Kernel;

use Drupal\farm_grazing_plan\GrazingEventWriterInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests GrazingEventWriter persistence logic.
 */
#[Group('farm_grazing_plan')]
#[RunTestsInSeparateProcesses]
class GrazingEventWriterTest extends GrazingPlanTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'asset',
    'entity',
    'entity_reference_revisions',
    'farm_activity',
    'farm_animal',
    'farm_animal_type',
    'farm_entity',
    'farm_entity_fields',
    'farm_field',
    'farm_format',
    'farm_grazing_plan',
    'farm_id_tag',
    'farm_land',
    'farm_location',
    'farm_log',
    'farm_log_asset',
    'farm_log_quantity',
    'farm_map',
    'farm_observation',
    'farm_quantity_standard',
    'farm_quick',
    'farm_unit',
    'file',
    'filter',
    'fraction',
    'geofield',
    'image',
    'log',
    'options',
    'plan',
    'quantity',
    'state_machine',
    'system',
    'taxonomy',
    'text',
    'user',
  ];

  /**
   * The grazing event writer.
   *
   * @var \Drupal\farm_grazing_plan\GrazingEventWriterInterface
   */
  protected GrazingEventWriterInterface $writer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installEntitySchema('quantity');
    $this->installConfig([
      'farm_format',
      'farm_observation',
      'farm_quantity_standard',
      'farm_unit',
      'system',
    ]);
    $this->writer = $this->container->get('farm_grazing_plan.event_writer');
  }

  /**
   * Test completing a grazing event updates the movement log.
   */
  public function testCompleteGrazingEvent() {
    $this->createMockPlanEntities();

    // use a pending event that has a previous paddock (not the first event).
    $asset = $this->animalAssets[0];
    $events = array_values($this->container->get('farm_grazing_plan')->getGrazingEventsByAsset($this->plan)[$asset->id()]);
    /** @var \Drupal\farm_grazing_plan\Bundle\GrazingEventInterface $pending */
    $pending = end($events);
    $this->assertEquals('pending', $pending->getStatus());
    $previous_location = $events[count($events) - 2]->getLocation();

    $planned_start = (int) $pending->get('start')->value;
    $timestamp = $planned_start + 3600;
    $geometry = 'POINT(1 2)';
    $this->writer->completeGrazingEvent($pending, [
      'timestamp' => $timestamp,
      'notes' => [
        'value' => 'Completed in the field.',
        'format' => 'default',
      ],
      'geometry' => $geometry,
      'observations' => [
        'previous' => [
          'quantities' => [
            [
              'label' => 'Residual left',
              'value' => '4',
              'units' => 'cm',
              'measure' => 'length',
            ],
          ],
        ],
        'next' => [
          'quantities' => [
            [
              'label' => 'Graze height',
              'value' => '12',
              'units' => 'cm',
              'measure' => 'length',
            ],
          ],
        ],
      ],
    ]);

    $log = \Drupal::entityTypeManager()->getStorage('log')->load($pending->getLog()->id());
    $this->assertEquals('done', $log->get('status')->value);
    $this->assertEquals($timestamp, (int) $log->get('timestamp')->value);
    $this->assertStringContainsString('Completed in the field.', $log->get('notes')->value);
    $this->assertEquals($geometry, $log->get('geometry')->value);

    // Planned start on the plan record is unchanged.
    $pending = \Drupal::entityTypeManager()->getStorage('plan_record')->load($pending->id());
    $this->assertEquals($planned_start, (int) $pending->get('start')->value);

    $observations = array_values(\Drupal::entityTypeManager()->getStorage('log')->loadByProperties(['type' => 'observation']));
    $this->assertCount(2, $observations);
    $labels = array_map(fn($observation) => $observation->label(), $observations);
    $this->assertTrue((bool) array_filter($labels, fn($label) => str_contains((string) $label, 'Previous paddock observation')));
    $this->assertTrue((bool) array_filter($labels, fn($label) => str_contains((string) $label, 'Next paddock observation')));

    // Previous observation references the prior paddock.
    $previous_observation = NULL;
    foreach ($observations as $observation) {
      if (str_contains((string) $observation->label(), 'Previous paddock observation')) {
        $previous_observation = $observation;
        break;
      }
    }
    $this->assertNotNull($previous_observation);
    $this->assertEquals($previous_location->id(), $previous_observation->get('location')->target_id);
    $this->assertEquals('4', $previous_observation->get('quantity')->referencedEntities()[0]->get('value')[0]->get('decimal')->getValue());
  }

  /**
   * Test creating a grazing event appends it for the asset in the plan.
   */
  public function testCreateGrazingEvent() {
    $this->createMockPlanEntities();
    $asset = $this->animalAssets[0];
    $events_before = array_values($this->container->get('farm_grazing_plan')->getGrazingEventsByAsset($this->plan)[$asset->id()]);
    $previous_location = end($events_before)->getLocation();
    $last_start = (int) end($events_before)->get('start')->value;
    $timestamp = $last_start + (7 * 24 * 60 * 60);
    $location = $this->landAssets[0];
    $geometry = 'POINT(3 4)';

    $created = $this->writer->createGrazingEvent($this->plan, $asset, $location, [
      'timestamp' => $timestamp,
      'duration_hours' => 3 * 24,
      'recovery_hours' => 10 * 24,
      'notes' => [
        'value' => 'Created live in the field.',
        'format' => 'default',
      ],
      'geometry' => $geometry,
      'observations' => [
        'previous' => [
          'quantities' => [
            [
              'label' => 'Residual left',
              'value' => '3',
              'units' => 'cm',
              'measure' => 'length',
            ],
          ],
        ],
        'next' => [
          'quantities' => [
            [
              'label' => 'Graze height',
              'value' => '8',
              'units' => 'cm',
              'measure' => 'length',
            ],
          ],
        ],
      ],
    ]);

    $this->assertNotNull($created);
    $this->assertEquals($timestamp, (int) $created->get('start')->value);
    $this->assertEquals(3 * 24, (int) $created->get('duration')->value);
    $this->assertEquals(10 * 24, (int) $created->get('recovery')->value);
    $this->assertEquals('done', $created->getStatus());
    $this->assertEquals($location->id(), $created->getLocation()->id());
    $this->assertStringContainsString('Created live in the field.', $created->getLog()->get('notes')->value);
    $this->assertEquals($geometry, $created->getLog()->get('geometry')->value);

    $events_after = array_values($this->container->get('farm_grazing_plan')->getGrazingEventsByAsset($this->plan)[$asset->id()]);
    $this->assertCount(count($events_before) + 1, $events_after);
    $starts = array_map(fn($event) => (int) $event->get('start')->value, $events_after);
    $sorted = $starts;
    sort($sorted);
    $this->assertEquals($sorted, $starts);
    $this->assertSame(end($events_after)->id(), $created->id());

    $observations = array_values(\Drupal::entityTypeManager()->getStorage('log')->loadByProperties(['type' => 'observation']));
    $this->assertCount(2, $observations);
    $previous_observation = NULL;
    foreach ($observations as $observation) {
      if (str_contains((string) $observation->label(), 'Previous paddock observation')) {
        $previous_observation = $observation;
        break;
      }
    }
    $this->assertNotNull($previous_observation);
    $this->assertEquals($previous_location->id(), $previous_observation->get('location')->target_id);
  }

  /**
   * Test create rejects a timestamp before the last event.
   */
  public function testCreateGrazingEventRejectsEarlyStart() {
    $this->createMockPlanEntities();
    $asset = $this->animalAssets[0];
    $events_before = array_values($this->container->get('farm_grazing_plan')->getGrazingEventsByAsset($this->plan)[$asset->id()]);
    $last_start = (int) end($events_before)->get('start')->value;

    $created = $this->writer->createGrazingEvent($this->plan, $asset, $this->landAssets[0], [
      'timestamp' => $last_start - 60,
      'duration_hours' => 24,
    ]);

    $this->assertNull($created);
    $events_after = array_values($this->container->get('farm_grazing_plan')->getGrazingEventsByAsset($this->plan)[$asset->id()] ?? []);
    $this->assertCount(count($events_before), $events_after);
  }

}
