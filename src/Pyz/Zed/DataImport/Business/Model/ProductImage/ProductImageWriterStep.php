<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

namespace Pyz\Zed\DataImport\Business\Model\ProductImage;

use Orm\Zed\ProductImage\Persistence\SpyProductImage;
use Orm\Zed\ProductImage\Persistence\SpyProductImageQuery;
use Orm\Zed\ProductImage\Persistence\SpyProductImageSet;
use Orm\Zed\ProductImage\Persistence\SpyProductImageSetQuery;
use Orm\Zed\ProductImage\Persistence\SpyProductImageSetToProductImageQuery;
use Pyz\Zed\DataImport\Business\Model\Locale\Repository\LocaleRepositoryInterface;
use Pyz\Zed\DataImport\Business\Model\Product\Repository\ProductRepositoryInterface;
use Spryker\Zed\DataImport\Business\Model\DataImportStep\DataImportStepInterface;
use Spryker\Zed\DataImport\Business\Model\DataImportStep\PublishAwareStep;
use Spryker\Zed\DataImport\Business\Model\DataSet\DataSetInterface;
use Spryker\Zed\Product\Dependency\ProductEvents;
use Spryker\Zed\ProductImage\Dependency\ProductImageEvents;

class ProductImageWriterStep extends PublishAwareStep implements DataImportStepInterface
{
    public const BULK_SIZE = 100;

    public const KEY_LOCALE = 'locale';
    public const KEY_IMAGE_SET_NAME = 'image_set_name';
    public const KEY_ABSTRACT_SKU = 'abstract_sku';
    public const KEY_CONCRETE_SKU = 'concrete_sku';
    public const KEY_EXTERNAL_URL_LARGE = 'external_url_large';
    public const KEY_EXTERNAL_URL_SMALL = 'external_url_small';
    public const KEY_SORT_ORDER = 'sort_order';
    public const KEY_PRODUCT_IMAGE_SET_KEY = 'product_image_set_key';
    public const DEFAULT_IMAGE_SORT_ORDER = 0;

    /**
     * @var \Pyz\Zed\DataImport\Business\Model\Locale\Repository\LocaleRepositoryInterface
     */
    protected $localeRepository;

    /**
     * @var \Pyz\Zed\DataImport\Business\Model\Product\Repository\ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @param \Pyz\Zed\DataImport\Business\Model\Locale\Repository\LocaleRepositoryInterface $localeRepository
     * @param \Pyz\Zed\DataImport\Business\Model\Product\Repository\ProductRepositoryInterface $productRepository
     */
    public function __construct(LocaleRepositoryInterface $localeRepository, ProductRepositoryInterface $productRepository)
    {
        $this->localeRepository = $localeRepository;
        $this->productRepository = $productRepository;
    }

    /**
     * @param \Spryker\Zed\DataImport\Business\Model\DataSet\DataSetInterface $dataSet
     *
     * @return void
     */
    public function execute(DataSetInterface $dataSet)
    {
        $productImageSetEntity = $this->findOrCreateImageSet($dataSet);
        $productImageEntity = $this->findOrCreateImage($dataSet, $productImageSetEntity);

        $this->updateOrCreateImageToImageSetRelation($productImageSetEntity, $productImageEntity, $dataSet);
    }

    /**
     * @param \Spryker\Zed\DataImport\Business\Model\DataSet\DataSetInterface $dataSet
     *
     * @return \Orm\Zed\ProductImage\Persistence\SpyProductImageSet
     */
    protected function findOrCreateImageSet(DataSetInterface $dataSet)
    {
        $idLocale = $this->getIdLocaleByLocale($dataSet);

        $query = SpyProductImageSetQuery::create()
            ->filterByName($dataSet[static::KEY_IMAGE_SET_NAME])
            ->filterByFkLocale($idLocale);

        if (!empty($dataSet[static::KEY_ABSTRACT_SKU])) {
            $idProductAbstract = $this->productRepository->getIdProductAbstractByAbstractSku($dataSet[static::KEY_ABSTRACT_SKU]);
            $query->filterByFkProductAbstract($idProductAbstract);
        }

        if (!empty($dataSet[static::KEY_CONCRETE_SKU])) {
            $idProduct = $this->productRepository->getIdProductByConcreteSku($dataSet[static::KEY_CONCRETE_SKU]);
            $query->filterByFkProduct($idProduct);
        }

        if (!empty($dataSet[static::KEY_PRODUCT_IMAGE_SET_KEY])) {
            $query->filterByProductImageSetKey($dataSet[static::KEY_PRODUCT_IMAGE_SET_KEY]);
        }

        $productImageSetEntity = $query->findOneOrCreate();
        if ($productImageSetEntity->isNew() || $productImageSetEntity->isModified()) {
            $productImageSetEntity->save();

            $this->addImagePublishEvents($productImageSetEntity);
        }

        return $productImageSetEntity;
    }

