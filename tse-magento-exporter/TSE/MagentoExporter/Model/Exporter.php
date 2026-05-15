<?php
declare(strict_types=1);

namespace TSE\MagentoExporter\Model;

use Magento\Store\Model\App\Emulation;

/**
 * Multi-store orchestrator.
 *
 * Strategy:
 *   1. Build the store hierarchy (stores.json).
 *   2. For each store view, emulate the store context, then run all extractors.
 *      Store-specific URLs / metadata / status / visibility / category tree are
 *      preserved this way.
 *   3. Build cross-store maps (shared SKUs, diverging categories, cross-store
 *      relationship flags).
 *
 * Returns a flat map: { "filename inside zip" => array payload }.
 */
class Exporter
{
    /** @var StoreContextResolver */
    private $storeContext;

    /** @var Emulation */
    private $emulation;

    /** @var CategoryExtractor */
    private $categoryExtractor;

    /** @var ProductExtractor */
    private $productExtractor;

    /** @var CmsPageExtractor */
    private $cmsPageExtractor;

    /** @var RelationshipBuilder */
    private $relationshipBuilder;

    /** @var CrossStoreMapper */
    private $crossStoreMapper;

    public function __construct(
        StoreContextResolver $storeContext,
        Emulation $emulation,
        CategoryExtractor $categoryExtractor,
        ProductExtractor $productExtractor,
        CmsPageExtractor $cmsPageExtractor,
        RelationshipBuilder $relationshipBuilder,
        CrossStoreMapper $crossStoreMapper
    ) {
        $this->storeContext        = $storeContext;
        $this->emulation           = $emulation;
        $this->categoryExtractor   = $categoryExtractor;
        $this->productExtractor    = $productExtractor;
        $this->cmsPageExtractor    = $cmsPageExtractor;
        $this->relationshipBuilder = $relationshipBuilder;
        $this->crossStoreMapper    = $crossStoreMapper;
    }

    public const SECTION_PRODUCTS      = 'products';
    public const SECTION_CATEGORIES    = 'categories';
    public const SECTION_CMS           = 'cms';
    public const SECTION_RELATIONSHIPS = 'relationships';
    public const SECTION_SEO           = 'seo';
    public const ALL_SECTIONS = [
        self::SECTION_PRODUCTS,
        self::SECTION_CATEGORIES,
        self::SECTION_CMS,
        self::SECTION_RELATIONSHIPS,
        self::SECTION_SEO,
    ];

