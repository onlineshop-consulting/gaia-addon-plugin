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


            if (!empty($payload['gaiaConfigurationDetails']['weight'])) {
                $lineItem->getDeliveryInformation()->setWeight($payload['gaiaConfigurationDetails']['weight']);
            }


            if (empty($payload['gaiaConfigurationDetails']) || !empty($payload['gaiaConfigurationDetails']['isPriceUntouched'])) {
                continue;
            }
            $priceFound = false;

            if ($lineItem->getQuantity() > 1 && !empty($payload['gaiaConfigurationDetails']['pricesWithQuantitySteps'])) {
                $foundPrice = null;
                foreach ($payload['gaiaConfigurationDetails']['pricesWithQuantitySteps'] as $quantityStep => $prices) {
                    if ($lineItem->getQuantity() >= $quantityStep) {
                        $_price = $prices[''] ?? null;
                        foreach ($context->getContext()->getRuleIds() as $ruleId) {
                            foreach ($prices as $priceRuleId => $price) {
                                if ($priceRuleId === $ruleId) {
                                    $_price = $price;
                                }
                            }
                        }
                        if ($_price !== null) {
                            $foundPrice = $_price;
                        }
                    }
                }
                if ($foundPrice !== null) {
                    $priceGross = $foundPrice;
                    $taxRate = $payload['gaiaConfigurationDetails']['taxRate'] ?? 19;
                    $priceNet = $priceGross / (100 + $taxRate) * 100;
                    $priceFound = true;
                }
            }
            if (!empty($payload['gaiaConfigurationDetails']['prices'])) {
                foreach ($context->getContext()->getRuleIds() as $ruleId) {
                    foreach ($payload['gaiaConfigurationDetails']['prices'] as $priceRuleId => $price) {
                        if ($priceRuleId === $ruleId && !$priceFound) {
                            $priceFound = true;
                            $priceGross = $price;
                            $priceNet = $payload['gaiaConfigurationDetails']['pricesNet'][$priceRuleId];
                        }
                    }
                }
            }

            if (!$priceFound && !empty($payload['gaiaConfigurationDetails']['price'])) {
                $priceGross = $payload['gaiaConfigurationDetails']['price'];
                $priceNet = $payload['gaiaConfigurationDetails']['priceNet'];
            }

            if (!isset($priceGross) || !isset($priceNet)) {
                continue;
            }

            // build new price definition
            $definition = new QuantityPriceDefinition(
                $context->getTaxState() === CartPrice::TAX_STATE_GROSS ? $priceGross : $priceNet,
                $lineItem->getPrice()->getTaxRules(),
                $lineItem->getPrice()->getQuantity()
            );

            // build CalculatedPrice over calculator class for overwritten price
            $calculated = $this->calculator->calculate($definition, $context);

            // set new price into line item
            $lineItem->setPrice($calculated);
            $lineItem->setPriceDefinition($definition);
        }
    }
}