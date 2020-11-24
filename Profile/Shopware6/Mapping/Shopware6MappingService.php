<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SwagMigrationAssistant\Profile\Shopware6\Mapping;

use Shopware\Core\Content\MailTemplate\Aggregate\MailTemplateType\MailTemplateTypeEntity;
use Shopware\Core\Content\Media\Aggregate\MediaDefaultFolder\MediaDefaultFolderEntity;
use Shopware\Core\Content\Product\SalesChannel\Sorting\ProductSortingEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Write\EntityWriterInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\Aggregate\NumberRangeType\NumberRangeTypeEntity;
use SwagMigrationAssistant\Migration\DataSelection\DefaultEntities;
use SwagMigrationAssistant\Migration\Mapping\MappingService;
use SwagMigrationAssistant\Migration\MigrationContextInterface;

class Shopware6MappingService extends MappingService implements Shopware6MappingServiceInterface
{
    /**
     * @var EntityRepositoryInterface
     */
    protected $numberRangeTypeRepo;

    /**
     * @var EntityRepositoryInterface
     */
    protected $mailTemplateTypeRepo;

    /**
     * @var EntityRepositoryInterface
     */
    protected $salutationRepo;

    /**
     * @var EntityRepositoryInterface
     */
    protected $seoUrlTemplateRepo;

    /**
     * @var EntityRepositoryInterface
     */
    protected $systemConfigRepo;

    /**
     * @var EntityRepositoryInterface
     */
    protected $productSortingRepo;

    public function __construct(
        EntityRepositoryInterface $migrationMappingRepo,
        EntityRepositoryInterface $localeRepository,
        EntityRepositoryInterface $languageRepository,
        EntityRepositoryInterface $countryRepository,
        EntityRepositoryInterface $currencyRepository,
        EntityRepositoryInterface $taxRepo,
        EntityRepositoryInterface $numberRangeRepo,
        EntityRepositoryInterface $ruleRepo,
        EntityRepositoryInterface $thumbnailSizeRepo,
        EntityRepositoryInterface $mediaDefaultRepo,
        EntityRepositoryInterface $categoryRepo,
        EntityRepositoryInterface $cmsPageRepo,
        EntityRepositoryInterface $deliveryTimeRepo,
        EntityRepositoryInterface $documentTypeRepo,
        EntityWriterInterface $entityWriter,
        EntityDefinition $mappingDefinition,
        EntityRepositoryInterface $numberRangeTypeRepo,
        EntityRepositoryInterface $mailTemplateTypeRepo,
        EntityRepositoryInterface $salutationRepo,
        EntityRepositoryInterface $seoUrlTemplateRepo,
        EntityRepositoryInterface $systemConfigRepo,
        EntityRepositoryInterface $productSortingRepo
    ) {
        parent::__construct(
            $migrationMappingRepo,
            $localeRepository,
            $languageRepository,
            $countryRepository,
            $currencyRepository,
            $taxRepo,
            $numberRangeRepo,
            $ruleRepo,
            $thumbnailSizeRepo,
            $mediaDefaultRepo,
            $categoryRepo,
            $cmsPageRepo,
            $deliveryTimeRepo,
            $documentTypeRepo,
            $entityWriter,
            $mappingDefinition
        );

        $this->numberRangeTypeRepo = $numberRangeTypeRepo;
        $this->mailTemplateTypeRepo = $mailTemplateTypeRepo;
        $this->salutationRepo = $salutationRepo;
        $this->seoUrlTemplateRepo = $seoUrlTemplateRepo;
        $this->systemConfigRepo = $systemConfigRepo;
        $this->productSortingRepo = $productSortingRepo;
    }

    public function getMailTemplateTypeUuid(string $type, string $oldIdentifier, MigrationContextInterface $migrationContext, Context $context): ?string
    {
        $connection = $migrationContext->getConnection();
        if ($connection === null) {
            return null;
        }

        $connectionId = $connection->getId();
        $typeMapping = $this->getMapping($connectionId, DefaultEntities::MAIL_TEMPLATE_TYPE, $oldIdentifier, $context);

        if ($typeMapping !== null) {
            return $typeMapping['entityUuid'];
        }

        $result = $context->disableCache(function (Context $context) use ($type): EntitySearchResult {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('technicalName', $type));

            return $this->mailTemplateTypeRepo->search($criteria, $context);
        });