    /**
     * @param \Spryker\Zed\DataImport\Business\Model\DataSet\DataSetInterface $dataSet
     *
     * @return int
     */
    protected function getIdLocaleByLocale(DataSetInterface $dataSet)
    {
        $idLocale = null;

        if (!empty($dataSet[static::KEY_LOCALE])) {
            $idLocale = $this->localeRepository->getIdLocaleByLocale($dataSet[static::KEY_LOCALE]);
        }

        return $idLocale;
    }

    /**
     * We expect that the large URL is the unique identifier for an image.
     *
     * @param \Spryker\Zed\DataImport\Business\Model\DataSet\DataSetInterface $dataSet
     * @param \Orm\Zed\ProductImage\Persistence\SpyProductImageSet $productImageSetEntity
     *
     * @return \Orm\Zed\ProductImage\Persistence\SpyProductImage
     */
    protected function findOrCreateImage(DataSetInterface $dataSet, SpyProductImageSet $productImageSetEntity)
    {
        $productImageEntity = SpyProductImageQuery::create()
            ->filterByExternalUrlLarge($dataSet[static::KEY_EXTERNAL_URL_LARGE])
            ->findOneOrCreate();

        $productImageEntity
            ->setExternalUrlSmall($dataSet[static::KEY_EXTERNAL_URL_SMALL]);

        if ($productImageEntity->isNew() || $productImageEntity->isModified()) {
            $productImageEntity->save();

            $this->addImagePublishEvents($productImageSetEntity);
        }

        return $productImageEntity;
    }

    /**
     * @param \Orm\Zed\ProductImage\Persistence\SpyProductImageSet $productImageSetEntity
     * @param \Orm\Zed\ProductImage\Persistence\SpyProductImage $productImageEntity
     * @param \Spryker\Zed\DataImport\Business\Model\DataSet\DataSetInterface $dataSet
     *
     * @return void
     */
    protected function updateOrCreateImageToImageSetRelation(
        SpyProductImageSet $productImageSetEntity,
        SpyProductImage $productImageEntity,
        DataSetInterface $dataSet
    ) {
        $productImageSetToProductImageEntity = SpyProductImageSetToProductImageQuery::create()
            ->filterByFkProductImageSet($productImageSetEntity->getIdProductImageSet())
            ->filterByFkProductImage($productImageEntity->getIdProductImage())
            ->findOneOrCreate();

        $productImageSetToProductImageEntity
            ->setSortOrder($this->getSortOrder($dataSet));

        if ($productImageSetToProductImageEntity->isNew() || $productImageSetToProductImageEntity->isModified()) {
            $productImageSetToProductImageEntity->save();

            $this->addImagePublishEvents($productImageSetEntity);
        }
    }

    /**
     * @param \Orm\Zed\ProductImage\Persistence\SpyProductImageSet $productImageSetEntity
     *
     * @return void
     */
    protected function addImagePublishEvents(SpyProductImageSet $productImageSetEntity)
    {
        if ($productImageSetEntity->getFkProductAbstract()) {
            $this->addPublishEvents(ProductImageEvents::PRODUCT_IMAGE_PRODUCT_ABSTRACT_PUBLISH, $productImageSetEntity->getFkProductAbstract());
            $this->addPublishEvents(ProductEvents::PRODUCT_ABSTRACT_PUBLISH, $productImageSetEntity->getFkProductAbstract());
        } elseif ($productImageSetEntity->getFkProduct()) {
            $this->addPublishEvents(ProductImageEvents::PRODUCT_IMAGE_PRODUCT_CONCRETE_PUBLISH, $productImageSetEntity->getFkProduct());
        }
    }

    /**
     * @param \Spryker\Zed\DataImport\Business\Model\DataSet\DataSetInterface $dataSet
     *
     * @return int
     */
    protected function getSortOrder(DataSetInterface $dataSet): int
    {
        if (isset($dataSet[static::KEY_SORT_ORDER]) && $dataSet[static::KEY_SORT_ORDER] >= 0) {
            return (int)$dataSet[static::KEY_SORT_ORDER];
        }

        return static::DEFAULT_IMAGE_SORT_ORDER;
    }
}
