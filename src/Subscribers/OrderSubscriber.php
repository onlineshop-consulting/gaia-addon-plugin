<?php declare(strict_types=1);

    namespace OncoSfcAddon\Subscribers;

    use OncoSfcAddon\Services\ConfigService;
    use Psr\Log\LoggerInterface;
    use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
    use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
    use Shopware\Core\Content\Product\ProductEntity;
    use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
    use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
    use Shopware\Core\Framework\Uuid\Uuid;
    use Symfony\Component\EventDispatcher\EventSubscriberInterface;

    class OrderSubscriber implements EventSubscriberInterface
    {
        public function __construct(
            private readonly ConfigService    $configService,
            private readonly EntityRepository $orderRepository,
            private readonly EntityRepository $productRepository,
            private readonly EntityRepository $orderLineItemRepository,
            private readonly LoggerInterface  $logger,
        )
        {
        }

        public static function getSubscribedEvents(): array
        {
            return [
                CheckoutOrderPlacedEvent::class => 'orderPlaced',
            ];
        }

        public function orderPlaced(CheckoutOrderPlacedEvent $event): void
        {
            $context = $event->getContext();
            $order = $event->getOrder();
            $additionalComment = '';

            $configuratorItemCount = 0;

            $allChildLineItems = [];
            $lineItemUpdates = [];
            $productIdsToLoad = [];

            // First pass: collect all productIds for a single batched product lookup (C4).
            foreach ($order->getLineItems() ?? [] as $item) {
                $itemPayload = $item->getPayload() ?? [];
                $configurationDetails = $itemPayload['gaiaConfigurationDetails'] ?? null;
                if (!is_array($configurationDetails) || $configurationDetails === []) {
                    continue;
                }
                if (!empty($itemPayload['gaiaProcessed'])) {
                    continue;
                }
                if (!$this->configService->isCreateOrderLineItemsForProducts()) {
                    continue;
                }
                $productRelatedItems = $configurationDetails['productRelatedItems'] ?? null;
                if (!is_array($productRelatedItems)) {
                    continue;
                }
                foreach ($productRelatedItems as $productRelatedItem) {
                    if (!is_array($productRelatedItem)) {
                        continue;
                    }
                    $productId = $productRelatedItem['productId'] ?? null;
                    if (is_string($productId) && $productId !== '') {
                        $productIdsToLoad[$productId] = true;
                    }
                }
            }

            $productCollection = null;
            if ($productIdsToLoad !== []) {
                $productCollection = $this->productRepository
                    ->search(new Criteria(array_keys($productIdsToLoad)), $context)
                    ->getEntities();
            }


            /** @var OrderLineItemEntity $item */
            foreach ($order->getLineItems() ?? [] as $item) {
                $itemPayload = $item->getPayload() ?? [];
                $configurationDetails = $itemPayload['gaiaConfigurationDetails'] ?? null;
                if (!is_array($configurationDetails) || $configurationDetails === []) {
                    continue;
                }

                // Idempotency guard (C2): skip items that have already been processed.
                if (!empty($itemPayload['gaiaProcessed'])) {
                    continue;
                }

                $itemListPlainText = $configurationDetails['itemListPlainText'] ?? '';
                if (!is_string($itemListPlainText)) {
                    $itemListPlainText = '';
                }

                if ($this->configService->isWriteConfigurationListInOrderComment()) {
                    $additionalComment .= "\n" . 'Konfiguration' . (!empty($item->getLabel()) ? ' von "' . $item->getLabel() . '":' . "\n" : '') .
                        ($this->configService->isConfigurationListCondensed() ? $this->getItemListPlainTextCondensed($configurationDetails) : $itemListPlainText) .
                        "\n";
                }

                // Aggregate all updates for this parent into a single DAL payload below.
                $parentUpdate = [
                    'id' => $item->getId(),
                ];

                if ($this->configService->isWriteConfigurationListInDescription()) {
                    $parentUpdate['description'] = $this->configService->isConfigurationListCondensed()
                        ? $this->getItemListPlainTextCondensed($configurationDetails)
                        : $itemListPlainText;
                }

                // Build payload overrides (article number / options).
                $overriddenPayload = $this->buildOverriddenPayload($item, $configurationDetails);
                $payloadForUpdate = $overriddenPayload ?? $itemPayload;
                if ($overriddenPayload !== null) {
                    $parentUpdate['type'] = 'custom';
                }

                // prevent the ProductLineItemCommandValidator getting confused
                if (isset($payloadForUpdate['productNumber'])) {
                    unset($payloadForUpdate['productNumber']);
                }


                if ($this->configService->isCreateOrderLineItemsForProducts()) {
                    $productRelatedItems = $configurationDetails['productRelatedItems'] ?? null;
                    if (is_array($productRelatedItems) && $productCollection !== null) {
                        foreach ($productRelatedItems as $productRelatedItem) {
                            if (!is_array($productRelatedItem)) {
                                continue;
                            }
                            $productId = $productRelatedItem['productId'] ?? null;
                            if (!is_string($productId) || $productId === '') {
                                continue;
                            }
                            /** @var ProductEntity $product */
                            $product = $productCollection->get($productId);
                            if ($product === null) {
                                continue;
                            }

                            $allChildLineItems[] = [
                                'id' => Uuid::randomHex(),
                                'orderId' => $order->getId(),
                                'identifier' => 'gaia-' . Uuid::randomHex() . '-' . $product->getId(),
                                'parentId' => $item->getId(),
                                'productId' => $product->getId(),
                                'referencedId' => $product->getId(),
                                'label' => $product->getName() ?: ($product->getProductNumber() ?: '-'),
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
                                'quantity' => $item->getQuantity() * 1 * (int)($productRelatedItem['productQuantityEvaluated'] ?? $productRelatedItem['productQuantity'] ?? 1),
                            ];
                        }
                    }
                }

                if ($this->configService->isCreateOrderLineItemsForItemsWithNumber() || $this->configService->isCreateOrderLineItemsForAllItems()) {
                    $csvKeyValue = $configurationDetails['csvKeyValue'] ?? null;
                    if (is_string($csvKeyValue) && $csvKeyValue !== '') {
                        $configuratorItemCount++;
                        $currentLabel = $item->getLabel() ?? '';
                        if (!preg_match('/\(#\d{3}\)$/', $currentLabel)) {
                            $parentUpdate['label'] = $currentLabel . ' (#' . str_pad((string)$configuratorItemCount, 3, '0', STR_PAD_LEFT) . ')';
                        }

                        $data = $this->createArrayFromCsv($csvKeyValue);
                        $positionNumber = 0;
                        foreach ($data as $configurationItemData) {
                            $hasItemNumber = !empty($configurationItemData['item_number']);
                            if ($this->configService->isCreateOrderLineItemsForAllItems() || $hasItemNumber) {
                                $inputValue = $configurationItemData['input_value'] ?? '';
                                $file = $configurationItemData['file'] ?? '';
                                $childPayload = [
                                    'productNumber' => $configurationItemData['item_number'] ?? '',
                                    'options' => [],
                                    'optionIds' => null,
                                    'configuration_group' => $configurationItemData['group'] ?? '',
                                    'configuration_group_id' => $configurationItemData['group_id'] ?? '',
                                    'configuration_item' => $configurationItemData['item'] ?? '',
                                    'configuration_item_id' => $configurationItemData['item_id'] ?? '',
                                    'configuration_item_number' => $configurationItemData['item_number'] ?? '',
                                ];
                                if (!empty($file)) {
                                    $childPayload['configuration_file'] = $file;
                                }
                                if (!empty($inputValue)) {
                                    // adding type=textarea and value=configuration_text to payload as "Custom Products" does for better ERP integration
                                    $childPayload['type'] = 'textarea';
                                    $childPayload['value'] = $inputValue;
                                    $childPayload['configuration_text'] = $inputValue;
                                }

                                $allChildLineItems[] = [
                                    'id' => Uuid::randomHex(),
                                    'orderId' => $order->getId(),
                                    'identifier' => 'gaia-' . Uuid::randomHex(),
                                    'parentId' => $item->getId(),
                                    'label' => ($configurationItemData['item'] ?? '') .
                                        (!empty($inputValue) ? ': ' . $inputValue : '') .
                                        (!empty($file) ? ': ' . basename((string)$file) : '') .
                                        ' (#' . str_pad((string)$configuratorItemCount, 3, '0', STR_PAD_LEFT) . ')',
                                    'position' => $positionNumber++,
                                    'type' => 'custom',
                                    'price' => [
                                        'totalPrice' => 0,
                                        'unitPrice' => 0,
                                        'quantity' => 1,
                                        'taxRules' => [],
                                        'calculatedTaxes' => [],
                                    ],
                                    'payload' => $childPayload,
                                    'meta' => [
                                        'parameters' => [],
                                    ],
                                    'quantity' => $item->getQuantity() * 1,
                                ];
                            }
                        }
                    }
                }

                $payloadForUpdate['gaiaProcessed'] = true;
                $parentUpdate['payload'] = $payloadForUpdate;

                $lineItemUpdates[] = $parentUpdate;
            }


            if ($allChildLineItems !== []) {
                try {
                    $this->orderLineItemRepository->create($allChildLineItems, $context);
                } catch (\Throwable $e) {
                    $this->logger->error('OncoSfcAddon: failed to create child order line items', [
                        'orderId' => $order->getId(),
                        'exception' => $e,
                    ]);
                }
            }

            if ($lineItemUpdates !== []) {
                try {
                    $this->orderLineItemRepository->update($lineItemUpdates, $context);
                } catch (\Throwable $e) {
                    $this->logger->error('OncoSfcAddon: failed to update order line items', [
                        'orderId' => $order->getId(),
                        'exception' => $e,
                    ]);
                }
            }

            if ($additionalComment !== '') {
                try {
                    $this->orderRepository->update([[
                        'id' => $order->getId(),
                        'customerComment' => trim(($order->getCustomerComment() ?? '') . "\n" . $additionalComment),
                    ]], $context);
                } catch (\Throwable $e) {
                    $this->logger->error('OncoSfcAddon: failed to update order customer comment', [
                        'orderId' => $order->getId(),
                        'exception' => $e,
                    ]);
                }
            }
        }

        /**
         * Builds the payload override for a parent item. Returns null if no overrides apply.
         *
         * @param array<string, mixed> $configurationDetails
         * @return array<string, mixed>|null
         */
        protected function buildOverriddenPayload(OrderLineItemEntity $item, array $configurationDetails): ?array
        {
            $itemPayload = $item->getPayload() ?? [];
            $isChanged = false;

            if ($this->configService->isCreateOptions()) {
                $this->addOptionsToPayload($itemPayload, $configurationDetails);
                $isChanged = true;
            }

            return $isChanged ? $itemPayload : null;
        }

        /**
         * @return list<array<string, string>>
         */
        protected function createArrayFromCsv(string $csvString): array
        {
            $csv = fopen('php://memory', 'w+');
            if ($csv === false) {
                return [];
            }

            try {
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
            } finally {
                fclose($csv);
            }
        }

        /**
         * @param array<string, mixed> $itemPayload
         * @param array<string, mixed> $configurationDetails
         */
        protected function addOptionsToPayload(array &$itemPayload, array $configurationDetails): void
        {
            $options = $itemPayload['options'] ?? [];
            if (!is_array($options)) {
                $options = [];
            }

            $csvKeyValue = $configurationDetails['csvKeyValue'] ?? null;
            if (is_string($csvKeyValue) && $csvKeyValue !== '') {
                $data = $this->createArrayFromCsv($csvKeyValue);
                foreach ($data as $configurationItemData) {
                    $inputValue = $configurationItemData['input_value'] ?? '';
                    $options[] = [
                        'group' => $configurationItemData['group'] ?? '',
                        'option' => ($configurationItemData['item'] ?? '') . (!empty($inputValue) ? ': ' . $inputValue : ''),
                    ];
                }
            }
            $itemPayload['options'] = $options;
        }

        /**
         * @param array<string, mixed> $configurationDetails
         */
        protected function getItemListPlainTextCondensed(array $configurationDetails): string
        {
            $itemListPlainText = '';
            $csvKeyValue = $configurationDetails['csvKeyValue'] ?? null;
            if (is_string($csvKeyValue) && $csvKeyValue !== '') {
                $data = $this->createArrayFromCsv($csvKeyValue);
                foreach ($data as $configurationItemData) {
                    $inputValue = $configurationItemData['input_value'] ?? '';
                    $itemListPlainText .= ($configurationItemData['group'] ?? '') . ': ' . ($configurationItemData['item'] ?? '') . (!empty($inputValue) ? ': ' . $inputValue : '') . "\n";
                }
            }
            return $itemListPlainText;
        }
    }
