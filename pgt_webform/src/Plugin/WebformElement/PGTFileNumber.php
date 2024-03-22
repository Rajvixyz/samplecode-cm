<?php

namespace Drupal\pgt_webform\Plugin\WebformElement;

use Drupal\paragraphs\Entity\Paragraph;
use Drupal\user\Entity\User;
use Drupal\webform\Plugin\WebformElementBase;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Provides a 'pgt_file_number' element.
 *
 * @WebformElement(
 *   id = "pgt_file_number",
 *   label = @Translation("PGT File Number element"),
 *   description = @Translation("Provides a webform element for a user appicable file number."),
 *   category = @Translation("PGT Webform Element"),
 * )
 *
 */
class PGTFileNumber extends WebformElementBase {

  /**
   * {@inheritdoc}
   */
  protected function defineDefaultProperties() {
    return [
      'options' => [],
    ] + parent::defineDefaultProperties();
  }

  /**
   * {@inheritdoc}
   */
  public function prepare(array &$element, WebformSubmissionInterface $webform_submission = NULL) {
    $uid = \Drupal::currentUser()->id();
    $user = User::load($uid);

    $field_client_information = $user->get('field_client_information')->getValue();
    $options = [];

    foreach ($field_client_information as $item) {
      $paragraph = Paragraph::load($item['target_id']);
      $field_file_number = $paragraph->get('field_file_number')->getValue();
      if ($field_file_number) {
        $options[$field_file_number[0]['value']] = $field_file_number[0]['value'];
      }
    }

    $element['#type'] = 'select';
    $element['#options'] = $options;
    //$element['#required'] = TRUE;

    parent::prepare($element, $webform_submission);
  }

}
