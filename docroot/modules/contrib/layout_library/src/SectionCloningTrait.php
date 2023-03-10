<?php

namespace Drupal\layout_library;

use Drupal\layout_builder\Plugin\Block\InlineBlock;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\Section;

/**
 * A Trait for deep cloning methods for section inline blocks.
 */
trait SectionCloningTrait {

  /**
   * The allowed types for deep cloning.
   *
   * @return array
   *   Array of entity types ids.
   */
  protected function getAllowedTypes() {
    return [
      'block_content',
    ];
  }

  /**
   * Deep clone template sections.
   *
   * @param array $sections
   *   Array of sections.
   *
   * @return array
   *   Array of deep cloned sections.
   */
  protected function deepCloneSections(array $sections) {
    $deep_cloned_sections = [];
    foreach ($sections as $section) {
      $deep_cloned_sections[] = $this->deepCloneSection($section);
    }
    return $deep_cloned_sections;
  }

  /**
   * Deep clone section.
   *
   * @param object $section
   *   The section object.
   *
   * @return object
   *   The new section object.
   */
  protected function deepCloneSection($section) {
    $section_array = $section->toArray();

    // Clone section.
    $cloned_section = new Section(
      $section->getLayoutId(),
      $section->getLayoutSettings(),
      $section->getComponents(),
      $section_array['third_party_settings']
    );

    // Replace section components with new instances.
    $deep_cloned_section = $this->cloneAndReplaceSectionComponents($cloned_section);

    return $deep_cloned_section;
  }

  /**
   * Clone and replace the section components.
   *
   * @param object $section
   *   The section object.
   *
   * @return object
   *   The modified section object.
   */
  protected function cloneAndReplaceSectionComponents($section) {
    foreach ($section->getComponents() as $uuid => $component) {
      $component_array = $component->toArray();
      $configuration = $component_array['configuration'];
      $additional = $component_array['additional'];
      // Create a new component.
      $new_component = new SectionComponent(\Drupal::service('uuid')->generate(), $component->getRegion(), $configuration, $additional);

      $plugin_block = $component->getPlugin();
      if ($plugin_block instanceof InlineBlock) {
        try {
          // Now fetch the entity itself for recursive cloning. We have to use
          // reflection for this as it's a protected method.
          $reflectionMethod = new \ReflectionMethod($plugin_block, 'getEntity');
          $reflectionMethod->setAccessible(TRUE);
          $entity = $reflectionMethod->invoke($plugin_block);
          $duplicate_entity = $entity->createDuplicate();
          $configuration['block_uuid'] = NULL;
          $configuration['block_revision_id'] = NULL;
          $configuration['block_serialized'] = NULL;
          // Duplicate referenced entities of allowed types.
          $this->cloneReferencedEntities($duplicate_entity);
          // Save as serialized. Otherwise we get into trouble with MediaLibrary access checks
          // if it already has an ID but the layout hasn't been saved yet, meaning it
          // hasn't been added to the usage table yet. Moreover, if it has an ID when
          // we save the layout then it also seems to not want to add it to the usage
          // table. No clue why. But keeping it serialized seems to fix everything,
          // and is how core itself handles adding new blocks as well.
          $configuration['block_serialized'] = serialize($duplicate_entity);
        }
        catch (\ReflectionException $e) {
          watchdog_exception('layout_library', $e);
        }
      }

      $new_component->setWeight($component->getWeight());
      $new_component->setConfiguration($configuration);

      // Insert the new component after the old one.
      $section->insertAfterComponent($uuid, $new_component);

      // Then remove old component.
      $section->removeComponent($uuid);
    }

    return $section;
  }

