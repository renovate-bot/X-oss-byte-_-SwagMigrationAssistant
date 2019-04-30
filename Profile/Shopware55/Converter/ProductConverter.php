<?php declare(strict_types=1);

namespace SwagMigrationNext\Profile\Shopware55\Converter;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerGroup\CustomerGroupDefinition;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Content\Media\Aggregate\MediaTranslation\MediaTranslationDefinition;
use Shopware\Core\Content\Media\MediaDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductManufacturer\ProductManufacturerDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductManufacturerTranslation\ProductManufacturerTranslationDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductMedia\ProductMediaDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductPrice\ProductPriceDefinition;
use Shopware\Core\Content\Product\Aggregate\ProductTranslation\ProductTranslationDefinition;
use Shopware\Core\Content\Product\ProductDefinition;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOption\PropertyGroupOptionDefinition;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupOptionTranslation\PropertyGroupOptionTranslationDefinition;
use Shopware\Core\Content\Property\Aggregate\PropertyGroupTranslation\PropertyGroupTranslationDefinition;
use Shopware\Core\Content\Property\PropertyGroupDefinition;
use Shopware\Core\Content\Rule\RuleDefinition;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Rule\Container\AndRule;
use Shopware\Core\Framework\Rule\Container\OrRule;
use Shopware\Core\System\Currency\CurrencyDefinition;
use Shopware\Core\System\Tax\TaxDefinition;
use Shopware\Core\System\Unit\Aggregate\UnitTranslation\UnitTranslationDefinition;
use Shopware\Core\System\Unit\UnitDefinition;
use SwagMigrationNext\Migration\Converter\ConvertStruct;
use SwagMigrationNext\Migration\Logging\LoggingServiceInterface;
use SwagMigrationNext\Migration\Mapping\MappingServiceInterface;
use SwagMigrationNext\Migration\Media\MediaFileServiceInterface;
use SwagMigrationNext\Migration\MigrationContextInterface;
use SwagMigrationNext\Profile\Shopware55\Exception\ParentEntityForChildNotFoundException;
use SwagMigrationNext\Profile\Shopware55\Logging\Shopware55LogTypes;
use SwagMigrationNext\Profile\Shopware55\Premapping\ProductManufacturerReader;
use SwagMigrationNext\Profile\Shopware55\Shopware55Profile;

class ProductConverter extends Shopware55Converter
{
    public const MAIN_PRODUCT_TYPE = 1;
    public const VARIANT_PRODUCT_TYPE = 2;

    /**
     * @var MappingServiceInterface
     */
    private $mappingService;

    /**
     * @var Context
     */
    private $context;

    /**
     * @var string
     */
    private $oldProductId;

    /**
     * @var string
     */
    private $connectionId;

    /**
     * @var string
     */
    private $runId;

    /**
     * @var MediaFileServiceInterface
     */
    private $mediaFileService;

    /**
     * @var LoggingServiceInterface
     */
    private $loggingService;

    /**
     * @var string[]
     */
    private $requiredDataFieldKeys = [
        'tax',
        'prices',
    ];

    /**
     * @var string
     */
    private $locale;

    /**
     * @var int
     */
    private $productType;

    /**
     * @var string
     */
    private $mainProductId;

    public function __construct(
        MappingServiceInterface $mappingService,
        MediaFileServiceInterface $mediaFileService,
        LoggingServiceInterface $loggingService
    ) {
        $this->mappingService = $mappingService;
        $this->mediaFileService = $mediaFileService;
        $this->loggingService = $loggingService;
    }

    public function getSupportedEntityName(): string
    {
        return ProductDefinition::getEntityName();
    }

    public function getSupportedProfileName(): string
    {
        return Shopware55Profile::PROFILE_NAME;
    }

    public function writeMapping(Context $context): void
    {
        $this->mappingService->writeMapping($context);
    }

