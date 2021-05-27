<?php declare(strict_types=1);
/*
 * (c) shopware AG <info@shopware.com>
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace SwagMigrationAssistant\Profile\Shopware6\Gateway\Api\Reader;

use SwagMigrationAssistant\Migration\DataSelection\DefaultEntities;

class TaxRuleReader extends ApiReader
{
    protected function getIdentifier(): string
    {
        return DefaultEntities::TAX_RULE;
    }
}
