<?php

namespace Drupal\webform_export\Plugin\WebformHandler;

use Drupal\webform\Plugin\WebformHandlerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\user\Entity\User;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\webform\WebformSubmissionInterface;
use Google\Cloud\Storage\StorageClient;
use Google\Cloud\Core\Exception\GoogleException;
use Jurosh\PDFMerge\PDFMerger;
use Drupal\Core\Site\Settings;

/**
 * Export Webform as CSV and PDF file with uploaded documents
 *
 * @WebformHandler(
 *   id = "webform_export",
 *   label = @Translation("Export Webform as CSV and PDF file with uploaded documents"),
 *   category = @Translation("Webform export"),
 *   description = @Translation("Export Webform as CSV and PDF file with uploaded documents"),
 *   cardinality = \Drupal\webform\Plugin\WebformHandlerInterface::CARDINALITY_UNLIMITED,
 *   results = \Drupal\webform\Plugin\WebformHandlerInterface::RESULTS_PROCESSED,
 *   submission = \Drupal\webform\Plugin\WebformHandlerInterface::SUBMISSION_REQUIRED,
 * )
 */
class WebformExport extends WebformHandlerBase {

  /**
   * {@inheritdoc}
   */

  public function defaultConfiguration() {
    return [
      'usecase' => '',
    ];
  }

  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form['usecase'] = [
      '#type' => 'select',
      '#title' => $this->t('Use case name'),
      '#description' => $this->t('Use case name for file name convention'),
      '#default_value' => $this->configuration['usecase'],
      '#empty_option' => $this->t('-- Select one option --'),
      '#options' => [
        "aisref" => 'Assessment and Investigation Services Referral',
        "cfareferral" => 'CFA Referral',
        "eptsreferral" => 'EPTS Referral',
        "packageform" => 'Private Committee Account Submission',
        "legalsubmission" => 'Legal Submission',
        "discquest" => 'Private Committee Discretionary Reporting Questionnaire',
      ],
      '#required' => TRUE,
    ];
    return $this->setSettingsParents($form);
  }

  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->applyFormStateToConfiguration($form_state);
  }

  // Function to be fired after submitting the Webform.
  public function postSave(WebformSubmissionInterface $webform_submission, $update = TRUE) {
    $status = '';
    $get_form = $webform_submission->getWebform();
    $webform_id = $get_form->id();
    $elements = $get_form->getElementsDecodedAndFlattened();
    $webform = Webform::load($webform_id);
    $handler = $webform->getHandler('export_webform_as_csv_and_pdf_file_with_uploaded_documents');
    $config = $handler->getConfiguration();
    $usecase = $config['settings']['usecase'];
    $user_id = $webform_submission->getOwnerId();

    if ($usecase == "packageform") {
      $user = User::load($user_id);

      $firstname = $user->field_first_name->value;
      $lastname = $user->field_last_name->value;

      $portal_forms = ['financial_details', 'attach_documents', 'authorization_to_request_informa', 'income_and_expenses', 'update_committee_or_adult_person'];

      $query = \Drupal::entityQuery('webform_submission')
        ->condition('uid', $user_id, '=')
        ->condition('webform_id', $portal_forms, 'IN');

      $results = $query->execute();
      $submissions = WebformSubmission::loadMultiple($results);

      $pdfs_to_merge = [];
      $destination = DRUPAL_ROOT . '/sites/default/private/tmp/';
      $usecase = 'packageform';

      // We hould have all the submissions for this user now - generate a PDF, then combine and serve the result.
      foreach ($submissions as $submission) {

        $pdf_file = pgt_core_export_submission($submission, $usecase, $user, $destination, 'pdf');
        if ($pdf_file !== NULL) {
          $pdfs_to_merge[] = $pdf_file;
        }

        // Could also generate a csv with the following arguments:
        //$csv_file = pgt_core_export_submission($submission, $usecase, $user, $destination, 'csv');
      }

      if (count($pdfs_to_merge) > 0) {
        $pdf = new PDFMerger();

        foreach ($pdfs_to_merge as $pdf_to_merge) {
          $pdf->addPDF($pdf_to_merge, 'all', 'vertical');
        }
        $firstname = $user->field_first_name->value;
        $lastname = $user->field_last_name->value;
        $date = date('Ymd');
        $time = date('His');

        $path = $usecase . "/" . $date;

        $pdf->merge('file', $destination . $path . '/' . $firstname[0] . $lastname . '_' . $time . '.pdf');
        // Need to delete the temporary pdfs after we have a merged one.
        foreach ($pdfs_to_merge as $pdf_to_merge) {
          unlink($pdf_to_merge);
        }

        $package = fopen($destination . $path . '/' . $firstname[0] . $lastname . '_' . $time . '.pdf', 'r');

        $config = [
          'keyFile' => json_decode(file_get_contents(Settings::get('PGT_GSP_PATH')), TRUE),
          'projectId' => "pgt-production-website",
        ];

        try {
          $storage = new StorageClient($config);
          $storage->bucket("received-from-yellow-pencil")->upload($package, [
            'name' => $path . '/' . $firstname[0] . $lastname . '_' . $time . '.pdf'
          ]);
        }
        catch (GoogleException $y) {
          \Drupal::logger('webform_export')->notice(t($y->getMessage()));
          //dpm($y->getMessage());
          $status = 'error';
        }
      }

    $counter = 1;

    foreach ($submissions as $submission) {
      $webform = $submission->getWebform();
      $elements_managed_files = $webform->getElementsManagedFiles();
      if ($elements_managed_files) {
        $fids = [];
        foreach ($elements_managed_files as $k => $element_name) {
          $file_ids = $webform_submission->getElementData($element_name);
          if (!empty($file_ids)) {
            if (!is_array($file_ids) && is_numeric($file_ids)) {
              $file_ids = [$file_ids];
            }
            $fids = array_merge($fids, $file_ids);
            $file_entities = File::loadMultiple($fids);
            foreach ($file_entities as $file_entity) {
              $files = $file_entity->getFileUri();
              $data = file_get_contents($files);
              $orig_file_name = $file_entity->getFileName();
              $extension = pathinfo($orig_file_name, PATHINFO_EXTENSION);
              $file_name = $firstname[0] . $lastname . '_' . 'attach' . $counter . '_' . $time . '.' . $extension;
              $source = \Drupal::service("file_system")->saveData($data, $destination . $path . '/' . $file_name, FileSystemInterface::EXISTS_REPLACE);
              // Upload file into PGT google cloud platform
              // Explicitly use service account credentials by specifying the private key file.
              $config = [
                'keyFile' => json_decode(file_get_contents(Settings::get('PGT_GSP_PATH')), TRUE),
                'projectId' => "pgt-production-website",
              ];
              try {
                $storage = new StorageClient($config);
                $file = fopen($source, 'r');
                $storage->bucket("received-from-yellow-pencil")->upload($file, [
                  'name' => $path . '/' . $firstname[0] . $lastname . '_' . 'attach' . $counter . '_' . $time . '.' . $extension
                ]);
              }
              catch (GoogleException $z) {
                \Drupal::logger('webform_export')->notice(t($z->getMessage()));
                //dpm($z->getMessage());
                $status = 'error';
              }
              $counter++;
              unlink($source);
            }
          }
        }
      }

      // Could also generate a csv with the following arguments:
      //$csv_file = pgt_core_export_submission($submission, $usecase, $user, $destination, 'csv');
    }
    }
      else {
    $firstname = $_POST['first_name'];
    $lastname = $_POST['surname'];

    $date = date('Ymd');
    $time = date('His');
    $destination = DRUPAL_ROOT . '/sites/default/private/tmp/';
    if ($usecase == "legalsubmission") {
      $session = \Drupal::request()->getSession();
      $target = $session->get('target', []);
      switch ($target) {
        case "Personal Trust Services":
          $usecase = "estateslegalreview";
          break;

        case "Services to AdultsChild":
          $usecase = "adultslegalreview";
          break;

        case "Youth Services, Estate":
          $usecase = "cyslegalreview";
          break;

      }
    }

    $path = $usecase . "/" . $date;
    if (!is_dir($destination . $path)) {
      mkdir($destination . $path, 0755, TRUE);
    }
    $submission_exporter = \Drupal::service('webform_submission.exporter');
    $export_options = $submission_exporter->getDefaultExportOptions();
    $export_options['range_type'] = 'latest';
    $export_options['range_latest'] = 1;
    $export_options['excluded_columns'] = [
      'uid' => 'uid',
      'uuid' => 'uuid',
      'token' => 'token',
      'webform_id' => 'webform_id',
      'completed' => 'completed',
      'serial' => 'serial',
      'changed' => 'changed',
      'uri' => 'uri',
      'current_page' => 'current_page',
      'remote_addr' => 'remote_addr',
      'uid' => 'uid',
      'langcode' => 'langcode',
      'entity_type' => 'entity_type',
      'entity_id' => 'entity_id',
      'locked' => 'locked',
      'in_draft' => 'in_draft',
      'sticky' => 'sticky',
      'notes' => 'notes',
    ];
    // export webform submissions as CSV
    $submission_exporter->setWebform($webform);
    $submission_exporter->setExporter($export_options);
    $submission_exporter->generate();
    $filename = $submission_exporter->getExportFilePath();
    $renamed_file = $destination . $path . '/' . $firstname[0] . $lastname . '_' . $time . '.csv';
    rename($filename, $renamed_file);
    // move headers to the first column
    $csv = array_map('str_getcsv', file($renamed_file));
    $headers = $csv[0];
    unset($csv[0]);
    foreach ($csv as $row) {
        $newRow = [];
        foreach ($headers as $key => $value) {
          $newRow[] = $value . ',' . $row[$key] . PHP_EOL;
        }
        file_put_contents($renamed_file, $newRow);
    }
    // export webform submissions as PDF
    $export_options['exporter'] = 'webform_entity_print:pdf';
    $submission_exporter->setWebform($webform);
    $submission_exporter->setExporter($export_options);
    $submission_exporter->generate();
    $filepath = $submission_exporter->getArchiveFilePath();
    $archive = new \Archive_Tar($filepath, 'gz');
    $tmp_pdf = $archive->extract($destination . $path . '/');
    $pdf_file = $archive->listContent();
    $pdf_name = end($pdf_file)['filename'];
    rename($destination . $path . '/' . $pdf_name, $destination . $path . '/' . $firstname[0] . $lastname . '_' . $time . '.pdf');
    $renamed_pdf = $destination . $path . '/' . $firstname[0] . $lastname . '_' . $time . '.pdf';

    // Upload exported form CSV and PDF into PGT google cloud platform
    // Explicitly use service account credentials by specifying the private key file.
    $config = [
      'keyFile' => json_decode(file_get_contents(Settings::get('PGT_GSP_PATH')), TRUE),
      'projectId' => "pgt-production-website",
    ];
    try {
      $storage = new StorageClient($config);
      $exported_file = fopen($renamed_file, 'r');
      $storage->bucket("received-from-yellow-pencil")->upload($exported_file, [
        'name' => $path . '/' . $firstname[0] . $lastname . '_' . $time . '.csv'
      ]);
    }
    catch (GoogleException $x) {
      \Drupal::logger('webform_export')->notice(t($x->getMessage()));
      //dpm($x->getMessage());
      $status = 'error';
    }
    try {
      $storage = new StorageClient($config);
      $exported_pdf = fopen($renamed_pdf, 'r');
      $storage->bucket("received-from-yellow-pencil")->upload($exported_pdf, [
        'name' => $path . '/' . $firstname[0] . $lastname . '_' . $time . '.pdf'
      ]);
    }
    catch (GoogleException $y) {
      \Drupal::logger('webform_export')->notice(t($y->getMessage()));
      //dpm($y->getMessage());
      $status = 'error';
    }
    unlink($filepath);
    unlink($renamed_file);
    unlink($renamed_pdf);
    foreach ($pdf_file as $key => $value) {
      if ($pdf_file[$key] !== end($pdf_file)) {
        unlink($destination . $path . '/' . $pdf_file[$key]['filename']);
      }
    }
    // get uploaded files and copy them into tmp directory
    $elements_managed_files = $webform->getElementsManagedFiles();
    if ($elements_managed_files) {
      $fids = [];
      foreach ($elements_managed_files as $k => $element_name) {
        $file_ids = $webform_submission->getElementData($element_name);
        if (!empty($file_ids)) {
          if (!is_array($file_ids) && is_numeric($file_ids)) {
            $file_ids = [$file_ids];
          }
          $fids = array_merge($fids, $file_ids);
          $file_entities = File::loadMultiple($fids);
          $index = 1;
          foreach ($file_entities as $file_entity) {
            $files = $file_entity->getFileUri();
            $data = file_get_contents($files);
            $orig_file_name = $file_entity->getFileName();
            $extension = pathinfo($orig_file_name, PATHINFO_EXTENSION);
            $file_name = $firstname[0] . $lastname . '_' . 'attach' . $index . '_' . $time . '.' . $extension;
            $source = \Drupal::service("file_system")->saveData($data, $destination . $path . '/' . $file_name, FileSystemInterface::EXISTS_REPLACE);
            // Upload file into PGT google cloud platform
            // Explicitly use service account credentials by specifying the private key file.
            $config = [
              'keyFile' => json_decode(file_get_contents(Settings::get('PGT_GSP_PATH')), TRUE),
              'projectId' => "pgt-production-website",
            ];
            try {
              $storage = new StorageClient($config);
              $file = fopen($source, 'r');
              $storage->bucket("received-from-yellow-pencil")->upload($file, [
                'name' => $path . '/' . $firstname[0] . $lastname . '_' . 'attach' . $index . '_' . $time . '.' . $extension
              ]);
            }
            catch (GoogleException $z) {
              \Drupal::logger('webform_export')->notice(t($z->getMessage()));
              //dpm($z->getMessage());
              $status = 'error';
            }
            $index++;
            unlink($source);
          }
        }
      }
    }
    if ($status !== 'error') {
      $webform = Webform::load($webform_id);
      if ($webform->hasSubmissions()) {
        $query = \Drupal::entityQuery('webform_submission')
          ->condition('webform_id', $webform_id);
        $query->accessCheck(FALSE)->execute();
        $result = $query->execute();
        foreach ($result as $item) {
           $submission = WebformSubmission::load($item);
           $submission->delete();
        }
      }
    }
      }
  }

}