    /**
     * @throws ParentEntityForChildNotFoundException
     */
    public function convert(
        array $data,
        Context $context,
        MigrationContextInterface $migrationContext
    ): ConvertStruct {
        $this->context = $context;
        $this->runId = $migrationContext->getRunUuid();
        $this->connectionId = $migrationContext->getConnection()->getId();
        $this->oldProductId = $data['detail']['ordernumber'];
        $this->mainProductId = $data['detail']['articleID'];
        $this->locale = $data['_locale'];

        $fields = $this->checkForEmptyRequiredDataFields($data, $this->requiredDataFieldKeys);
        if (!empty($fields)) {
            $this->loggingService->addWarning(
                $this->runId,
                Shopware55LogTypes::EMPTY_NECESSARY_DATA_FIELDS,
                'Empty necessary data fields',
                sprintf('Product-Entity could not converted cause of empty necessary field(s): %s.', implode(', ', $fields)),
                [
                    'id' => $this->oldProductId,
                    'entity' => 'Product',
                    'fields' => $fields,
                ],
                \count($fields)
            );

            return new ConvertStruct(null, $data);
        }

        $this->productType = (int) $data['detail']['kind'];
        unset($data['detail']['kind']);
        $isProductWithVariant = $data['configurator_set_id'] !== null;

        if ($this->productType === self::MAIN_PRODUCT_TYPE && $isProductWithVariant) {
            return $this->convertMainProduct($data);
        }

        if ($this->productType === self::VARIANT_PRODUCT_TYPE && $isProductWithVariant) {
            return $this->convertVariantProduct($data);
        }

        $converted = $this->getUuidForProduct($data);
        $converted = $this->getProductData($data, $converted);

        if (!isset($converted['manufacturerId']) && !isset($converted['manufacturer'])) {
            return new ConvertStruct(null, $data);
        }

        if (empty($data)) {
            $data = null;
        }

        return new ConvertStruct($converted, $data);
    }

    private function convertMainProduct(array $data): ConvertStruct
    {
        $containerUuid = $this->mappingService->createNewUuid(
            $this->connectionId,
            ProductDefinition::getEntityName() . '_container',
            $data['id'],
            $this->context
        );
        $converted['id'] = $containerUuid;
        unset($data['detail']['articleID']);

        $converted = $this->getProductData($data, $converted);

        $converted['children'][] = $converted;
        $converted['productNumber'] .= 'M';
        $converted['children'][0]['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            ProductDefinition::getEntityName(),
            $this->oldProductId,
            $this->context
        );
        $converted['children'][0]['parentId'] = $containerUuid;
        unset($data['detail']['id']);

        if (empty($data['detail'])) {
            unset($data['detail']);
        }

        if (empty($data)) {
            $data = null;
        }

        return new ConvertStruct($converted, $data);
    }

    /**
     * @throws ParentEntityForChildNotFoundException
     */
    private function convertVariantProduct(array $data): ConvertStruct
    {
        $parentUuid = $this->mappingService->getUuid(
            $this->connectionId,
            ProductDefinition::getEntityName() . '_container',
            $data['detail']['articleID'],
            $this->context
        );

        if ($parentUuid === null) {
            throw new ParentEntityForChildNotFoundException(ProductDefinition::getEntityName(), $this->oldProductId);
        }

        $converted = $this->getUuidForProduct($data);
        $converted['parentId'] = $parentUuid;
        $converted = $this->getProductData($data, $converted);

        if (empty($data)) {
            $data = null;
        }

        return new ConvertStruct($converted, $data);
    }

    private function getUuidForProduct(array &$data): array
    {
        $converted['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            ProductDefinition::getEntityName(),
            $this->oldProductId,
            $this->context
        );

        $this->mappingService->createNewUuid(
            $this->connectionId,
            ProductDefinition::getEntityName() . '_mainProduct',
            $data['detail']['articleID'],
            $this->context,
            null,
            $converted['id']
        );

        unset($data['detail']['id'], $data['detail']['articleID']);

        return $converted;
    }

