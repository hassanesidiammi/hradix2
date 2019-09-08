<?php

namespace Drupal\clothes\Form;

use Drupal\Core\File\FileSystem;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\taxonomy\Entity\Term;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Yaml\Yaml;

/**
 * Implements a simple form.
 */
class TaxonomiesForm extends FormBase {
  protected $exportDir;
  protected $imagesDir;
  protected $fileExtension;
  protected $buttonsValue;
  protected $imageSettings;
  protected $configurationYML;

  public function __construct() {
    $this->exportDir = DRUPAL_ROOT.'/'.drupal_get_path('module', 'clothes').'/export/config/';
    $this->imagesDir = DRUPAL_ROOT.'/'.drupal_get_path('module', 'clothes').'/export/images/';
    $this->fileExtension = '.terms.yml';
    $this->configurationYML = '';
    $this->buttonsValue = [
      'import' => $this->t('Import from Yaml file'),
      'export' => $this->t('Export to Yaml file'),
      'write' => $this->t('Write configuration in Yaml file'),
    ];
  }

  /**
   * Build the simple form.
   *
   * @param array $form
   *   Default form array structure.
   * @param FormStateInterface $form_state
   *   Object containing current form state.
   *
   * @param null $hash
   *
   * @return array
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function buildForm(array $form, FormStateInterface $form_state, $hash=NULL) {
    /** @var \Drupal\taxonomy\VocabularyStorage */
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary');
    $vocabularies = $storage->loadMultiple();
    if (!array_key_exists($hash, $vocabularies)) {
      throw new NotFoundHttpException();
    }
    $form_state->set('hash', $hash);

    $configurationString = '';
    if (file_exists($this->exportDir.$hash.$this->fileExtension)) {
      $configurationString = file_get_contents($this->exportDir.$hash.$this->fileExtension);
    }

