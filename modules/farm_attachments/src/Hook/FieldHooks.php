<?php

declare(strict_types=1);

namespace Drupal\farm_attachments\Hook;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\farm_field\FarmFieldFactoryInterface;

/**
 * Provides attachment fields for farmOS records.
 */
class FieldHooks {

  use StringTranslationTrait;

  public function __construct(
    protected FarmFieldFactoryInterface $farmFieldFactory,
  ) {}

  /**
   * Implements hook_farm_entity_bundle_field_info().
   */
  #[Hook('farm_entity_bundle_field_info')]
  public function farmEntityBundleFieldInfo(EntityTypeInterface $entity_type, string $bundle): array {
    if (!in_array($entity_type->id(), ['asset', 'log'], TRUE)) {
      return [];
    }

    $field = $this->farmFieldFactory->bundleFieldDefinition([
      'type' => 'file',
      'label' => $this->t('Attachments'),
      'description' => $this->t('Upload photos or documents related to this record.'),
      'multiple' => TRUE,
      'file_directory' => 'attachments/[date:custom:Y]-[date:custom:m]',
      'weight' => [
        'form' => 90,
        'view' => 90,
      ],
    ]);
    $field
      ->setSetting('uri_scheme', 'private')
      ->setSetting('max_filesize', '10 MB')
      ->setSetting('file_extensions', 'jpg jpeg png webp pdf docx')
      ->setDisplayOptions('view', [
        'type' => 'farm_attachment',
        'label' => 'above',
        'weight' => 90,
      ]);

    return ['attachments' => $field];
  }

}