    private function getProductData(array &$data, array $converted): array
    {
        if (isset($data['manufacturer'])) {
            $converted['manufacturer'] = $this->getManufacturer($data['manufacturer']);
            unset($data['manufacturer'], $data['supplierID']);
        } else {
            $manufacturerUuid = $this->mappingService->getUuid(
                $this->connectionId,
                ProductManufacturerReader::getMappingName(),
                'default_manufacturer',
                $this->context
            );

            if ($manufacturerUuid !== null) {
                $converted['manufacturerId'] = $manufacturerUuid;

                unset($data['supplierID']);
            } else {
                $this->loggingService->addWarning(
                    $this->runId,
                    Shopware55LogTypes::EMPTY_NECESSARY_DATA_FIELDS,
                    'Empty necessary data fields',
                    'Product-Entity could not converted cause of empty necessary field(s): manufacturer.',
                    [
                        'id' => $this->oldProductId,
                        'entity' => ProductDefinition::getEntityName(),
                        'fields' => ['manufacturer'],
                    ],
                    1
                );
            }
        }

        $converted['tax'] = $this->getTax($data['tax']);
        unset($data['tax'], $data['taxID']);

        if (isset($data['unit']['id'])) {
            $converted['unit'] = $this->getUnit($data['unit']);
        }
        unset($data['unit'], $data['detail']['unitID']);

        $setInGross = isset($data['prices'][0]['customergroup']) ? (bool) $data['prices'][0]['customergroup']['taxinput'] : false;
        $converted['price'] = $this->getPrice($data['prices'][0], $converted['tax']['taxRate'], $setInGross);
        $converted['prices'] = $this->getPrices($data['prices'], $converted);
        unset($data['prices']);

        if (isset($data['assets'])) {
            $convertedMedia = $this->getMedia($data['assets'], $converted);

            if (!empty($convertedMedia['media'])) {
                $converted['media'] = $convertedMedia['media'];
            }

            if (isset($convertedMedia['cover'])) {
                $converted['cover'] = $convertedMedia['cover'];
            }

            unset($data['assets'], $convertedMedia);
        }

        $converted['translations'] = [];
        $this->setGivenProductTranslation($data, $converted);
        unset($data['_locale']);

        if (isset($data['categories'])) {
            $converted['categories'] = $this->getCategoryMapping($data['categories']);
        }
        unset($data['categories']);

        if (isset($data['attributes'])) {
            $converted['customFields'] = $this->getAttributes($data['attributes'], ProductDefinition::getEntityName(), ['id', 'articleID', 'articledetailsID']);
        }
        unset($data['attributes']);

        $this->convertValue($converted, 'productNumber', $data['detail'], 'ordernumber', self::TYPE_STRING);

        $this->convertValue($converted, 'active', $data, 'active', self::TYPE_BOOLEAN);
        $this->convertValue($converted, 'minDeliveryTime', $data, 'shippingtime', self::TYPE_INTEGER);
        $this->convertValue($converted, 'isCloseout', $data, 'laststock', self::TYPE_BOOLEAN);
        $this->convertValue($converted, 'markAsTopseller', $data, 'topseller', self::TYPE_BOOLEAN);
        $this->convertValue($converted, 'allowNotification', $data, 'notification', self::TYPE_BOOLEAN);

        $this->convertValue($converted, 'manufacturerNumber', $data['detail'], 'suppliernumber');
        $this->convertValue($converted, 'active', $data['detail'], 'active', self::TYPE_BOOLEAN);
        $this->convertValue($converted, 'sales', $data['detail'], 'sales', self::TYPE_INTEGER);
        $this->convertValue($converted, 'stock', $data['detail'], 'instock', self::TYPE_INTEGER);
        $this->convertValue($converted, 'minStock', $data['detail'], 'stockmin', self::TYPE_INTEGER);
        $this->convertValue($converted, 'isCloseout', $data['detail'], 'laststock', self::TYPE_BOOLEAN);
        $this->convertValue($converted, 'position', $data['detail'], 'position', self::TYPE_INTEGER);
        $this->convertValue($converted, 'weight', $data['detail'], 'weight', self::TYPE_FLOAT);
        $this->convertValue($converted, 'width', $data['detail'], 'width', self::TYPE_FLOAT);
        $this->convertValue($converted, 'height', $data['detail'], 'height', self::TYPE_FLOAT);
        $this->convertValue($converted, 'length', $data['detail'], 'length', self::TYPE_FLOAT);
        $this->convertValue($converted, 'ean', $data['detail'], 'ean');
        $this->convertValue($converted, 'purchaseSteps', $data['detail'], 'purchasesteps', self::TYPE_INTEGER);
        $this->convertValue($converted, 'maxPurchase', $data['detail'], 'maxpurchase', self::TYPE_INTEGER);
        $this->convertValue($converted, 'minPurchase', $data['detail'], 'minpurchase', self::TYPE_INTEGER);
        $this->convertValue($converted, 'purchaseUnit', $data['detail'], 'purchaseunit', self::TYPE_FLOAT);
        $this->convertValue($converted, 'referenceUnit', $data['detail'], 'referenceunit', self::TYPE_FLOAT);
        $this->convertValue($converted, 'releaseDate', $data['detail'], 'releasedate', self::TYPE_DATETIME);
        $this->convertValue($converted, 'shippingFree', $data['detail'], 'shippingfree', self::TYPE_BOOLEAN);
        $this->convertValue($converted, 'minDeliveryTime', $data['detail'], 'shippingtime', self::TYPE_INTEGER);
        $this->convertValue($converted, 'purchasePrice', $data['detail'], 'purchaseprice', self::TYPE_FLOAT);

        $this->getOptions($converted, $data);
        $this->getFilters($data);

        // Legacy data which don't need a mapping or there is no equivalent field
        unset(
            $data['id'],
            $data['datum'],
            $data['changetime'],
            $data['crossbundlelook'],
            $data['mode'],
            $data['main_detail_id'],
            $data['available_from'],
            $data['available_to'],
            $data['pseudosales'],
            $data['configurator_set_id'],

            // TODO check how to handle these
            $data['pricegroupID'],
            $data['pricegroupActive'],
            $data['filtergroupID'],
            $data['template'],
            $data['detail']['additionaltext']
        );

        if (empty($data['detail'])) {
            unset($data['detail']);
        }

        return $converted;
    }

