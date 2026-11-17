<?php

declare(strict_types=1);

namespace Drupal\farm_attachments\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\file\Plugin\Field\FieldFormatter\DescriptionAwareFileFormatterBase;

/**
 * Renders image attachments as thumbnails and other attachments as links.
 */
#[FieldFormatter(
  id: 'farm_attachment',
  label: new TranslatableMarkup('Attachment'),
  field_types: ['file'],
)]
class AttachmentFormatter extends DescriptionAwareFileFormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];

    foreach ($this->getEntitiesToView($items, $langcode) as $delta => $entity) {
      /** @var \Drupal\file\FileInterface $file */
      $file = $entity;
      $cache = [
        'tags' => $file->getCacheTags(),
      ];

      if (str_starts_with((string) $file->getMimeType(), 'image/')) {
        $elements[$delta] = [
          '#theme' => 'image_style',
          '#style_name' => 'thumbnail',
          '#uri' => $file->getFileUri(),
          '#alt' => $file->getFilename(),
          '#title' => $file->getFilename(),
          '#cache' => $cache,
        ];
      }
      else {
        $elements[$delta] = [
          '#theme' => 'file_link',
          '#file' => $file,
          '#cache' => $cache,
        ];
      }
    }

    return $elements;
  }

}