    return [
      'configuration' => [
        '#type' => 'textarea',
        '#title' => $this->t('YML configuration'),
        '#description' => $this->t('Taxonomies configuration in "YML" format.'),
        '#value' => $configurationString,
        '#required' => FALSE,
      ],
      'actions' => [
        '#type' => 'actions',
        'import' => [
          '#type' => 'submit',
          '#name' => 'import',
          '#value' => $this->buttonsValue['import'],
          '#submit' => [[$this, 'importForm']],
        ],
        'export' => [
          '#type' => 'submit',
          '#name' => 'export',
          '#value' => $this->buttonsValue['export'],
          '#submit' => [[$this, 'exportForm']],
        ],
        'write' => [
          '#type' => 'submit',
          '#name' => 'write',
          '#value' => $this->buttonsValue['write'],
          '#submit' => [[$this, 'writeForm']],
        ],
      ]
    ];
  }

  /**
   * Getter method for Form ID.
   *
   * The form ID is used in implementations of hook_form_alter() to allow other
   * modules to alter the render array built by this form controller.  it must
   * be unique site wide. It normally starts with the providing module's name.
   *
   * @return string
   *   The unique ID of the form defined by this class.
   */
  public function getFormId() {
    return 'clothes_taxonomies_form';
  }

  /**
   * Implements form validation.
   *
   * The validateForm method is the default method called to validate input on
   * a form.
   *
   * @param array $form
   *   The render array of the currently built form.
   * @param FormStateInterface $form_state
   *   Object describing the current state of the form.
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\Core\Form\FormState $form_state */
    $inputs = $form_state->getUserInput();
    if (array_key_exists('import', $form_state->getUserInput())) {
      try {
        $this->configurationYML = Yaml::parseFile($this->exportDir.$form_state->get('hash').$this->fileExtension);
      } catch (\Exception $exception) {
        $form_state->setErrorByName('configuration', $this->t($exception->getMessage()));
      }
    }
    if (array_key_exists('write', $inputs)) {
      $this->configurationYML = $inputs['configuration'];
      try {
        $this->configurationYML = Yaml::parse($this->configurationYML);
      } catch (\Exception $exception) {
        $form_state->setErrorByName('configuration', $this->t($exception->getMessage()));
      }
    }
  }

  /**
   * Implements a form submit handler.
   *
   * The submitForm method is the default method called for any submit elements.
   *
   * @param array $form
   *   The render array of the currently built form.
   * @param FormStateInterface $form_state
   *   Object describing the current state of the form.
   */
  public function importForm(array &$form, FormStateInterface $form_state) {
    $this->clothesImportTerms($form_state->get('hash'), $this->configurationYML);
    \Drupal::messenger()->addMessage($this->t('Taxonomy terms imported successfully!'));
  }

  /**
   * Implements a form submit handler.
   *
   * @param array $form
   *   The render array of the currently built form.
   * @param FormStateInterface $form_state
   *   Object describing the current state of the form.
   */
  public function exportForm(array &$form, FormStateInterface $form_state) {
    $this->clothesExportTaxonomiesTerms($form_state->get('hash'));
    \Drupal::messenger()->addMessage($this->t('Taxonomy terms exported successfully!'));
  }

  /**
   * Implements a form submit handler.
   *
   *
   * @param array $form
   *   The render array of the currently built form.
   * @param FormStateInterface $form_state
   *   Object describing the current state of the form.
   */
  public function writeForm(array &$form, FormStateInterface $form_state) {
    $this->writeConfig($form_state->get('hash'), $this->configurationYML);
    \Drupal::messenger()->addMessage($this->t('Taxonomy terms written successfully!'));
  }

  /**
   * Implements a form submit handler.
   *
   * The submitForm method is the default method called for any submit elements.
   *
   * @param array $form
   *   The render array of the currently built form.
   * @param FormStateInterface $form_state
   *   Object describing the current state of the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {}

  protected function clothesExportTaxonomiesTerms($vocabularyName) {
    /** @var \Drupal\taxonomy\TermStorage $storage */
    $storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $terms = $storage->loadTree($vocabularyName, 0, null, true);
    $taxonomiesTerms = $targetIds = $parents = [];

    $nameTranslations = [];
    $nameAlias = [];
    $nameImages = [];
    $imagesField = [];

    foreach ($terms as $term) {
      $tid = $term->tid->getValue()[0]['value'];
      $name = $term->getName();

      $alias = $term->path->getValue()[0]['alias'] ?? FALSE;
      if ($alias) {
        $name = explode('/', $alias);
        $name = end($name);

      }
      $parent = $term->parents[0];
      $translations = $term->getTranslationLanguages();
      $targetIds[$tid] = $name;
      foreach ($term->getTranslationLanguages() as $langCode => $language) {
        $translations[$langCode] = $term->getTranslation($langCode)->getName();
      }

      $nameTranslations[$name] = $translations;
      $nameAlias[$name] = $alias;
      $nameImages[$name] = $this->getImageUri($term, $imagesField[$name]);
      if ($parent) {
        $parents[$tid] = $parent;
        $path = [];
        $parentId = $tid;
        do {
          array_unshift($path, 'children');
          array_unshift($path, $targetIds[$parentId]);
          $parentId = $parents[$parentId];
        } while (is_array($parents)  && @key_exists($parentId, $parents));

        $tmp = &$taxonomiesTerms;
        foreach ($path as $p){
          if (!is_array($tmp) || !array_key_exists($p, $tmp)) {
            $tmp[$p] = FALSE;
          }
          $tmp = &$tmp[$p];
        }
        unset($tmp);
      } else {
        $taxonomiesTerms[$name] = [];
        $parents[$tid] = FALSE;
      }
    }

    $taxonomiesTerms = $this->formatTree($taxonomiesTerms, $nameTranslations, $nameAlias, $nameImages);
    $taxonomiesTerms = [
      'name' =>$vocabularyName,
      'image_settings' => $this->imageSettings,
      'children' =>$taxonomiesTerms,
    ];

    $this->writeConfig($vocabularyName, $taxonomiesTerms);
    $this->exportImages($vocabularyName, $nameImages);
  }

  /**
   * @param array $taxonomiesTerms
   */
  protected function writeConfig($vocabularyName, $taxonomiesTerms) {
    @mkdir($this->exportDir);
    file_put_contents(
      $this->exportDir.$vocabularyName.$this->fileExtension,
      Yaml::dump($taxonomiesTerms, 8)
    );
  }

  /**
   * Save existing image files in the "export" directory.
   *
   * @param $vocabularyName
   * @param $images
   *
   * @return bool
   */
  protected function exportImages($vocabularyName, $images) {
    $images = array_filter($images);
    if (empty($images)) {
      return FALSE;
    }

    /** @var FileSystem $fileSystem */
    $fileSystem = \Drupal::service('file_system');
    foreach ($images as $imageField) {
      @$fileSystem->mkdir($this->imagesDir.$vocabularyName, NULL, TRUE);
      $fileSystem->copy(
        $fileSystem->realpath($imageField['uri']),
        $this->imagesDir.$vocabularyName.'/'.$imageField['file_name'],
        FileSystem::EXISTS_REPLACE
      );
    }

    return TRUE;
  }

  protected function clothesImportAllTerms() {
    $flags = \FilesystemIterator::UNIX_PATHS;
    $flags |= \FilesystemIterator::SKIP_DOTS;
    $flags |= \FilesystemIterator::FOLLOW_SYMLINKS;

    foreach (new \RecursiveDirectoryIterator($this->exportDir, $flags) as $splFileInfo) {
      /** @var \SplFileInfo $splFileInfo */
      $filename = $splFileInfo->getFilename();
      if(strpos($filename, $this->fileExtension)){
        $vocabularyName = substr(
          $filename,
          0,
          strrpos($filename, $this->fileExtension)
        );
        clothesImportTerms($vocabularyName, $splFileInfo->getPath());
      }
    }
  }
  protected function clothesImportTerms($vocabularyName, $terms) {
    if (empty($terms['children'])) {
      return FALSE;
    }

    $this->imageSettings = $terms['image_settings'];
    foreach ($terms['children'] as $term) {
      $this->clothesCreateTerm(
        $term,
        $vocabularyName,
        0,
        \Drupal::service('file_system')
      );
    }
  }

  /**
   * @param $item
   * @param $vid
   * @param int $parent
   * @param $destDir
   * @param $fieldName
   * @param \Drupal\Core\File\FileSystem $fileSystem
   */
  protected function clothesCreateTerm ($item, $vid, $parent=0,FileSystem $fileSystem) {
    $term = [
      'vid' => $vid,
      'name' => $item['translations']['en'],
    ];

    if (!empty($item['alias'])){
      $term['path'] = [
        'alias' => $item['alias'],
      ];
    }
    $term = Term::create($term);
    if ($parent) {
      $term->parent = ['target_id' => $parent];
    }
    unset($item['translations']['en']);
    foreach ($item['translations'] as $langCode => $translation) {
      $term->addTranslation($langCode, [
        'name' => $translation
      ]);
    }
    if (!empty($image = $item['image'])){
      $imageName='';
      if (is_array($image)){
        $imageName = $image['file_name'];
      } else {
        $imageName = $image;
      }
      $image = $this->imagesDir.$vid.'/'.$imageName;
      if (file_exists($image)){
        $imageDest = $fileSystem->copy($image, 'public://'.$this->imageSettings['default_image_directory'], FileSystem::EXISTS_REPLACE);
        $this->setImage($term, $imageDest);
      }
    }
    $term->save();

    if (empty($item['children'])){
      return;
    }

    foreach ($item['children'] as $child ) {
      $this->clothesCreateTerm($child, $vid, $term->tid->getValue()[0]['value'],$fileSystem);
    }
  }

  function formatTree ($taxonomiesTerms, $nameTranslations, $nameAlias, $images) {
    $formatted = $children = [];
    foreach ($taxonomiesTerms as $key => $item) {
      if ('children' == $key) continue;
      if(!empty($item['children']) && is_array($item['children']) ) {
        $children = $this->formatTree($item['children'], $nameTranslations, $nameAlias, $images);
      }
      $formatted[] = [
        'name' => $key,
        'alias' => $nameAlias[$key],
        'translations' => $nameTranslations[$key] ?? FALSE,
        'image' => $images[$key] ? $images[$key] : FALSE,
        'children' => empty($children) ? FALSE : $children,
      ];
    }
    return $formatted;
  }

  protected function getImageSettings($term) {
    if ($this->imageSettings) {
      return $this->imageSettings;
    }

    foreach ($term->getFieldDefinitions() as $fieldName => $fieldDefinition) {
      if (false !== strpos($fieldName, 'image') || false !== strpos($fieldName, 'img')) {
        /** @var \Drupal\field\Entity\FieldConfig $fieldDefinition */
        $this->imageSettings['default_image_directory'] = $term->{$fieldName}->getSetting('file_directory');
        $this->imageSettings['field_name'] = $fieldName;

        break;
      }
    }

    return $this->imageSettings;
  }

  protected function getImageUri($term, &$field=null) {
    $field = $this->getImageSettings($term);
    /** @var \Drupal\file\Plugin\Field\FieldType\FileFieldItemList $field */
    $field = $term->get($this->getImageSettings($term)['field_name']);
    if (! $field = $field->first()) {
      return FALSE;
    }
    /** @var \Drupal\image\Plugin\Field\FieldType\ImageItem $field */

    if ($field->entity && $directory = $field->entity->getFileUri()){
      $directory = array_slice(explode('/', $directory), 2);

      return [
        'file_name' => array_pop($directory),
        'directory' => implode('/', $directory),
        'uri' => $field->entity->getFileUri(),
      ];
    }

    return FALSE;
  }
  protected function setImage($term, $image) {
    if (empty($image)) {
      return FALSE;
    }
    $field = $this->getImageSettings($term);
    if (!$field) {
      return FALSE;
    }
    /** @var \Drupal\file\Plugin\Field\FieldType\FileFieldItemList $field */
    $field = $term->{$this->imageSettings['field_name']};



    $field->set(0, File::create([
      'uri' => $image,
      'status' => 1,
    ]));

    return TRUE;
  }
}