    private function getOptions(&$converted, &$data): void
    {
        if (
            !isset($data['configuratorOptions'])
            || !is_array($data['configuratorOptions'])
        ) {
            return;
        }

        $options = [];
        $productContainerUuid = $this->mappingService->getUuid(
            $this->connectionId,
            ProductDefinition::getEntityName() . '_container',
            $this->mainProductId,
            $this->context
        );

        $language = $this->mappingService->getDefaultLanguage($this->context);
        $shouldBeTranslated = true;
        if ($language->getLocale()->getCode() === $this->locale) {
            $shouldBeTranslated = false;
        }

        foreach ($data['configuratorOptions'] as $option) {
            if ($productContainerUuid !== null) {
                $this->mappingService->createNewUuidListItem(
                    $this->connectionId,
                    'main_product_options',
                    hash('md5', strtolower($option['name'] . '_' . $option['group']['name'])),
                    null,
                    $productContainerUuid
                );
            }

            $optionElement = [
                'id' => $this->mappingService->createNewUuid(
                    $this->connectionId,
                    PropertyGroupOptionDefinition::getEntityName(),
                    hash('md5', strtolower($option['name'] . '_' . $option['group']['name'])),
                    $this->context
                ),

                'group' => [
                    'id' => $this->mappingService->createNewUuid(
                        $this->connectionId,
                        PropertyGroupDefinition::getEntityName(),
                        hash('md5', strtolower($option['group']['name'])),
                        $this->context
                    ),
                ],
            ];

            if ($shouldBeTranslated) {
                $this->getOptionTranslation($optionElement, $option);
            }

            $this->convertValue($optionElement, 'name', $option, 'name');
            $this->convertValue($optionElement, 'position', $option, 'position', self::TYPE_INTEGER);

            $this->convertValue($optionElement['group'], 'name', $option['group'], 'name');
            $this->convertValue($optionElement['group'], 'description', $option['group'], 'description');

            $options[] = $optionElement;
        }
        unset($data['configuratorOptions']);

        $converted['options'] = $options;
    }

