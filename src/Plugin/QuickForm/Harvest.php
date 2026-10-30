<?php

namespace Drupal\farm_grazing_plan\Plugin\QuickForm;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormStateInterface;
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
  use QuickLogTrait;
  /**
    * {@inheritdoc}
    */
  public function buildForm(array $form, FormStateInterface $form_state){
    // Date + time selection field (defaults to now)
    $form['timestamp'] =[
      '#type' => 'datetime',
      '#title' => $this->t('Date'),
      '#default_value' => new DrupalDateTime('now', $this->currentUser-> getTimeZone()),
      '#required' => TRUE,
    ];

    // Asset reference field (allow multiple)
    $form['asset'] = [
      '#type'=> 'entity_autocomplete',
      '#title'=> $this->t('Assets'),
      "#target_type" => 'asset',
      '#tags' => TRUE,
    ];

    // Harvest quantity field.
    $form['quantity'] =[
      '#type' => 'number',
      '#title' => $this->t('Quantity'),
      '#required'=>TRUE,
    ];
    return $form;
  }
  /**
    * {@inheritdoc}
    */
  public function submitForm(array &$form, FormStateInterface $form_state){
    // Draft a harvest log from the user-submitted data.

    $timestamp = $form_state->getValue('timestamp')->getTimestamp();
    $asset= $form_state->getValue('asset');
    $quantity = $form_state->getValue('quantity');
    $log= [
      'type' => 'harvest',
      'timestamp' => $timestamp,
      'asset' => $asset,
      'quantity' => [
        [
          'type' => 'standard',
          'value' => $quantity,
        ],
      ],
      'status' => 'done',
    ];
    // Create the log.
    $this.createLog($log);
  }
}
