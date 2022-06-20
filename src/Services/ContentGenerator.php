<?php

namespace Drupal\drupal_regression\Services;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\pathauto\PathautoState;

/**
 * Content generator, for the regression API.
 * 
 * It automatically loops through the available CTs and Paragraphs,
 * and creates mock data.
 */
class ContentGenerator {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Constructs a new Content Generator.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    EntityFieldManagerInterface $entity_field_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * Load module settings from config.
   */
  public function getSettings() {
    $config = $this->configFactory->get('drupal_regression.settings');

    return $config->getRawData();
  }

  /**
   * Load mock data from config.
   */
  public function getMockData() {
    $config = $this->configFactory->get('drupal_regression.mock_data');

    return $config->getRawData();
  }

  /**
   * Generate a single, mock entity.
   */
  public function generate($entity_type, $bundle) {
    $settings = $this->getSettings();
    $mock_data = $this->getMockData();
    $messages = [
      'warnings' => [],
      'errors' => [],
    ];

    $fields = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);

    if (!empty($settings['ignored_bundles'][$entity_type]) &&
        in_array($bundle, $settings['ignored_bundles'][$entity_type])) {

      return [
        'messages' => ['warnings' => ["{$entity_type}: {$bundle} has been ignored."]],
        'entity' => NULL,
      ];
    }

    $field_data = [
      'type' => $bundle,
      'title' => "drupal-regression: ({$entity_type}: {$bundle})",
      'status' => TRUE,
      'path' => [
        'pathauto' => PathautoState::SKIP,
      ],
    ];

    foreach ($fields as $field_name => $field_definition) {
      if (!empty($settings['ignored_fields'][$entity_type]) &&
          in_array($field_name, ($settings['ignored_fields'][$entity_type]))) {
        continue;
      }

      // Base fields (nid, uuid, revisions..) should not be filled manually.
      if (empty($mock_data['fields'][$field_name]) && $field_definition->getFieldStorageDefinition()->isBaseField()) {
        continue;
      }

      $field_type = $field_definition->getType();

      $is_field_type_reference =
        ($field_type === 'entity_reference' || $field_type === 'entity_reference_revisions');

      if (!empty($mock_data['fields'][$field_name])) {
        if ($is_field_type_reference) {
          $field_data[$field_name][]['target_id'] = $mock_data['fields'][$field_name];

          continue;
        }

        $field_data[$field_name] = $mock_data['fields'][$field_name];

        continue;
      }

      $target_type = $field_definition->getSetting('target_type');

      if ($is_field_type_reference &&
          !empty($mock_data['entity_reference_target_types'][$target_type])) {
        $field_data[$field_name][]['target_id'] = $mock_data['entity_reference_target_types'][$target_type];

        continue;
      }

      if (empty($mock_data['field_types'][$field_type])) {
        $messages['errors'][] = "Could not find any data for field type: {$field_type} ({$entity_type}: {$bundle}: {$field_name})";

        continue;
      }

      $field_data[$field_name] = $mock_data['field_types'][$field_type];
    }

    $entity = $this->entityTypeManager->getStorage($entity_type)->create($field_data);

    $entity->save();

    return [
      'messages' => $messages,
      'entity' => $entity,
    ];
  }

}