    private function getFilters(&$data): void
    {
        if (
            !isset($data['filters'])
            || !is_array($data['filters'])
        ) {
            return;
        }

        $productContainerUuid = $this->mappingService->getUuid(
            $this->connectionId,
            ProductDefinition::getEntityName() . '_container',
            $this->mainProductId,
            $this->context
        );

        if ($productContainerUuid === null) {
            $productContainerUuid = $this->mappingService->getUuid(
                $this->connectionId,
                ProductDefinition::getEntityName() . '_mainProduct',
                $this->mainProductId,
                $this->context
            );
        }

        if ($productContainerUuid === null) {
            return;
        }

        foreach ($data['filters'] as $option) {
            if (!isset($option['value'], $option['option']['name'])) {
                continue;
            }

            $this->mappingService->createNewUuidListItem(
                $this->connectionId,
                'main_product_filter',
                hash('md5', strtolower($option['value'] . '_' . $option['option']['name'])),
                null,
                $productContainerUuid
            );
        }
        unset($data['filters']);
    }

    private function getOptionTranslation(array &$option, array $data): void
    {
        $localeOptionTranslation = [];
        $languageUuid = $this->mappingService->getLanguageUuid($this->connectionId, $this->locale, $this->context);
        $localeOptionTranslation['languageId'] = $languageUuid;
        $localeGroupTranslation = $localeOptionTranslation;

        $localeOptionTranslation['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            PropertyGroupOptionTranslationDefinition::getEntityName(),
            hash('md5', strtolower($data['name'] . '_' . $data['group']['name'])) . ':' . $this->locale,
            $this->context
        );

        $this->convertValue($localeOptionTranslation, 'name', $data, 'name');
        $this->convertValue($localeOptionTranslation, 'position', $data, 'position', self::TYPE_INTEGER);

        $localeGroupTranslation['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            PropertyGroupTranslationDefinition::getEntityName(),
            hash('md5', strtolower($data['group']['name'])) . ':' . $this->locale,
            $this->context
        );

        $this->convertValue($localeGroupTranslation, 'name', $data['group'], 'name');
        $this->convertValue($localeGroupTranslation, 'description', $data['group'], 'description');

        $option['translations'][$languageUuid] = $localeOptionTranslation;
        $option['group']['translations'][$languageUuid] = $localeGroupTranslation;
    }

    private function getManufacturer(array $data): array
    {
        $manufacturer['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            ProductManufacturerDefinition::getEntityName(),
            $data['id'],
            $this->context
        );

        $this->getManufacturerTranslation($manufacturer, $data);
        $this->convertValue($manufacturer, 'link', $data, 'link');
        $this->convertValue($manufacturer, 'name', $data, 'name');
        $this->convertValue($manufacturer, 'description', $data, 'description');

        if (isset($data['media'])) {
            $manufacturer['media'] = $this->getManufacturerMedia($data['media']);
        }

        if (isset($data['attributes'])) {
            $manufacturer['customFields'] = $this->getAttributes($data['attributes'], ProductManufacturerDefinition::getEntityName(), ['id', 'supplierID']);
        }

        return $manufacturer;
    }

    private function getManufacturerTranslation(array &$manufacturer, array $data): void
    {
        $language = $this->mappingService->getDefaultLanguage($this->context);
        if ($language->getLocale()->getCode() === $this->locale) {
            return;
        }

        $localeTranslation = [];
        $localeTranslation['productManufacturerId'] = $manufacturer['id'];

        $this->convertValue($localeTranslation, 'name', $data, 'name');
        $this->convertValue($localeTranslation, 'description', $data, 'description');

        $localeTranslation['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            ProductManufacturerTranslationDefinition::getEntityName(),
            $data['id'] . ':' . $this->locale,
            $this->context
        );

        $languageUuid = $this->mappingService->getLanguageUuid($this->connectionId, $this->locale, $this->context);
        $localeTranslation['languageId'] = $languageUuid;

        $manufacturer['translations'][$languageUuid] = $localeTranslation;
    }

