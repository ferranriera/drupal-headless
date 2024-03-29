<?php

/**
 * Abstract entity validation.
 */
abstract class EntityValidateBase implements EntityValidateInterface {

  /**
   * The entity type.
   *
   * @var string
   */
  protected $entityType;

  /**
   * The bundle of the node.
   *
   * @var String.
   */
  protected $bundle;

  /**
   * List of fields keyed by machine name and valued with the field's value.
   *
   * Array with the optional values:
   * - "property": The entity property (e.g. "title", "nid").
   * - "sub_property": A sub property name of a property to take from it the
   *   content. This can be used for example on a text field with filtered text
   *   input format where we would need to do $wrapper->body->value->value().
   *   Defaults to FALSE.
   *
   * @var array
   */
  protected $publicFields = array();

  /**
   * Store the errors in case the error set to 0.
   *
   * @var Array
   */
  protected $errors = array();

  /**
   * Constructs a EntityValidateBase object.
   *
   * @param array $plugin
   *   Plugin definition.
   */
  public function __construct($plugin) {
    $this->plugin = $plugin;
    $this->entityType = $plugin['entity_type'];
    $this->bundle = $plugin['bundle'];
  }

  /**
   * {@inheritdoc}
   */
  public function setBundle($bundle) {
    $this->bundle = $bundle;

    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getBundle() {
    return $this->bundle;
  }

  /**
   * {@inheritdoc}
   */
  public function setEntityType($entity_type) {
    $this->entityType = $entity_type;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityType() {
    return $this->entityType;
  }

  /**
   * {@inheritdoc}
   */
  public function publicFieldsInfo() {
    $public_fields = array();
    $entity_info = entity_get_info($this->entityType);
    $keys = $entity_info['entity keys'];

    // When the entity has a label key we need to verify it's not empty.
    if (!empty($keys['label'])) {
      $public_fields[$keys['label']] = array(
        'required' => TRUE,
      );
    }

    $instances_info = field_info_instances($this->getEntityType(), $this->getBundle());
    foreach ($instances_info as $instance_info) {
      $field_info = field_info_field($instance_info['field_name']);

      if ($instance_info['required']) {
        // Validate field is not empty.
        $public_fields[$instance_info['field_name']]['required'] = TRUE;

        // This is a multiple field and required.
        if ($field_info['cardinality'] == FIELD_CARDINALITY_UNLIMITED) {
          $public_fields[$instance_info['field_name']]['validators'][] = array($this, 'validateMultipleFieldNotEmpty');
        }
      }

      if ($field_info['type'] == 'image') {
        // Validate the image dimensions.
        $public_fields[$instance_info['field_name']]['validators'][] = array($this, 'validateImageSize');
      }

      if (in_array($field_info['type'], array('image', 'file'))) {
        // Validate the file type.
        $public_fields[$instance_info['field_name']]['validators'][] = array($this, 'validateFileExtension');
      }
    }

    return $public_fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getPublicFields() {
    $public_fields = $this->publicFieldsInfo();
    foreach ($public_fields as $property => &$public_field) {

      // Set default values.
      $public_field += array(
        'property' => $property,
        'sub_property',
        'required' => FALSE,
        'validators' => array($this, 'isValidValue'),
      );
    }

    return $public_fields;
  }

  /**
   * {@inheritdoc}
   */
  public function validate($entity, $silent = FALSE) {
    // Clear any previous error messages.
    $this->clearErrors();
    if (!$public_fields = $this->getPublicFields()) {
      return TRUE;
    }

    $wrapper = entity_metadata_wrapper($this->entityType, $entity);

    // Collect the fields callbacks.
    foreach ($public_fields as $public_field) {
      $property = $public_field['property'];

      foreach ($public_field['validators'] as $validator) {
        $property_wrapper = $wrapper->{$property};

        if (!empty($public_field['sub_property']) && $property_wrapper->value()) {
          $property_wrapper = $property_wrapper->{$public_field['sub_property']};
        }

        $value = $property_wrapper->value();

        if ($public_field['required']) {
          // Property is required.
          $this->isNotEmpty($property, $value, $wrapper, $property_wrapper);
        }

        if ($validator) {
          // Property has value.
          call_user_func($validator, $property, $value, $wrapper, $property_wrapper);
        }
      }
    }

    if (!$errors = $this->getErrors()) {
      return TRUE;
    }

    if ($silent) {
      // Don't throw an error, and just indicate validation failed.
      return FALSE;
    }

    $params = array('@errors' => $errors);
    throw new \EntityValidatorException(format_string('The validation process failed: @errors', $params));
  }

  /**
   * {@inheritdoc}
   */
  public function setError($field_name, $message, $params = array()) {
    $params['@field'] = $field_name;
    $this->errors[$field_name][] = array('message' => $message, 'params' => $params);
  }

  /**
   * {@inheritdoc}
   */
  public function getErrors($squash = TRUE) {
    if (!$squash) {
      return $this->errors;
    }

    $return = array();
    foreach ($this->errors as $errors) {
      foreach ($errors as $error) {
        $error += array('params' => array());
        $return[] = format_string($error['message'], $error['params']);
      }
    }

    return implode("\n\r", $return);
  }

  /**
   * {@inheritdoc}
   */
  public function clearErrors() {
    $this->errors = array();
  }

  /**
   * Verify the field is not empty.
   *
   * @param string $field_name
   *   The field name.
   * @param mixed $value
   *   The value of the field.
   * @param EntityMetadataWrapper $wrapper
   *   The wrapped entity.
   * @param EntityMetadataWrapper $property_wrapper
   *   The wrapped property.
   */
  protected function isNotEmpty($field_name, $value, EntityMetadataWrapper $wrapper, EntityMetadataWrapper $property_wrapper) {
    if (empty($value)) {
      $params = array('@field' => $field_name);
      $this->setError($field_name, 'The field @field cannot be empty.', $params);
    }
  }

  /**
   * Check the value of the field using the entity API module.
   *
   * @param string $field_name
   *   The field name.
   * @param mixed $value
   *   The value of the field.
   * @param EntityMetadataWrapper $wrapper
   *   The wrapped entity.
   * @param EntityMetadataWrapper $property_wrapper
   *   The wrapped property.
   */
  protected function isValidValue($field_name, $value, EntityMetadataWrapper $wrapper, EntityMetadataWrapper $property_wrapper) {
    // Loading default value of the fields and the instance.
    if (!$field_info = field_info_field($field_name)) {
      // Not a field.
      return;
    }

    $field_type_info = field_info_field_types($field_info['type']);

    if (empty($field_type_info['property_type'])) {
      return;
    }

    if (entity_property_verify_data_type($value, $field_type_info['property_type'])) {
      // Value is valid.
      return;
    }

    $params = array(
      '@value' => (String) $value,
      '@field' => $field_name,
    );

    $this->setError($field_name, 'The value @value is invalid for the field @field.', $params);
  }

  /**
   * Validate the field image's by checking the image size is valid.
   *
   * @param string $field_name
   *   The field name.
   * @param mixed $value
   *   The value of the field.
   * @param EntityMetadataWrapper $wrapper
   *   The wrapped entity.
   * @param EntityMetadataWrapper $property_wrapper
   *   The wrapped property.
   */
  protected function validateImageSize($field_name, $value, EntityMetadataWrapper $wrapper, EntityMetadataWrapper $property_wrapper) {
    if (empty($value)) {
      return;
    }

    $info = field_info_instance($this->getEntityType(), $field_name, $this->getBundle());
    $settings = $info['settings'];

    $file = file_load($value['fid']);
    $url = file_create_url($file->uri);
    $size = getimagesize($url);

    $value = array(
      'width' => $size['0'],
      'height' => $size['1'],
    );

    $params = array(
      '@width' => $value['width'],
      '@height' => $value['height'],
    );

    if (!empty($settings['max_resolution'])) {
      list($max_height, $max_width) = explode("X", $settings['max_resolution']);

      $params += array(
        '@max-width' => $max_width,
        '@max-height' => $max_height,
      );

      if ($value['width'] > $max_width) {
        $this->setError($field_name, 'The width of the image(@width) is bigger then the allowed size(@max-width)', $params);
      }

      if ($value['height'] > $max_height) {
        $this->setError($field_name, 'The height of the image(@height) is bigger then the allowed size(@max-height)', $params);
      }
    }

    if (!empty($settings['min_resolution'])) {
      list($min_height, $min_width) = explode("X", $settings['min_resolution']);
      $params += array(
        '@min-width' => $min_width,
        '@min-height' => $min_height,
      );

      if ($value['width'] < $min_width) {
        $this->setError($field_name, 'The width of the image(@width) is bigger then the allowed size(@min-width)', $params);
      }

      if ($value['height'] < $min_height) {
        $this->setError($field_name, 'The height of the image(@height) is bigger then the allowed size(@min-height)', $params);
      }
    }
  }

  /**
   * Validate the file extension.
   *
   * @param string $field_name
   *   The field name.
   * @param mixed $value
   *   The value of the field.
   * @param EntityMetadataWrapper $wrapper
   *   The wrapped entity.
   * @param EntityMetadataWrapper $property_wrapper
   *   The wrapped property.
   */
  protected function validateFileExtension($field_name, $value, EntityMetadataWrapper $wrapper, EntityMetadataWrapper $property_wrapper) {
    if (empty($value)) {
      return;
    }

    $info = field_info_instance($this->getEntityType(), $field_name, $this->getBundle());
    $settings = $info['settings'];

    $file = file_load($value['fid']);

    $extensions = explode('.', $file->filename);
    $extension = end($extensions);

    if (!in_array($extension, explode(" ", $settings['file_extensions']))) {
      $params = array(
        '@file-name' => $file->filename,
        '@extension' => $extension,
        '@extensions' => $settings['file_extensions'],
      );
      $this->setError($field_name, 'The file (@file-name) extension (@extension) did not match the allowed extensions: @extensions', $params);
    }
  }

  /**
   * Validate a field with multiple cardinality is not empty.
   *
   * @param string $field_name
   *   The field name.
   * @param mixed $value
   *   The value of the field.
   * @param EntityMetadataWrapper $wrapper
   *   The wrapped entity.
   * @param EntityMetadataWrapper $property_wrapper
   *   The wrapped property.
   */
  public function validateMultipleFieldNotEmpty($field_name, $value, EntityMetadataWrapper $wrapper, EntityMetadataWrapper $property_wrapper) {
    foreach ($property_wrapper as $delta => $sub_wrapper) {
      if (!$sub_wrapper->value()) {
        $this->setError($field_name, 'The delta @delta cannot be empty.', array('@delta' => $delta));
      }
    }
  }
}
