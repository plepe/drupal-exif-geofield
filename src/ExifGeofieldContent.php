<?php

namespace Drupal\exif_geofield;

use Drupal\exif\ExifContent;
use Drupal\exif\ExifFactory;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\file\Plugin\Field\FieldType\FileFieldItemList;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\UriItem;
use Drupal\Core\StreamWrapper\StreamWrapperInterface;

use Drupal;


/**
 * Class ExifGeofieldContent make link between drupal content and file content.
 *
 * @package Drupal\exif_geofield
 */
class ExifGeofieldContent {
  /**
   * Main entry of the module.
   *
   * @param string $entityType
   *   The entity type name to be modified.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to look for metadata fields.
   * @param bool $update
   *   Indicate an Update (against an Insert).
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function entity_insert_update($entityType, FieldableEntityInterface $entity, $update = TRUE) {
    // TODO: do load location when file gets replaced
    if ($update) {
      return;
    }

    $bundles_to_check = $this->getBundleForExifData();

    if (in_array($entity->bundle(), $bundles_to_check)) {
      $exif = ExifFactory::getExifInterface();
      $ar_exif_fields = $this->filterFieldsOnSettings($entityType, $entity);

      if (sizeof($ar_exif_fields)) {
	$image_fields = $this->getImageFields($entity);
	$metadata_images_fields = $this->getImageFieldsMetadata($entity, $ar_exif_fields, $image_fields);

	foreach ($image_fields as $image_field_name => $image_ref) {
	  if (array_key_exists($image_field_name, $metadata_images_fields)) {
	    $data = $metadata_images_fields[$image_field_name][0];
	    $value = "POINT({$data['gps']['gpslongitude']} {$data['gps']['gpslatitude']})";

	    foreach ($ar_exif_fields as $field_name => $field_ref) {
	      if ($field_ref['image_field'] === $image_field_name) {
		$field = $entity->get($field_name);
		$field->offsetSet(0, $value);
	      }
	    }
	  }
	}
      }
    }
  }

  /**
   * Check if this node type contains an image field.
   *
   * @return array
   *   List of bundle where the exif data could be updated.
   */
  private function getBundleForExifData() {
    $config = Drupal::config('exif.settings');
    $new_types = [];
    // Fill up array with checked nodetypes.
    foreach ($config->get('nodetypes', []) as $type) {
      if ($type != "0") {
        $new_types[] = $type;
      }
    }
    foreach ($config->get('mediatypes', []) as $type) {
      if ($type != "0") {
        $new_types[] = $type;
      }
    }
    foreach ($config->get('filetypes', []) as $type) {
      if ($type != "0") {
        $new_types[] = $type;
      }
    }
    if (\Drupal::moduleHandler()->moduleExists('photos')) {
      // Photos module integration.
      $new_types[] = 'photos_image';
    }
    return $new_types;
  }

  /**
   * Look for metadata fields in an entity type.
   *
   * @param string $entityType
   *   The entity type name to be modified.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to look for metadata fields.
   *
   * @return array
   *   The list of metadata fields found in the entity.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function filterFieldsOnSettings($entityType, FieldableEntityInterface $entity) {
    $result = [];
    foreach ($entity->getFieldDefinitions() as $fieldName => $fieldDefinition) {
      if ($fieldDefinition instanceof FieldConfigInterface || ($fieldDefinition instanceof BaseFieldDefinition and $fieldName === 'title')) {
        $settings = NULL;
        $formDisplay = \Drupal::entityTypeManager()
          ->getStorage('entity_form_display')
          ->load($entityType . '.' . $entity->bundle() . '.default')
          ->getComponent($fieldName);
        if ($formDisplay && $formDisplay['type'] === 'exif_geofield_readonly') {
          $settings = $formDisplay['settings'];
          if (array_key_exists('image_field', $settings)) {
            $imageField = $settings['image_field'];
	    $result[$fieldName] = [
	      'image_field' => $imageField,
	      'metadata_field' => [
	        'section' => 'gps',
		'tag' => 'gpslatitude',
	      ],
	    ];
          }
        }
      }
    }
    return $result;
  }

  /**
   * Look for image fields in an entity type.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to look for image fields.
   *
   * @return array
   *   the list of image fields found in the entity
   */
  private function getImageFields(FieldableEntityInterface $entity) {
    $result = [];
    if ($entity->getEntityTypeId() == 'node' or $entity->getEntityTypeId() == 'media' || $entity->getEntityTypeId() == 'photos_image') {
      foreach ($entity->getFieldDefinitions() as $fieldName => $fieldDefinition) {
        if (in_array($fieldDefinition->getType(), ['image', 'file'])) {
          $result[$fieldName] = $fieldDefinition;
        }
      }
    }
    if ($entity->getEntityTypeId() == 'file') {
      $result['file'] = $entity;
    }
    return $result;
  }