    private function getTax(array $taxData): array
    {
        $taxRate = (float) $taxData['tax'];
        $taxUuid = $this->mappingService->getTaxUuid($this->connectionId, $taxRate, $this->context);

        if (empty($taxUuid)) {
            $taxUuid = $this->mappingService->createNewUuid(
                $this->connectionId,
                TaxDefinition::getEntityName(),
                $taxData['id'],
                $this->context
            );
        }

        return [
            'id' => $taxUuid,
            'taxRate' => $taxRate,
            'name' => $taxData['description'],
        ];
    }

    private function getUnit(array $data): array
    {
        $unit = [];
        $unit['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            UnitDefinition::getEntityName(),
            $data['id'],
            $this->context
        );

        $this->getUnitTranslation($unit, $data);
        $this->convertValue($unit, 'shortCode', $data, 'unit');
        $this->convertValue($unit, 'name', $data, 'description');

        return $unit;
    }

    private function getUnitTranslation(array &$unit, $data): void
    {
        $language = $this->mappingService->getDefaultLanguage($this->context);
        if ($language->getLocale()->getCode() === $this->locale) {
            return;
        }

        $localeTranslation = [];

        $this->convertValue($localeTranslation, 'shortCode', $data, 'unit');
        $this->convertValue($localeTranslation, 'name', $data, 'description');

        $localeTranslation['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            UnitTranslationDefinition::getEntityName(),
            $data['id'] . ':' . $this->locale,
            $this->context
        );

        $languageUuid = $this->mappingService->getLanguageUuid($this->connectionId, $this->locale, $this->context);
        $localeTranslation['languageId'] = $languageUuid;

        $unit['translations'][$languageUuid] = $localeTranslation;
    }

    private function getMedia(array $media, array $converted): array
    {
        $mediaObjects = [];
        $cover = null;
        foreach ($media as $mediaData) {
            if (!isset($mediaData['media']['id'])) {
                $this->loggingService->addInfo(
                    $this->runId,
                    Shopware55LogTypes::PRODUCT_MEDIA_NOT_CONVERTED,
                    'Product-Media could not be converted',
                    'Product-Media could not be converted.',
                    [
                        'uuid' => $converted['id'],
                        'id' => $this->oldProductId,
                    ]
                );

                continue;
            }

            $newProductMedia = [];
            $newProductMedia['id'] = $this->mappingService->createNewUuid(
                $this->connectionId,
                ProductMediaDefinition::getEntityName(),
                $mediaData['id'],
                $this->context
            );
            $newProductMedia['productId'] = $converted['id'];
            $this->convertValue($newProductMedia, 'position', $mediaData, 'position', self::TYPE_INTEGER);

            $newMedia = [];
            $newMedia['id'] = $this->mappingService->createNewUuid(
                $this->connectionId,
                MediaDefinition::getEntityName(),
                $mediaData['media']['id'],
                $this->context
            );

            if (!isset($mediaData['media']['name'])) {
                $mediaData['media']['name'] = $newMedia['id'];
            }

            $this->mediaFileService->saveMediaFile(
                [
                    'runId' => $this->runId,
                    'uri' => $mediaData['media']['uri'] ?? $mediaData['media']['path'],
                    'fileName' => $mediaData['media']['name'],
                    'fileSize' => (int) $mediaData['media']['file_size'],
                    'mediaId' => $newMedia['id'],
                ]
            );

            $this->getMediaTranslation($newMedia, $mediaData);
            $this->convertValue($newMedia, 'name', $mediaData['media'], 'name');
            $this->convertValue($newMedia, 'description', $mediaData['media'], 'description');

            $newProductMedia['media'] = $newMedia;
            $mediaObjects[] = $newProductMedia;

            if ($cover === null && (int) $mediaData['main'] === 1) {
                $cover = $newProductMedia;
            }
        }

        return ['media' => $mediaObjects, 'cover' => $cover];
    }

