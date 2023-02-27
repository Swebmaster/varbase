<?php

namespace Drupal\drimage\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;

/**
 * Plugin implementation of the 'dynamic responsive image' formatter.
 *
 * @FieldFormatter(
 *   id = "drimage_uri",
 *   label = @Translation("Dynamic Responsive Image"),
 *   field_types = {
 *     "uri",
 *     "file_uri"
 *   }
 * )
 */
class DrImageUriFormatter extends DrImageFormatter {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    // @todo: can it not be multiple?
    $file = $items->getEntity();
    $config = \Drupal::configFactory()->get('drimage.settings');

    $url = Url::fromUri('internal:/')->toString();
    if (substr($url, -1) === '/') {
      $url = substr($url, 0, -1);
    }

    $elements[0]['#item_attributes'] = new Attribute();
    $elements[0]['#item_attributes']['class'] = ['drimage'];
    $elements[0]['#theme'] = 'drimage_formatter';
    $elements[0]['#data'] = [
      'fid' => $file->id(),
      // Add the original filename for SEO purposes.
      'filename' => pathinfo($file->getFileUri())['basename'],
      // Add needed data for calculations.
      'threshold' => $config->get('threshold'),
      'upscale' => $config->get('upscale'),
      'downscale' => $config->get('downscale'),
      'multiplier' => $config->get('multiplier'),
      'imageapi_optimize_webp' => $config->get('imageapi_optimize_webp'),
      'lazy_offset' => $config->get('lazy_offset'),
      'subdir' => $url,
    ];

    // Get original image data. (non cropped, non processed) This is useful when
    // implementing lightbox-style plugins that show the original image.
    $elements[0]['#width'] = $file->getMetaData('width');
    $elements[0]['#height'] = $file->getMetaData('height');
    $elements[0]['#imageapi_optimize_webp'] = $config->get('imageapi_optimize_webp');
    $elements[0]['#alt'] = $file->getMetaData('alt');
    $elements[0]['#data']['original_width'] = $file->getMetaData('width');
    $elements[0]['#data']['original_height'] = $file->getMetaData('height');
    $elements[0]['#data']['original_source'] = \Drupal::service('file_url_generator')->generateString($file->getFileUri());

    // Add image_handling and specific data for the type of handling.
    $elements[0]['#data']['image_handling'] = $this->getSetting('image_handling');
    switch ($elements[0]['#data']['image_handling']) {
      case 'background':
        $elements[0]['#data']['background'] = [
          'attachment' => $this->getSetting('background')['attachment'],
          'position' => $this->getSetting('background')['position'],
          'size' => $this->getSetting('background')['size'],
        ];
        break;

      case 'aspect_ratio':
        $elements[0]['#data']['aspect_ratio'] = [
          'width' => $this->getSetting('aspect_ratio')['width'],
          'height' => $this->getSetting('aspect_ratio')['height'],
        ];
        $elements[0]['#width'] = $this->getSetting('aspect_ratio')['width'];
        $elements[0]['#height'] = $this->getSetting('aspect_ratio')['height'];
        break;

      case 'iwc':
        $elements[0]['#data']['iwc'] = [
          'image_style' => $this->getSetting('iwc')['image_style'],
        ];
        break;

      case 'scale':
      default:
        // Nothing extra needed here.
        break;
    }

    // Unset the fallback image.
    unset($elements[0]['#image']);

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareView(array $entities_items) {}

}
