<?php declare(strict_types=1);

namespace SwagMigrationNext\Test\Profile\Shopware55\Converter;

use Exception;
use PHPUnit\Framework\TestCase;
use SwagMigrationNext\Profile\Shopware55\Converter\ConverterNotFoundException;
use SwagMigrationNext\Profile\Shopware55\Converter\ConverterRegistry;
use SwagMigrationNext\Profile\Shopware55\Converter\ConverterRegistryInterface;
use SwagMigrationNext\Profile\Shopware55\Converter\ProductConverter;
use SwagMigrationNext\Test\Mock\DummyCollection;
use SwagMigrationNext\Test\Mock\Migration\Mapping\DummyMappingService;
use Symfony\Component\HttpFoundation\Response;

class ConverterRegistryTest extends TestCase
{
    /**
     * @var ConverterRegistryInterface
     */
    private $converterRegistry;

    protected function setUp()
    {
        $this->converterRegistry = new ConverterRegistry(new DummyCollection([new ProductConverter(new DummyMappingService())]));
    }

    public function testGetConverterNotFound(): void
    {
        try {
            $this->converterRegistry->getConverter('foo');
        } catch (Exception $e) {
            /* @var ConverterNotFoundException $e */
            self::assertInstanceOf(ConverterNotFoundException::class, $e);
            self::assertEquals(Response::HTTP_NOT_FOUND, $e->getStatusCode());
        }
    }
}