  /**
   * Clone entity referenced entities.
   *
   * @param object $entity
   *   The entity we like duplicate its ReferencedEntities.
   */
  protected function cloneReferencedEntities($entity) {
    $entity_fields = $entity->getFields();
    foreach ($entity_fields as $field_key => $entity_field) {
      $value = $entity_field->getValue();
      // Handle Paragraph as special case as it does not support
      // referencedEntities(), check issue #3089724, also it uses revsion id.
      /** @var \Drupal\Core\Field\FieldDefinitionInterface[] $field_definitions */
      $field_definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions($entity->getEntityTypeId(), $entity->bundle());
      foreach ($field_definitions as $definition) {
        switch ($definition->getFieldStorageDefinition()->getType()) {
          case 'entity_reference_revisions':
            // Support for Entity reference revisions.
            $this->cloneEntityReferenceRevisions($field_key, $entity_field, $entity);
            break;

          case 'entity_reference':
            // Support for Entity reference fields that target inline blocks.
            $value = $entity_field->getValue();
            $lang_code = \Drupal::languageManager()->getCurrentLanguage()->getId();
            if ($definition->getSetting('target_type') == 'block_content') {
              $referenced_entities = $entity_field->referencedEntities();
              // Create a duplicate entity reference and replace
              // the current target ids with the new entites.
              $new_referenced_target_ids = [];
              foreach ($referenced_entities as $entity_reference) {
                // Skip any items not included in getAllowedTypes method.
                // such as User, Media, Taxonomy term, Node...etc.
                if (!in_array($entity_reference->getEntityTypeId(), $this->getAllowedTypes())) {
                  $new_referenced_target_ids[] = ['target_id' => $entity_reference->id()];
                  continue;
                }

                $new_entity_reference = $entity_reference->createDuplicate();
                $new_entity_reference->set("langcode", $lang_code);
                $new_entity_reference->save();
                $new_referenced_target_ids[] = ['target_id' => $new_entity_reference->id()];
                // Recursive call.
                $this->cloneReferencedEntities($new_entity_reference);
              }

              if (!empty($new_referenced_target_ids)) {
                $entity->set($field_key, $new_referenced_target_ids);
              }
            }
            elseif (isset($value[0]['entity'])) {
              $new_value = [];
              foreach ($value as $key => $item) {
                if ($item['entity']) {
                  $new_paragraph_entity = $item['entity']->createDuplicate();
                  $new_value[$key]['entity'] = $new_paragraph_entity;
                }
              }

              if (!empty($new_value)) {
                $entity->set($field_key, $new_value);
              }
            }
            break;
        }
      }
    }
  }

  /**
   * Clone paragraphs referenced entities.
   *
   * @param string $field_key
   *   The field machine name.
   * @param object $entity_field
   *   The field object.
   * @param object $entity
   *   The original entity object.
   */
  protected function cloneEntityReferenceRevisions($field_key, $entity_field, &$entity) {
    $value = $entity_field->getValue();
    $new_value = [];
    $lang_code = \Drupal::languageManager()->getCurrentLanguage()->getId();
    // IEF - complex form mode structure with Pargraph.
    if (
      $entity_field->getName() != 'type'
      && isset($value[0]['target_id'])
      && $entity_field->getSetting('target_type') == 'paragraph'
    ) {
      foreach ($value as $key => $item) {
        $paragraph = \Drupal::entityTypeManager()->getStorage('paragraph')->load($value[$key]['target_id']);
        $new_paragraph = $paragraph->createDuplicate();
        $new_paragraph->set("langcode", $lang_code);
        // It's important to save the entity in the IEF - complex case.
        $new_paragraph->save();
        $new_value[$key]['target_id'] = $new_paragraph->id();
        $new_value[$key]['target_revision_id'] = $new_paragraph->getRevisionId();
      }
    }
    // IEF - complex form mode structure with Block content.
    elseif (
      $entity_field->getName() != 'type'
      && isset($value[0]['target_id'])
      && $entity_field->getSetting('target_type') == 'block_content'
    ) {
      foreach ($value as $key => $item) {
        $block_content = \Drupal::entityTypeManager()->getStorage('block_content')->load($value[$key]['target_id']);
        $new_block_content = $block_content->createDuplicate();
        $new_block_content->set("langcode", $lang_code);
        // It's important to save the entity in the IEF - complex case.
        $new_block_content->save();
        $new_value[$key]['target_id'] = $new_block_content->id();
        $new_value[$key]['target_revision_id'] = $new_block_content->getRevisionId();
      }
    }

    if (!empty($new_value)) {
      $entity->set($field_key, $new_value);
    }
  }

}
