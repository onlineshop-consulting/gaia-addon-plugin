<?php declare(strict_types=1);

namespace OncoSfcAddon\Subscribers;

use OncoSfcAddon\Services\ConfigService;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OrderSubscriber implements EventSubscriberInterface
{
    private ContainerInterface $container;

    public function setContainer(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'orderPlaced',
        ];
    }

    public function orderPlaced(CheckoutOrderPlacedEvent $event)
    {
        /** @var ConfigService $configService */
        $configService = $this->container->get(ConfigService::class);
        /** @var EntityRepository $orderRepository */
        $orderRepository = $this->container->get('order.repository');
        /** @var EntityRepository $orderRepository */
        $productRepository = $this->container->get('product.repository');
        $order = $event->getOrder();
        $additionalComment = '';

        $configuratorItemCount = 0;
        /** @var OrderLineItemEntity $item */
        foreach ($order->getLineItems() as $item) {
            $itemPayload = $item->getPayload() ?? [];
            $configurationDetails = $itemPayload['gaiaConfigurationDetails'] ?? null;
            if (!$configurationDetails) {
                continue;
            }

            if ($configService->isWriteConfigurationListInOrderComment()) {
                $additionalComment .= "\n" . 'Konfiguration' . (!empty($item->getLabel()) ? ' von "' . $item->getLabel() . '":' . "\n" : '') .
                    ($configService->isConfigurationListCondensed() ? $this->getItemListPlainTextCondensed($configurationDetails) : $configurationDetails['itemListPlainText']) .

                    "\n";
            }

            if ($configService->isWriteConfigurationListInDescription()) {
                $this->container->get('order_line_item.repository')->update([
                    [
                        'id' => $item->getId(),
                        'description' => ($configService->isConfigurationListCondensed() ? $this->getItemListPlainTextCondensed($configurationDetails) : $configurationDetails['itemListPlainText']),
                    ],
                ], $event->getContext());
            }


            $this->overrideItemPayload($item, $configService, $event->getContext());


            if ($configService->isCreateOrderLineItemsForProducts()) {
                if ($configurationDetails['productRelatedItems']) {
                    foreach ($configurationDetails['productRelatedItems'] as $productRelatedItem) {
                        $product = $productRepository->search((new Criteria())->addFilter(new EqualsFilter('id', $productRelatedItem['productId'])), $event->getContext())->first();
                        $orderRepository->update([[
                            'id' => $order->getId(),
                            'lineItems' => [
                                [
                                    'identifier' => 'gaia-' . uniqid() . '-' . $product->getId(),
                                    'parentId' => $item->getId(),
                                    'productId' => $product->getId(),
                                    'referencedId' => $product->getId(),
                                    'label' => $product->getName() ?: '-',
                                    'position' => 999,
                                    'type' => 'product',
                                    'price' => [
                                        'totalPrice' => 0,
                                        'unitPrice' => 0,
                                        'quantity' => 1,
                                        'taxRules' => [],
                                        'calculatedTaxes' => [],
                                    ],
                                    'payload' => [
                                        'productNumber' => $product->getProductNumber(),
                                        'options' => [],
                                        'optionIds' => null,
                                    ],
                                    'meta' => [
                                        'parameters' => [],
                                    ],
                                    'quantity' => $item->getQuantity() * 1 * ($productRelatedItem['productQuantity'] ?? 1),//TODO configuration qty
                                ],
                            ],
                        ]], $event->getContext());
                    }
                }
            }

            if ($configService->isCreateOrderLineItemsForItemsWithNumber() || $configService->isCreateOrderLineItemsForAllItems()) {
                if ($configurationDetails['csvKeyValue']) {
                    $configuratorItemCount++;

                    $this->container->get('order_line_item.repository')->update([
                        [
                            'id' => $item->getId(),
                            'label' => $item->getLabel() . ' (#' . str_pad((string)$configuratorItemCount, 3, '0', STR_PAD_LEFT) . ')',
                        ],
                    ], $event->getContext());

                    $data = $this->createArrayFromCsv($configurationDetails['csvKeyValue']);
                    $positionNumber = 0;
                    foreach ($data as $configurationItemData) {
                        if ($configService->isCreateOrderLineItemsForAllItems() || $configurationItemData['item_number']) {
                            $orderRepository->update([[
                                'id' => $order->getId(),
                                'lineItems' => [
                                    [
                                        'identifier' => 'gaia-' . uniqid(),
                                        'parentId' => $item->getId(),
                                        'label' => $configurationItemData['item'] .
                                            ($configurationItemData['input_value'] ? ': ' . $configurationItemData['input_value'] : '') .
                                            ($configurationItemData['file'] ? ': ' . basename($configurationItemData['file']) : '') .
                                            ' (#' . str_pad((string)$configuratorItemCount, 3, '0', STR_PAD_LEFT) . ')'

                                        ,
                                        //, //shows gaia URL to customers
                                        'position' => $positionNumber++,
                                        'type' => 'custom',
                                        'price' => [
                                            'totalPrice' => 0,
                                            'unitPrice' => 0,
                                            'quantity' => 1,
                                            'taxRules' => [],
                                            'calculatedTaxes' => [],
                                        ],
                                        'payload' => (
                                            [
                                                'productNumber' => $configurationItemData['item_number'] ?? '',
                                                'options' => [],
                                                'optionIds' => null,
                                                'configuration_group' => $configurationItemData['group'] ?? '',
                                                'configuration_group_id' => $configurationItemData['group_id'] ?? '',
                                                'configuration_item' => $configurationItemData['item'] ?? '',
                                                'configuration_item_id' => $configurationItemData['item_id'] ?? '',
                                                'configuration_item_number' => $configurationItemData['item_number'] ?? '',
                                            ]
                                            + ($configurationItemData['file'] ?? null ? ['configuration_file' => $configurationItemData['file']] : [])
                                            //adding type=textarea and value=configuration_text to payload as "Custom Products" does for better ERP integration
                                            + ($configurationItemData['input_value'] ?? null ? ['type' => 'textarea', 'value' => $configurationItemData['input_value'], 'configuration_text' => $configurationItemData['input_value']] : [])
                                        )
                                        ,
                                        'meta' => [
                                            'parameters' => [],
                                        ],
                                        'quantity' => $item->getQuantity() * 1,//TODO configuration qty
                                    ],
                                ],
                            ]], $event->getContext());
                        }
                    }
                }
            }
        }


        if ($additionalComment) {
            $orderRepository->update([[
                'id' => $order->getId(),
                'customerComment' => trim($order->getCustomerComment() . "\n" . $additionalComment),
            ]], $event->getContext());
        }
    }

    protected function overrideItemPayload(OrderLineItemEntity $item, ConfigService $configService, Context $context): void
    {
        $itemPayload = $item->getPayload() ?? [];
        $isChanged = false;
        $configurationDetails = $itemPayload['gaiaConfigurationDetails'] ?? null;
        if (!$configurationDetails || !is_array($configurationDetails)) {
            return;
        }
        if ($configService->isOverrideArticleNumber() && !empty($configurationDetails['productNumber'])) {
            $itemPayload['productNumber'] = $configurationDetails['productNumber'];
            $isChanged = true;
        }

        if ($configService->isCreateOptions()) {
            $this->addOptionsToPayload($itemPayload, $configurationDetails);
            $isChanged = true;
        }


        if ($isChanged) {
            $this->container->get('order_line_item.repository')->update([
                [
                    'id' => $item->getId(),
                    'type' => 'custom',
                    'payload' => $itemPayload,
                ],
            ], $context);
        }
    }

    protected function createArrayFromCsv(string $csvString)
    {

        $csv = fopen('php://memory', 'x+');
        fwrite($csv, $csvString);
        rewind($csv);
        $head = null;
        $data = [];
        while ($line = fgetcsv($csv, 0, ';')) {
            if (!$head) {
                $head = $line;
            } else {
                if (count($line) === count($head)) {
                    $data[] = array_combine($head, $line);
                }
            }
        }
        return $data;
    }

    protected function addOptionsToPayload(array &$itemPayload, array $configurationDetails): void
    {
        $options = $itemPayload['options'] ?? [];

        if ($configurationDetails['csvKeyValue']) {
            $data = $this->createArrayFromCsv($configurationDetails['csvKeyValue']);
            foreach ($data as $configurationItemData) {
                $options[] = [
                    'group' => $configurationItemData['group'],
                    'option' => $configurationItemData['item'] . ($configurationItemData['input_value'] ? ': ' . $configurationItemData['input_value'] : ''),
                ];

            }
        }
        $itemPayload['options'] = $options;
    }

    protected function getItemListPlainTextCondensed(array $configurationDetails)
    {
        $itemListPlainText = '';
        if ($configurationDetails['csvKeyValue']) {
            $data = $this->createArrayFromCsv($configurationDetails['csvKeyValue']);
            foreach ($data as $configurationItemData) {
                $itemListPlainText .= $configurationItemData['group'] . ': ' . $configurationItemData['item'] . ($configurationItemData['input_value'] ? ': ' . $configurationItemData['input_value'] : '') . "\n";
            }
        }
        return $itemListPlainText;
    }


}