    /**
     * @param array $opts {
     *   stores?:   string[]  store codes to include (empty = all),
     *   sections?: string[]  one or more of SECTION_*; empty = all,
     * }
     * @return array filename => payload
     */
    public function buildBundle(array $opts = []): array
    {
        $hierarchy = $this->storeContext->getHierarchy();

        $storesFilter = isset($opts['stores'])   ? array_values(array_filter((array) $opts['stores']))   : [];
        $sections     = isset($opts['sections']) ? array_values(array_filter((array) $opts['sections'])) : [];
        if (! $sections) $sections = self::ALL_SECTIONS;
        $sections = array_intersect($sections, self::ALL_SECTIONS);

        $includeProducts      = in_array(self::SECTION_PRODUCTS,      $sections, true);
        $includeCategories    = in_array(self::SECTION_CATEGORIES,    $sections, true);
        $includeCms           = in_array(self::SECTION_CMS,           $sections, true);
        $includeRelationships = in_array(self::SECTION_RELATIONSHIPS, $sections, true);
        $includeSeo           = in_array(self::SECTION_SEO,           $sections, true);
        // Relationships need products+categories underneath; force-extract them
        // even if the user didn't tick those sections, but DON'T emit their files.
        $needProducts   = $includeProducts   || $includeRelationships || $includeSeo;
        $needCategories = $includeCategories || $includeRelationships || $includeSeo;

        $bundle = [
            'stores.json' => $hierarchy,
        ];

        $productsByStoreCode      = [];
        $categoriesByStoreCode    = [];
        $relationshipsByStoreCode = [];

        foreach ($hierarchy['stores'] as $store) {
            $storeId   = (int) $store['id'];
            $storeCode = (string) $store['code'];
            if ($storesFilter && ! in_array($storeCode, $storesFilter, true)) continue;

            $this->emulation->startEnvironmentEmulation($storeId, \Magento\Framework\App\Area::AREA_FRONTEND, true);
            try {
                $categories = $needCategories ? $this->categoryExtractor->extractAll($storeId, $storeCode) : [];
                $products   = $needProducts   ? $this->productExtractor->extractAll($categories, $storeId, $storeCode) : [];
                $cmsPages   = $includeCms     ? $this->cmsPageExtractor->extractAll($storeId, $storeCode) : [];
            } finally {
                $this->emulation->stopEnvironmentEmulation();
            }

            $relationships  = $includeRelationships ? $this->relationshipBuilder->buildProductRelationships($products) : ['edges' => [], 'totals' => []];
            $categoryGraph  = $includeRelationships ? $this->relationshipBuilder->buildCategoryGraph($categories)      : [];
            $productCatEdges= $includeRelationships ? $this->relationshipBuilder->buildProductCategoryEdges($products) : [];

            $prefix = 'stores/' . $storeCode . '/';
            if ($includeProducts) {
                $bundle[$prefix . 'products.json'] = [
                    'description'  => sprintf('Products visible in store "%s" (id=%d).', $storeCode, $storeId),
                    'store_id'     => $storeId,
                    'store_code'   => $storeCode,
                    'website_id'   => (int) $store['website_id'],
                    'count'        => count($products),
                    'products'     => $this->stripInternalFields($products),
                ];
            }
            if ($includeCategories) {
                $bundle[$prefix . 'categories.json'] = [
                    'description'  => sprintf('Categories visible in store "%s" (id=%d). Tree per store may differ.', $storeCode, $storeId),
                    'store_id'     => $storeId,
                    'store_code'   => $storeCode,
                    'count'        => count($categories),
                    'categories'   => $categories,
                ];
            }
            if ($includeCms) {
                $bundle[$prefix . 'cms-pages.json'] = [
                    'description'  => sprintf('CMS pages assigned to store "%s" (id=%d).', $storeCode, $storeId),
                    'store_id'     => $storeId,
                    'store_code'   => $storeCode,
                    'count'        => count($cmsPages),
                    'pages'        => $cmsPages,
                ];
            }
            if ($includeRelationships) {
                $bundle[$prefix . 'product-relationships.json']  = array_merge($relationships, [
                    'store_id'   => $storeId,
                    'store_code' => $storeCode,
                ]);
                $bundle[$prefix . 'category-graph.json']         = array_merge($categoryGraph, [
                    'store_id'   => $storeId,
                    'store_code' => $storeCode,
                ]);
                $bundle[$prefix . 'product-category-edges.json'] = [
                    'description' => 'product → category edges (graph-ready). Scoped to this store.',
                    'store_id'    => $storeId,
                    'store_code'  => $storeCode,
                    'count'       => count($productCatEdges),
                    'edges'       => $productCatEdges,
                ];
            }
            if ($includeSeo) {
                $bundle[$prefix . 'seo-products.json']   = [
                    'description' => sprintf('SEO-only product slice for store "%s".', $storeCode),
                    'store_id'    => $storeId,
                    'store_code'  => $storeCode,
                    'items'       => array_map(fn($p) => [
                        'id'               => $p['id'],
                        'sku'              => $p['sku'],
                        'url'              => $p['url'],
                        'url_key'          => $p['url_key'],
                        'meta_title'       => $p['meta_title'],
                        'meta_description' => $p['meta_description'],
                        'meta_keyword'     => $p['meta_keyword'],
                    ], $products),
                ];
                $bundle[$prefix . 'seo-categories.json'] = [
                    'description' => sprintf('SEO-only category slice for store "%s".', $storeCode),
                    'store_id'    => $storeId,
                    'store_code'  => $storeCode,
                    'items'       => array_map(fn($c) => [
                        'id'               => $c['id'],
                        'url'              => $c['url'],
                        'url_key'          => $c['url_key'],
                        'meta_title'       => $c['meta_title'],
                        'meta_description' => $c['meta_description'],
                        'meta_keywords'    => $c['meta_keywords'],
                    ], $categories),
                ];
            }

            $productsByStoreCode[$storeCode]      = $products;
            $categoriesByStoreCode[$storeCode]    = $categories;
            $relationshipsByStoreCode[$storeCode] = $relationships;
        }

        // Cross-store maps only when more than one store is in scope and the
        // related sections were requested.
        $multiStore = count($productsByStoreCode) > 1;
        if ($multiStore && $includeProducts) {
            $bundle['product-store-map.json'] = $this->crossStoreMapper->buildProductStoreMap($productsByStoreCode);
        }
        if ($multiStore && $includeCategories) {
            $bundle['category-store-diff.json'] = $this->crossStoreMapper->buildCategoryStoreDiff($categoriesByStoreCode);
        }
        if ($multiStore && $includeRelationships) {
            $bundle['relationship-cross-store-flags.json'] = $this->crossStoreMapper->buildRelationshipCrossStoreFlags($relationshipsByStoreCode);
        }

        // Top-level manifest.
        $bundle['manifest.json'] = [
            'plugin'         => 'TSE Magento Exporter',
            'plugin_version' => '1.1.0',
            'generated_at'   => gmdate('c'),
            'multi_store'    => true,
            'filters'        => [
                'stores'   => $storesFilter ? array_values($storesFilter) : 'all',
                'sections' => array_values($sections),
            ],
            'totals'         => [
                'websites'        => $hierarchy['totals']['websites'],
                'groups'          => $hierarchy['totals']['groups'],
                'stores'          => $hierarchy['totals']['stores'],
                'stores_exported' => count($productsByStoreCode ?: $categoriesByStoreCode ?: []) ?: count(array_filter($hierarchy['stores'], fn($s) => ! $storesFilter || in_array($s['code'], $storesFilter, true))),
            ],
            'store_codes'    => array_map(fn($s) => $s['code'], $hierarchy['stores']),
        ];
        $bundle['manifest.json']['files'] = array_keys($bundle);

        return $bundle;
    }

    /**
     * Drop internal `product_links_raw` field from public product payload —
     * its content is already exposed in product-relationships.json.
     */
    private function stripInternalFields(array $products): array
    {
        foreach ($products as &$p) {
            unset($p['product_links_raw']);
        }
        unset($p);
        return $products;
    }
}