  /**
   * List fields that contains exif metadata.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   * @param $ar_exif_fields
   * @param $image_fields
   *
   * @return array|bool
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  private function getImageFieldsMetadata(FieldableEntityInterface $entity, &$ar_exif_fields, $image_fields) {
    $result = [];
    if (empty($ar_exif_fields)) {
      return TRUE;
    }
    if (empty($image_fields)) {
      return FALSE;
    }

    foreach ($ar_exif_fields as $drupal_field => $metadata_settings) {
      $field_image_name = $metadata_settings['image_field'];
      if (empty($image_fields[$field_image_name])) {
        $result[$field_image_name] = [];
      }
      else {
        $images_descriptor = $this->getFileUriAndLanguage($entity, $field_image_name);
        if ($images_descriptor == FALSE) {
          $fullmetadata = [];
        }
        else {
          foreach ($images_descriptor as $index => $image_descriptor) {
            $fullmetadata[$index] = $this->getDataFromFileUri($image_descriptor['uri']);
          }
        }
        $result[$field_image_name] = $fullmetadata;
        $ar_exif_fields[$drupal_field]['language'] = $image_descriptor['language'];
      }
    }
    return $result;
  }

  /**
   * Retrieve the URI and Language of an image.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity to look for.
   * @param string $field_image_name
   *   The field name containing the image.
   *
   * @return array|bool
   *   Array with uri and language for each images in
   *   or FALSE if the entity type is not known.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  private function getFileUriAndLanguage(FieldableEntityInterface $entity, $field_image_name) {
    $result = FALSE;
    if ($entity->getEntityTypeId() == 'node' || $entity->getEntityTypeId() == 'media' || $entity->getEntityTypeId() == 'photos_image') {
      $image_field_instance = $entity->get($field_image_name);
      if ($image_field_instance instanceof FileFieldItemList) {
        $nbImages = count($image_field_instance->getValue());
        $result = [];
        for ($i = 0; $i < $nbImages; $i++) {
          $result[$i] = [];
          $tmp = $image_field_instance->get($i)->entity;
          $result[$i]['uri'] = $tmp->uri[0];
          $result[$i]['language'] = $tmp->language();
        }
      }
    }
    else {
      if ($entity->getEntityTypeId() == 'file') {
        $result = [];
        $result[0] = [];
        $result[0]['uri'] = $entity->uri;
        $result[0]['language'] = $entity->language();
      }
    }
    return $result;
  }

  /**
   * Retrieve all metadata values from an image.
   *
   * @param \Drupal\Core\Field\Plugin\Field\FieldType\UriItem $file_uri
   *   The File URI to look at.
   *
   * @return array
   *   A map of metadata values by key.
   */
  private function getDataFromFileUri(UriItem $file_uri) {
    $uri = $file_uri->getValue()['value'];

    $scheme = \Drupal::service('stream_wrapper_manager')->getScheme($uri);

    // If the file isn't stored locally make a temporary copy to read the
    // metadata from. We just assume that the temporary files are always local,
    // hard to figure out how to handle this otherwise.
    if (!isset(\Drupal::service('stream_wrapper_manager')
        ->getWrappers(StreamWrapperInterface::LOCAL)[$scheme])) {
      // Local stream.
      $cache_key = md5($uri);
      if (empty($this->localCopiesOfRemoteFiles[$cache_key])) {
        // Create unique local file.
        if (!($this->localCopiesOfRemoteFiles[$cache_key] = \Drupal::service('file_system')->copy($uri, 'temporary://exif_' . $cache_key . '_' . basename($uri), FileSystemInterface::EXISTS_REPLACE))) {
          // Log error if creating a copy fails - but return an empty array to
          // avoid type collision.
          \Drupal::logger('exif')
            ->notice('Unable to create local temporary copy of remote file for exif extraction! File %file.',
              [
                '%file' => $uri,
              ]);
          return [];
        }
      }
      $uri = $this->localCopiesOfRemoteFiles[$cache_key];
    }
    // Read the metadata.
    $exif = ExifFactory::getExifInterface();

    /** @var \Drupal\Core\File\FileSystem $file_system */
    $file_system = \Drupal::service('file_system');
    $fullmetadata = $exif->readMetadataTags($file_system->realpath($uri));
    return $fullmetadata;
  }

}
