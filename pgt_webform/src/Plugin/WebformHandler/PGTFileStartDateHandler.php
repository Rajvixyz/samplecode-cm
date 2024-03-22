<?php

namespace Drupal\pgt_webform\Plugin\WebformHandler;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormStateInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\user\Entity\User;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;

/**
 * @WebformHandler (
 *   id = "pgt_file_start_date_handler",
 *   label = @Translation("PGT file start date handler"),
 *   category = @Translation("PGT Webform Handler"),
 *   description = @Translation("Updates field_start_date on field_client_information for user who submits form."),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class PGTFileStartDateHandler extends WebformHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state, WebformSubmissionInterface $webform_submission) {
    $uid = \Drupal::currentUser()->id();
    $user = User::load($uid);

    $field_client_information = $user->get('field_client_information')->getValue();
    $field_active_file_number = $user->get('field_active_file_number')->getValue();

    //$timezone = new \DateTimeZone('America/Edmonton');
    $current_date_time = DrupalDateTime::createFromTimestamp(time(), 'UTC')->format('Y-m-d\TH:i:s');

    foreach ($field_client_information as $item) {
      // If the paragraph's file number equals the user's active field number, set start date on paragraph
      $paragraph = Paragraph::load($item['target_id']);
      $field_file_number = $paragraph->get('field_file_number')->getValue();

      if ($field_active_file_number[0]['value'] == $field_file_number[0]['value']) {
        $paragraph->set('field_file_start_date', $current_date_time);
        $paragraph->save();
      }
    }
  }

}
