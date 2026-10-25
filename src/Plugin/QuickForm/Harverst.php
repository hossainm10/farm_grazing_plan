<?php

namespace Drupal\farm_quick_harvest\Plugin\QuickForm;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormStateInterference;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\farm_quick\Attribute\QuickForm;
use Drupal\farm_quick\Plugin\QuickForm\QuickFormBase;
use Drupal\farm_quick\Traits\QuickLogTrait;

/**
  * Harvest Quick Form
  */

#[QuickForm(
  id: 'harvest',
  label: new TranslatableMarkup('harvest'),
  description: new TranslatableMarkup('Record when a harvest takes place'),
  helpText: new TranslatableMarkup('Use this form to record a harvest'),
  permissions: [ 'create harvest log']
  )]

class Harvest extends QuickFormBase{

}