        if ($result->getTotal() > 0) {
            /** @var MailTemplateTypeEntity|null $mailTemplateType */
            $mailTemplateType = $result->getEntities()->first();

            if ($mailTemplateType === null) {
                return null;
            }

            $this->saveMapping(
                [
                    'id' => Uuid::randomHex(),
                    'connectionId' => $connectionId,
                    'entity' => DefaultEntities::NUMBER_RANGE_TYPE,
                    'oldIdentifier' => $oldIdentifier,
                    'entityUuid' => $mailTemplateType->getId(),
                ]
            );

            return $mailTemplateType->getId();
        }

        return null;
    }

    public function getNumberRangeTypeUuid(string $type, string $oldIdentifier, MigrationContextInterface $migrationContext, Context $context): ?string
    {
        $connection = $migrationContext->getConnection();
        if ($connection === null) {
            return null;
        }

        $connectionId = $connection->getId();
        $typeMapping = $this->getMapping($connectionId, DefaultEntities::NUMBER_RANGE_TYPE, $oldIdentifier, $context);

        if ($typeMapping !== null) {
            return $typeMapping['entityUuid'];
        }

        $result = $context->disableCache(function (Context $context) use ($type): EntitySearchResult {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('technicalName', $type));

            return $this->numberRangeTypeRepo->search($criteria, $context);
        });

        if ($result->getTotal() > 0) {
            /** @var NumberRangeTypeEntity|null $numberRangeType */
            $numberRangeType = $result->getEntities()->first();

            if ($numberRangeType === null) {
                return null;
            }

            $this->saveMapping(
                [
                    'id' => Uuid::randomHex(),
                    'connectionId' => $connectionId,
                    'entity' => DefaultEntities::NUMBER_RANGE_TYPE,
                    'oldIdentifier' => $oldIdentifier,
                    'entityUuid' => $numberRangeType->getId(),
                ]
            );

            return $numberRangeType->getId();
        }

        return null;
    }

    public function getDefaultFolderIdByEntity(string $entityName, MigrationContextInterface $migrationContext, Context $context): ?string
    {
        $connection = $migrationContext->getConnection();
        if ($connection === null) {
            return null;
        }

        $connectionId = $connection->getId();
        $defaultFolderMapping = $this->getMapping($connectionId, DefaultEntities::MEDIA_DEFAULT_FOLDER, $entityName, $context);

        if ($defaultFolderMapping !== null) {
            return $defaultFolderMapping['entityUuid'];
        }

        /** @var EntitySearchResult $result */
        $result = $context->disableCache(function (Context $context) use ($entityName) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('entity', $entityName));

            return $this->mediaDefaultFolderRepo->search($criteria, $context);
        });

        if ($result->getTotal() > 0) {
            /** @var MediaDefaultFolderEntity|null $mediaDefaultFolder */
            $mediaDefaultFolder = $result->getEntities()->first();
            if ($mediaDefaultFolder === null) {
                return null;
            }

            $this->saveMapping(
                [
                    'id' => Uuid::randomHex(),
                    'connectionId' => $connectionId,
                    'entity' => DefaultEntities::MEDIA_DEFAULT_FOLDER,
                    'oldIdentifier' => $entityName,
                    'entityUuid' => $mediaDefaultFolder->getId(),
                ]
            );

            return $mediaDefaultFolder->getId();
        }

        return null;
    }

    public function getSalutationUuid(string $oldIdentifier, string $salutationKey, MigrationContextInterface $migrationContext, Context $context): ?string
    {
        $connection = $migrationContext->getConnection();
        if ($connection === null) {
            return null;
        }

        $connectionId = $connection->getId();
        $salutationMapping = $this->getMapping($connectionId, DefaultEntities::SALUTATION, $oldIdentifier, $context);

        if ($salutationMapping !== null) {
            return $salutationMapping['entityUuid'];
        }

        /** @var IdSearchResult $result */
        $result = $context->disableCache(function (Context $context) use ($salutationKey) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('salutationKey', $salutationKey));
            $criteria->setLimit(1);

            return $this->salutationRepo->searchIds($criteria, $context);
        });

        $salutationId = $result->firstId();

        if ($salutationId !== null) {
            $this->saveMapping(
                [
                    'id' => Uuid::randomHex(),
                    'connectionId' => $connectionId,
                    'entity' => DefaultEntities::SALUTATION,
                    'oldIdentifier' => $oldIdentifier,
                    'entityUuid' => $salutationId,
                ]
            );
        }

        return $salutationId;
    }

    public function getSeoUrlTemplateUuid(
        string $oldIdentifier,
        ?string $salesChannelId,
        string $routeName,
        MigrationContextInterface $migrationContext,
        Context $context
    ): ?string {
        $connection = $migrationContext->getConnection();
        if ($connection === null) {
            return null;
        }

        $connectionId = $connection->getId();
        $seoUrlTemplateMapping = $this->getMapping($connectionId, DefaultEntities::SEO_URL_TEMPLATE, $oldIdentifier, $context);
        if ($seoUrlTemplateMapping !== null) {
            return $seoUrlTemplateMapping['entityUuid'];
        }

        /** @var IdSearchResult $result */
        $result = $context->disableCache(function (Context $context) use ($salesChannelId, $routeName) {
            $criteria = new Criteria();
            $criteria->addFilter(
                new MultiFilter(
                    MultiFilter::CONNECTION_AND,
                    [
                        new EqualsFilter('salesChannelId', $salesChannelId),
                        new EqualsFilter('routeName', $routeName),
                    ]
                )
            );
            $criteria->setLimit(1);

            return $this->seoUrlTemplateRepo->searchIds($criteria, $context);
        });

        $seoUrlTemplateId = $result->firstId();

        if ($seoUrlTemplateId !== null) {
            $this->saveMapping(
                [
                    'id' => Uuid::randomHex(),
                    'connectionId' => $connectionId,
                    'entity' => DefaultEntities::SEO_URL_TEMPLATE,
                    'oldIdentifier' => $oldIdentifier,
                    'entityUuid' => $seoUrlTemplateId,
                ]
            );
        }

        return $seoUrlTemplateId;
    }

    public function getSystemConfigUuid(string $oldIdentifier, string $configurationKey, ?string $salesChannelId, MigrationContextInterface $migrationContext, Context $context): ?string
    {
        $connection = $migrationContext->getConnection();
        if ($connection === null) {
            return null;
        }

        $connectionId = $connection->getId();
        $systemConfigMapping = $this->getMapping($connectionId, DefaultEntities::SYSTEM_CONFIG, $oldIdentifier, $context);

        if ($systemConfigMapping !== null) {
            return $systemConfigMapping['entityUuid'];
        }

        /** @var IdSearchResult $result */
        $result = $context->disableCache(function (Context $context) use ($configurationKey, $salesChannelId) {
            $criteria = new Criteria();
            $criteria->addFilter(
                new MultiFilter(
                    MultiFilter::CONNECTION_AND,
                    [
                        new EqualsFilter('salesChannelId', $salesChannelId),
                        new EqualsFilter('configurationKey', $configurationKey),
                    ]
                )
            );
            $criteria->setLimit(1);

            return $this->systemConfigRepo->searchIds($criteria, $context);
        });

        $systemConfigId = $result->firstId();

        if ($systemConfigId !== null) {
            $this->saveMapping(
                [
                    'id' => Uuid::randomHex(),
                    'connectionId' => $connectionId,
                    'entity' => DefaultEntities::SYSTEM_CONFIG,
                    'oldIdentifier' => $oldIdentifier,
                    'entityUuid' => $systemConfigId,
                ]
            );
        }

        return $systemConfigId;
    }

    public function getProductSortingUuid(string $key, Context $context): array
    {
        /** @var EntitySearchResult $result */
        $result = $context->disableCache(function (Context $context) use ($key) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('key', $key));
            $criteria->setLimit(1);

            return $this->productSortingRepo->search($criteria, $context);
        });

        /** @var ProductSortingEntity|null $productSorting */
        $productSorting = $result->first();
        $id = null;
        $isLocked = false;

        if ($productSorting !== null) {
            $id = $productSorting->getId();
            $isLocked = $productSorting->isLocked();
        }

        return [$id, $isLocked];
    }
}
