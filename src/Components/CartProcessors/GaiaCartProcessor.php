<?php declare(strict_types=1);

namespace OncoSfcAddon\Components\CartProcessors;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class GaiaCartProcessor implements CartProcessorInterface
{
    private QuantityPriceCalculator $calculator;

    public function __construct(
        QuantityPriceCalculator $calculator
    )
    {
        $this->calculator = $calculator;
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        foreach ($toCalculate->getLineItems()->getFlat() as $lineItem) {
            $payload = $lineItem->getPayload();

            $configurationDetails = $payload['gaiaConfigurationDetails'] ?? null;
            if (!is_array($configurationDetails) || $configurationDetails === []) {
                continue;
            }

            $weight = $configurationDetails['weight'] ?? null;
            if (!empty($weight)) {
                $lineItem->getDeliveryInformation()?->setWeight((float) $weight);
            }

            if (!empty($configurationDetails['isPriceUntouched'])) {
                continue;
            }

            $priceFound = false;
            $priceGross = null;
            $priceNet = null;

            $pricesWithQuantitySteps = $configurationDetails['pricesWithQuantitySteps'] ?? null;
            if ($lineItem->getQuantity() > 1 && is_array($pricesWithQuantitySteps) && $pricesWithQuantitySteps !== []) {
                // Sort step keys numerically descending once, so the first step <= quantity wins.
                $stepKeys = array_keys($pricesWithQuantitySteps);
                usort($stepKeys, static fn ($a, $b): int => (int) $b <=> (int) $a);

                foreach ($stepKeys as $quantityStep) {
                    if ($lineItem->getQuantity() < (int) $quantityStep) {
                        continue;
                    }
                    $prices = $pricesWithQuantitySteps[$quantityStep];
                    if (!is_array($prices)) {
                        break;
                    }
                    // Default price (empty rule id) is the fallback.
                    $_price = $prices[''] ?? null;
                    // Walk the context rule IDs in the order they are provided (priority order)
                    // and pick the FIRST matching rule price.
                    foreach ($context->getContext()->getRuleIds() as $ruleId) {
                        if (array_key_exists($ruleId, $prices)) {
                            $_price = $prices[$ruleId];
                            break;
                        }
                    }
                    if ($_price !== null) {
                        $priceGross = $_price;
                        $taxRate = $configurationDetails['taxRate'] ?? null;
                        if (!is_numeric($taxRate) || (float) $taxRate < 0.0) {
                            $taxRate = 19;
                        } else {
                            $taxRate = (float) $taxRate;
                        }
                        $priceNet = $priceGross / (100 + $taxRate) * 100;
                        $priceFound = true;
                    }
                    break;
                }
            }

            $prices = $configurationDetails['prices'] ?? null;
            $pricesNet = $configurationDetails['pricesNet'] ?? null;
            if (!$priceFound && is_array($prices) && $prices !== []) {
                foreach ($context->getContext()->getRuleIds() as $ruleId) {
                    if (array_key_exists($ruleId, $prices)) {
                        $priceFound = true;
                        $priceGross = $prices[$ruleId];
                        $priceNet = is_array($pricesNet) && array_key_exists($ruleId, $pricesNet)
                            ? $pricesNet[$ruleId]
                            : null;
                        break;
                    }
                }
            }

            if (!$priceFound && !empty($configurationDetails['price'])) {
                $priceGross = $configurationDetails['price'];
                $priceNet = $configurationDetails['priceNet'] ?? null;
            }

            if ($priceGross === null || $priceNet === null || $lineItem->getPrice() === null) {
                continue;
            }


            $acrisListPrice = $payload['acrisListPrice'] ?? null;
            $acrisDiscountGroup = $payload['acrisDiscountGroup'] ?? null;
            if (is_array($acrisListPrice) && is_array($acrisDiscountGroup)) {
                $totalPercentage = $acrisListPrice['totalPercentage'] ?? null;
                $originalUnitPrice = $acrisDiscountGroup['originalUnitPrice'] ?? null;
                $listUnitPrice = $acrisListPrice['unitPrice'] ?? null;

                // Guard originalUnitPrice must be a positive numeric value.
                if (!is_numeric($originalUnitPrice) || (float) $originalUnitPrice <= 0.0) {
                    // Skip the factor adjustment rather than dividing by zero / a negative.
                } elseif (!is_numeric($totalPercentage) || !is_numeric($listUnitPrice)) {
                    // Skip when inputs are non-numeric.
                } else {
                    $totalPercentage = max(0.0, min(100.0, (float) $totalPercentage));
                    $dummyNewPrice = (float) $listUnitPrice * (100.0 - $totalPercentage) / 100.0;
                    $factor = $dummyNewPrice / (float) $originalUnitPrice;
                    $priceGross *= $factor;
                    $priceNet *= $factor;
                }
            }

            $definition = new QuantityPriceDefinition(
                $context->getTaxState() === CartPrice::TAX_STATE_GROSS ? $priceGross : $priceNet,
                $lineItem->getPrice()->getTaxRules(),
                $lineItem->getPrice()->getQuantity()
            );

            $calculated = $this->calculator->calculate($definition, $context);
            $lineItem->setPrice($calculated);
            $lineItem->setPriceDefinition($definition);
        }
    }
}
