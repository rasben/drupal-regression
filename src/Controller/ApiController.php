<?php

namespace Drupal\drupal_regression\Controller;

use Drupal\drupal_regression\Services\ContentGenerator;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Class CustomRestController.
 */
class ApiController extends ControllerBase {

  /**
   * The bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $bundleInfoService;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * drupal Regression content generator.
   *
   * @var \Drupal\drupal_regression\Services\ContentGenerator
   */
  protected $contentGenerator;

  /**
   * Config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new Content Generator.
   *
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundle_info_service
   *   The bundle info service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\drupal_regression\Services\ContentGenerator $content_generator
   *   The regression content generator.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    EntityTypeBundleInfoInterface $bundle_info_service,
    EntityTypeManagerInterface $entity_type_manager,
    RendererInterface $renderer,
    ContentGenerator $content_generator,
    ConfigFactoryInterface $config_factory
  ) {
    $this->bundleInfoService = $bundle_info_service;
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
    $this->contentGenerator = $content_generator;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('drupal_regression.content_generator'),
      $container->get('config.factory'),
    );
  }

  /**
   * Return all available dummies.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The formatted JSON response.
   */
  public function getAll() {
    $endpoints_urls = [];
    $endpoints = [];
    $messages = [];

    $entity_bundle_mapping = [
      'node' => $this->bundleInfoService->getBundleInfo('node'),
      'paragraph' => $this->bundleInfoService->getBundleInfo('paragraph'),
    ];

    foreach ($entity_bundle_mapping as $entity_type => $entity_bundles) {
      foreach ($entity_bundles as $entity_bundle => $entity_bundle_data) {
        $generator_result = $this->contentGenerator->generate($entity_type, $entity_bundle);
        $entity = $generator_result['entity'] ?? NULL;

        $result_messages = $generator_result['messages'] ?? NULL;

        if ($result_messages) {
          $messages = array_merge_recursive($messages, $result_messages);
        }

        if (!$entity) {
          continue;
        }

        $id = $entity->id();

        $endpoints[$entity_type][] = $id;

        $endpoints_urls["$entity_type--$entity_bundle.html"] = [
          'file' => "{$entity_type}--{$entity_bundle}.html",
          'url' => "/api/regression/content/{$entity_type}/{$id}",
        ];
      }
    }

    // Saving the endpoints, so we can check for permissions later.
    $this->state()->set('drupal_regression.endpoints', $endpoints);

    // Initialize the response array.
    $response_array = [
      'generated' => date('Y-m-d H:i:s'),
      'messages' => $messages,
      'endpoints' => $endpoints_urls,
    ];

    // Create the JSON response object and add the cache metadata.
    return new JsonResponse($response_array);
  }

  /**
   * Load a single piece of content's rendered DOM.
   */
  public function getContent(string $entity_type, int $id) {
    // Getting the available endpoints, to make sure we dont expose
    // data that is not publically visible.
    $endpoints = $this->state()->get('drupal_regression.endpoints', []);

    if (empty($endpoints[$entity_type]) || !in_array($id, $endpoints[$entity_type])) {
      return new Response(
        'You dont have access to view this.',
        Response::HTTP_FORBIDDEN,
        ['content-type' => 'text/plain']
      );
    }

    $entity = $this->entityTypeManager->getStorage($entity_type)->load($id);

    $view_builder = $this->entityTypeManager->getViewBuilder($entity_type);

    $render_array = $view_builder->view($entity);
    $html = $this->renderer->render($render_array);

    // Remove empty linebreaks.
    $html = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $html);

    // Remove various attributes that will be unique from each session/PR.
    $html = preg_replace('/js-view-dom-id(?s)(.*)"/', '"', $html);
    $html = str_replace("node/{$id}", 'node/__ENTITY_ID__', $html);
    $html = str_replace("p-{$id}", 'p-__ENTITY_ID__', $html);
    $html = str_replace($_SERVER['HTTP_HOST'], 'localhost', $html);

    return new Response(
      $html,
      Response::HTTP_OK,
      ['content-type' => 'text/html']
    );
  }

  /**
   * Only enable this endpoint if we're not on prod.
   *
   * This is both to avoid any accidental nodes getting added, but also to
   * further safe-guard against accidental data exposure.
   */
  public function access() {
    $enabled = $this->configFactory->get('drupal_regression')->get('enabled');

    return AccessResult::allowedIf(!empty($enabled));
  }

}