    // Todo: Check if this is necessary, because name and description is currently not translatable
    private function getMediaTranslation(array &$media, array $data): void
    {
        $language = $this->mappingService->getDefaultLanguage($this->context);
        if ($language->getLocale()->getCode() === $this->locale) {
            return;
        }

        $localeTranslation = [];

        $this->convertValue($localeTranslation, 'name', $data['media'], 'name');
        $this->convertValue($localeTranslation, 'description', $data['media'], 'description');

        $localeTranslation['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            MediaTranslationDefinition::getEntityName(),
            $data['media']['id'] . ':' . $this->locale,
            $this->context
        );

        $languageUuid = $this->mappingService->getLanguageUuid($this->connectionId, $this->locale, $this->context);
        $localeTranslation['languageId'] = $languageUuid;

        $media['translations'][$languageUuid] = $localeTranslation;
    }

    private function getManufacturerMedia(array $media): array
    {
        $manufacturerMedia['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            MediaDefinition::getEntityName(),
            $media['id'],
            $this->context
        );

        if (empty($media['name'])) {
            $media['name'] = $manufacturerMedia['id'];
        }

        $this->mediaFileService->saveMediaFile(
            [
                'runId' => $this->runId,
                'uri' => $media['uri'] ?? $media['path'],
                'fileName' => $media['name'],
                'fileSize' => (int) $media['file_size'],
                'mediaId' => $manufacturerMedia['id'],
            ]
        );

        return $manufacturerMedia;
    }

    private function getPrice(array $priceData, float $taxRate, bool $setInGross): array
    {
        $gross = (float) $priceData['price'] * (1 + $taxRate / 100);
        $gross = $setInGross ? round($gross, 4) : $gross;

        return [
            'gross' => $gross,
            'net' => (float) $priceData['price'],
            'linked' => true,
        ];
    }

    private function getPrices(array $priceData, array $converted): array
    {
        $newData = [];
        foreach ($priceData as $price) {
            if (!isset($price['customergroup']['id'])) {
                continue;
            }

            $customerGroupUuid = $this->mappingService->getUuid(
                $this->connectionId,
                CustomerGroupDefinition::getEntityName(),
                $price['customergroup']['id'],
                $this->context
            );

            if (!isset($customerGroupUuid)) {
                continue;
            }

            $productPriceRuleUuid = $this->mappingService->createNewUuid(
                $this->connectionId,
                RuleDefinition::getEntityName(),
                'customerGroupRule_productPriceRule_' . $price['id'] . '_' . $price['customergroup']['id'],
                $this->context
            );

            $priceRuleUuid = $this->mappingService->createNewUuid(
                $this->connectionId,
                RuleDefinition::getEntityName(),
                'customerGroupRule_' . $price['customergroup']['id'],
                $this->context
            );

            $orContainerUuid = $this->mappingService->createNewUuid(
                $this->connectionId,
                RuleDefinition::getEntityName(),
                'customerGroupRule_orContainer_' . $price['customergroup']['id'],
                $this->context
            );

            $andContainerUuid = $this->mappingService->createNewUuid(
                $this->connectionId,
                RuleDefinition::getEntityName(),
                'customerGroupRule_andContainer_' . $price['customergroup']['id'],
                $this->context
            );

            $conditionUuid = $this->mappingService->createNewUuid(
                $this->connectionId,
                RuleDefinition::getEntityName(),
                'customerGroupRule_condition_' . $price['customergroup']['id'],
                $this->context
            );

            $setInGross = (bool) $price['customergroup']['taxinput'];

            $currencyUuid = null;
            if (isset($price['currencyShortName'])) {
                $currencyUuid = $this->mappingService->getUuid(
                    $this->connectionId,
                    CurrencyDefinition::getEntityName(),
                    $price['currencyShortName'],
                    $this->context
                );
            }
            if ($currencyUuid === null) {
                $this->loggingService->addWarning(
                    $this->runId,
                    Shopware55LogTypes::EMPTY_NECESSARY_DATA_FIELDS,
                    'Empty necessary data fields',
                    'Product-Price-Entity could not converted cause of empty necessary field: currencyId.',
                    ['id' => $this->oldProductId]
                );

                continue;
            }

            $data = [
                'id' => $productPriceRuleUuid,
                'productId' => $converted['id'],
                'currencyId' => $currencyUuid,
                'rule' => [
                    'id' => $priceRuleUuid,
                    'name' => $price['customergroup']['description'],
                    'priority' => 0,
                    'moduleTypes' => [
                        'types' => [
                            'price',
                        ],
                    ],
                    'conditions' => [
                        [
                            'id' => $orContainerUuid,
                            'type' => (new OrRule())->getName(),
                        ],

                        [
                            'id' => $andContainerUuid,
                            'type' => (new AndRule())->getName(),
                            'parentId' => $orContainerUuid,
                        ],

                        [
                            'id' => $conditionUuid,
                            'type' => 'customerCustomerGroup',
                            'parentId' => $andContainerUuid,
                            'position' => 1,
                            'value' => [
                                'customerGroupIds' => [
                                    $customerGroupUuid,
                                ],
                                'operator' => '=',
                            ],
                        ],
                    ],
                ],
                'price' => $this->getPrice($price, $converted['tax']['taxRate'], $setInGross),
                'quantityStart' => (int) $price['from'],
                'quantityEnd' => $price['to'] !== 'beliebig' ? (int) $price['to'] : null,
            ];

            if (isset($price['attributes'])) {
                $data['customFields'] = $this->getAttributes($price, ProductPriceDefinition::getEntityName(), ['id', 'priceID']);
            }

            $newData[] = $data;
        }

        return $newData;
    }

