<?php

namespace Drupal\pgt_webform\Plugin\WebformHandler;

use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Form submission handler.
 *
 * @WebformHandler(
 *   id = "pgt_file_number_handler",
 *   label = @Translation("PGT file number handler"),
 *   category = @Translation("PGT Webform Handler"),
 *   description = @Translation("Updates field_active_file_number when user submit form"),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_SINGLE,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 * )
 */
class PGTFileNumberHandler extends WebformHandlerBase {

  /**
   * {@inheritdoc}
   */
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $values = $webform_submission->getData();

    if (isset($values['file_number'])) {
      $uid = \Drupal::currentUser()->id();
      $user = User::load($uid);

      $user->set('field_active_file_number', $values['file_number']);
      $user->save();

      // Redirect user to portal landing page.
      $config = \Drupal::config('pgt_portal_admin_settings.settings');
      $portalLandingPageURL = $config->get('portal_landing_page');
      $url = Url::fromUri('internal:' . $portalLandingPageURL);
      $response = new RedirectResponse($url->toString());
      \Drupal::service('http_middleware.pgt_core_redirect')->setRedirectResponse($response);
    }
  }

}
