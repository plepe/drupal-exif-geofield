<?php

namespace Drupal\exif_geofield\Plugin\Field\FieldWidget;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\exif\Plugin\Field\FieldWidget\ExifWidgetBase;

/**
 * Plugin implementation of the 'exif_geofield_readonly' widget.
 *
 * @FieldWidget(
 *   id = "exif_geofield_readonly",
 *   label = @Translation("location as WKT from image (viewable in forms)"),
 *   description = @Translation("field content is calculated from image field
 *   in the same content type (field are viewable but readonly in forms)"),
 *   multiple_values = true, field_types = {
 *     "geofield",
 *     "text",
 *   }
 * )
 */
class ExifGeofieldReadonlyWidget extends ExifWidgetBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a ExifGeofieldReadonlyWidget object.
   *
   * @param string $plugin_id
   *   The plugin_id for the widget.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the widget is associated.
   * @param array $settings
   *   The widget settings.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings, $entity_field_manager);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $value = $items->getValue();
    $entity_type = $items->getFieldDefinition()->getTargetEntityTypeId();
    $access = $this->entityTypeManager->getAccessControlHandler($entity_type)
      ->fieldAccess('view', $items->getFieldDefinition());
    if (!$access) {
      $element += [
        '#type' => '#hidden',
        '#value' => '',
      ];
    }
    $element += $items->view();
    $element += [
      '#value' => $value,
      '#default_value' => $value,
    ];
    return $element;
  }

}
