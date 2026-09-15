<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

namespace PrestaShop\Module\FacetedSearch\Tests;

use Mockery\Adapter\Phpunit\MockeryTestCase;
use PrestaShop\Module\FacetedSearch\CombinationFeature;

class CombinationFeatureTest extends MockeryTestCase
{
    /**
     * Fork Batinea: unlike upstream, filtering on combination feature values is not gated on the
     * PrestaShop version nor on the "combination_feature_values" feature flag. The
     * feature_product_attribute table is provided by the creafeatures module, so installing that
     * module is the only switch and the faceted search always takes those values into account.
     */
    public function testFilteringIsAlwaysEnabled()
    {
        $this->assertTrue(CombinationFeature::isFilteringEnabled());

        CombinationFeature::resetCache();

        $this->assertTrue(CombinationFeature::isFilteringEnabled());
    }
}