    private function setGivenProductTranslation(array &$data, array &$converted): void
    {
        $originalData = $data;
        $this->convertValue($converted, 'name', $data, 'name');
        $this->convertValue($converted, 'keywords', $data, 'keywords');
        $this->convertValue($converted, 'description', $data, 'description');
        $this->convertValue($converted, 'metaTitle', $data, 'metaTitle');
        $this->convertValue($converted, 'packUnit', $data['detail'], 'packunit');
        unset($data['description_long']);

        $language = $this->mappingService->getDefaultLanguage($this->context);
        if ($language->getLocale()->getCode() === $this->locale) {
            return;
        }

        $localeTranslation = [];

        $localeTranslation['productId'] = $converted['id'];
        $this->convertValue($localeTranslation, 'name', $originalData, 'name');
        $this->convertValue($localeTranslation, 'keywords', $originalData, 'keywords');
        $this->convertValue($localeTranslation, 'description', $originalData, 'description');
        $this->convertValue($localeTranslation, 'metaTitle', $originalData, 'metaTitle');
        $this->convertValue($localeTranslation, 'packUnit', $originalData['detail'], 'packunit');

        $defaultTranslation['id'] = $this->mappingService->createNewUuid(
            $this->connectionId,
            ProductTranslationDefinition::getEntityName(),
            $this->oldProductId . ':' . $this->locale,
            $this->context
        );

        $languageUuid = $this->mappingService->getLanguageUuid($this->connectionId, $this->locale, $this->context);
        $localeTranslation['languageId'] = $languageUuid;

        $converted['translations'][$languageUuid] = $localeTranslation;
    }

    private function getCategoryMapping(array $categories): array
    {
        $categoryMapping = [];

        foreach ($categories as $key => $category) {
            $categoryUuid = $this->mappingService->getUuid(
                $this->connectionId,
                CategoryDefinition::getEntityName(),
                $category['id'],
                $this->context
            );

            if ($categoryUuid === null) {
                continue;
            }

            $categoryMapping[] = ['id' => $categoryUuid];
        }

        return $categoryMapping;
    }

    private function getAttributes(array $attributes, string $entityName, array $blacklist = []): array
    {
        $result = [];

        foreach ($attributes as $attribute => $value) {
            if (in_array($attribute, $blacklist, true)) {
                continue;
            }
            $result[$entityName . '_' . $attribute] = $value;
        }

        return $result;
    }
}
