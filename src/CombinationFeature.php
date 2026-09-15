<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace PrestaShop\Module\FacetedSearch;

/**
 * Tells whether the faceted search must also take combination (product_attribute) feature values
 * into account, in addition to the product ones.
 *
 * Fork Batinea: upstream gates this on PrestaShop >= 9.3 plus the "combination_feature_values"
 * feature flag, because that is the version that introduced feature values at combination level.
 * Here we run on PrestaShop 8, where the `feature_product_attribute` table and its back-office UI
 * are provided by the `creafeatures` module instead. That module is therefore the only switch:
 * neither the core feature flag nor the version check exist, so the filtering is always on.
 *
 * @see https://github.com/PrestaShop/ps_facetedsearch/pull/1292
 */
class CombinationFeature
{
    /**
     * @return bool
     */
    public static function isFilteringEnabled()
    {
        return true;
    }

    /**
     * Kept for signature compatibility with upstream, there is no state to reset anymore.
     */
    public static function resetCache()
    {
    }
}
