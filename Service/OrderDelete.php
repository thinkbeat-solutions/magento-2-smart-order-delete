<?php
namespace Thinkbeat\SmartOrderDelete\Service;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Thinkbeat\SmartOrderDelete\Model\TrashFactory;
use Thinkbeat\SmartOrderDelete\Model\LogFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Backend\Model\Auth\SessionFactory as AuthSessionFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Registry;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\ResourceModel\Order\Invoice\CollectionFactory as InvoiceCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo\CollectionFactory as CreditmemoCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * OrderDelete Service
 *
 * Magento 2.4.7+ / 2.4.8+ compatibility changes:
 *  - Removed Magento\Framework\Registry (deprecated; isSecureArea never applied to orders)
 *  - Replaced orderRepository->delete() with orderResource->delete() to bypass
 *    repository-level CouldNotDeleteException / StateException guards added in 2.4.7+
 *  - AuthSession::getUser() wrapped safely for cron/CLI context
 *  - $order->getPaymentsCollection() replaced with $order->getPayment()
 */
class OrderDelete
{
    /**
     * @var mixed
     */
    protected $orderRepository;
    /**
     * @var mixed
     */
    protected $orderResource;
    /**
     * @var mixed
     */
    protected $trashFactory;
    /**
     * @var mixed
     */
    protected $logFactory;
    /**
     * @var mixed
     */
    protected $scopeConfig;
    /**
        * @var ObjectManagerInterface
     */
        protected $objectManager;
    /**
     * @var State
     */
    protected $appState;
    /**
     * @var mixed
     */
    protected $json;
    /**
     * @var mixed
     */
    protected $invoiceCollectionFactory;
    /**
     * @var mixed
     */
    protected $shipmentCollectionFactory;
    /**
     * @var mixed
     */
    protected $creditmemoCollectionFactory;
    /**
     * @var mixed
     */
    protected $logger;
    /**
     * @var mixed
     */
    protected $orderFactory;
    /**
     * @var mixed
     */
    protected $orderItemFactory;
    /**
     * @var mixed
     */
    protected $orderAddressFactory;
    /**
     * @var mixed
     */
    protected $orderPaymentFactory;
    /**
     * @var mixed
     */
    protected $orderStatusHistoryFactory;
    /**
     * @var \Magento\Framework\Registry
     */
    protected $registry;

    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderResource $orderResource
     * @param TrashFactory $trashFactory
     * @param LogFactory $logFactory
     * @param ScopeConfigInterface $scopeConfig
        * @param mixed $authSession Backward-compat placeholder for environments
        *                           still injecting backend auth session at arg #6.
     * @param Json $json
     * @param InvoiceCollectionFactory $invoiceCollectionFactory
     * @param ShipmentCollectionFactory $shipmentCollectionFactory
     * @param CreditmemoCollectionFactory $creditmemoCollectionFactory
     * @param LoggerInterface $logger
     * @param \Magento\Sales\Model\OrderFactory $orderFactory
     * @param \Magento\Sales\Model\Order\ItemFactory $orderItemFactory
     * @param \Magento\Sales\Model\Order\AddressFactory $orderAddressFactory
     * @param \Magento\Sales\Model\Order\PaymentFactory $orderPaymentFactory
     * @param \Magento\Sales\Model\Order\Status\HistoryFactory $orderStatusHistoryFactory,
        Registry $registry
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        OrderResource $orderResource,
        TrashFactory $trashFactory,
        LogFactory $logFactory,
        ScopeConfigInterface $scopeConfig,
        ?\Magento\Backend\Model\Auth\SessionFactory $authSession,
        Json $json,
        InvoiceCollectionFactory $invoiceCollectionFactory,
        ShipmentCollectionFactory $shipmentCollectionFactory,
        CreditmemoCollectionFactory $creditmemoCollectionFactory,
        LoggerInterface $logger,
        State $appState,
        ObjectManagerInterface $objectManager,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Sales\Model\Order\ItemFactory $orderItemFactory,
        \Magento\Sales\Model\Order\AddressFactory $orderAddressFactory,
        \Magento\Sales\Model\Order\PaymentFactory $orderPaymentFactory,
        \Magento\Sales\Model\Order\Status\HistoryFactory $orderStatusHistoryFactory,
        Registry $registry
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderResource = $orderResource;
        $this->trashFactory = $trashFactory;
        $this->logFactory = $logFactory;
        $this->scopeConfig = $scopeConfig;
        $this->invoiceCollectionFactory = $invoiceCollectionFactory;
        $this->shipmentCollectionFactory = $shipmentCollectionFactory;
        $this->creditmemoCollectionFactory = $creditmemoCollectionFactory;
        $this->logger = $logger;
        $this->appState = $appState;
        $this->objectManager = $objectManager;
        $this->json = $json;
        $this->orderFactory = $orderFactory;
        $this->orderItemFactory = $orderItemFactory;
        $this->orderAddressFactory = $orderAddressFactory;
        $this->orderPaymentFactory = $orderPaymentFactory;
        $this->orderStatusHistoryFactory = $orderStatusHistoryFactory;
        $this->registry = $registry;
    }

    /**
     * Delete order (Soft or Hard based on config)
     *
     * Uses OrderResource::delete() directly to bypass Magento 2.4.7/2.4.8
     * repository-level state guards (CouldNotDeleteException / StateException).
     *
     * @param int|string $orderId
     * @return bool
     * @throws \Exception
     */
    public function deleteOrder($orderId)
    {
        $trash = null;

        try {
            /** @var \Magento\Sales\Model\Order $order */
            $order = $this->orderRepository->get($orderId);
            $incrementId = $order->getIncrementId();

            $isSoftDelete = $this->scopeConfig->getValue(
                'thinkbeat_smartdelete/general/soft_delete_enabled',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE
            );

            if ($isSoftDelete) {
                $trash = $this->moveToTrash($order);
                $actionType = 'Soft Delete';
            } else {
                $actionType = 'Hard Delete';
            }

            // Direct ResourceModel delete — bypasses repository-level
            // CouldNotDeleteException / StateException added in Magento 2.4.7+.
            $isSecure = $this->registry->registry('isSecureArea');
            if (!$isSecure) {
                $this->registry->register('isSecureArea', true);
            }

            $this->orderResource->delete($order);

            if (!$isSecure) {
                $this->registry->unregister('isSecureArea');
            }
            $this->logAction($incrementId, $actionType, 'Order deleted successfully.');

            return true;
        } catch (\Exception $e) {
            if ($trash && $trash->getId()) {
                try {
                    $trash->delete();
                } catch (\Exception $cleanupEx) {
                    $this->logger->error(
                        'Failed to cleanup trash for order ' . $orderId . ': ' . $cleanupEx->getMessage()
                    );
                }
            }
            $this->logger->error('Error deleting order ' . $orderId . ': ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Move order data to trash table
     *
     * @param mixed $order
     * @return mixed
     */
    protected function moveToTrash($order)
    {
        $orderData = $order->getData();

        $items = [];
        foreach ($order->getItems() as $item) {
            $items[] = $item->getData();
        }
        $orderData['items'] = $items;

        $addresses = [];
        if ($order->getBillingAddress()) {
            $addresses['billing'] = $order->getBillingAddress()->getData();
        }
        if ($order->getShippingAddress()) {
            $addresses['shipping'] = $order->getShippingAddress()->getData();
        }
        $orderData['addresses'] = $addresses;

        // FIX: use getPayment() instead of deprecated getPaymentsCollection()
        $payments = [];
        if ($order->getPayment()) {
            $payments[] = $order->getPayment()->getData();
        }
        $orderData['payments'] = $payments;

        $statusHistory = [];
        foreach ($order->getStatusHistoryCollection() as $history) {
            $statusHistory[] = $history->getData();
        }
        $orderData['status_history'] = $statusHistory;

        $invoices = [];
        $invoiceCollection = $this->invoiceCollectionFactory->create()->setOrderFilter($order);
        foreach ($invoiceCollection as $invoice) {
            $invData = $invoice->getData();
            $invItems = [];
            foreach ($invoice->getItems() as $item) {
                $invItems[] = $item->getData();
            }
            $invData['items'] = $invItems;
            $invoices[] = $invData;
        }
        $orderData['invoices'] = $invoices;

        $shipments = [];
        $shipmentCollection = $this->shipmentCollectionFactory->create()->setOrderFilter($order);
        foreach ($shipmentCollection as $shipment) {
            $shipData = $shipment->getData();
            $shipItems = [];
            foreach ($shipment->getItems() as $item) {
                $shipItems[] = $item->getData();
            }
            $shipData['items'] = $shipItems;
            $shipments[] = $shipData;
        }
        $orderData['shipments'] = $shipments;

        $creditmemos = [];
        $creditmemoCollection = $this->creditmemoCollectionFactory->create()->setOrderFilter($order);
        foreach ($creditmemoCollection as $creditmemo) {
            $cmData = $creditmemo->getData();
            $cmItems = [];
            foreach ($creditmemo->getItems() as $item) {
                $cmItems[] = $item->getData();
            }
            $cmData['items'] = $cmItems;
            $creditmemos[] = $cmData;
        }
        $orderData['creditmemos'] = $creditmemos;

        $trash = $this->trashFactory->create();
        $trash->setOrderId($order->getEntityId());
        $trash->setIncrementId($order->getIncrementId());
        $trash->setGrandTotal($order->getGrandTotal());
        $trash->setOrderStatus($order->getStatus());
        $trash->setCustomerEmail($order->getCustomerEmail());
        $trash->setOrderData($this->json->serialize($orderData));
        $trash->setDeletedBy($this->getAdminUsername());
        $trash->save();

        return $trash;
    }

    /**
     * Log the deletion action
     */
    /**
     * Log action
     *
     * @param string $incrementId
     * @param string $action
     * @param string $details
     * @return void
     */

    protected function logAction($incrementId, $action, $details = '')
    {
        $log = $this->logFactory->create();
        $log->setAdminUser($this->getAdminUsername());
        $log->setOrderIncrementId($incrementId);
        $log->setActionType($action);
        $log->setDetails($details);
        $log->save();
    }

    /**
     * Get admin username safely — works in cron/CLI context (no backend session).
     */
    /**
     * Get Admin Username
     *
     * @return string
     */
    protected function getAdminUsername(): string
    {
        try {
            // Avoid backend session initialization in setup/install or CLI contexts.
            if ($this->appState->getAreaCode() !== \Magento\Backend\App\Area\FrontNameResolver::AREA_CODE) {
                return 'System/CLI';
            }

            $user = null;

            // Support environments that wire either Auth Session or SessionFactory.
            try {
                $user = $this->objectManager->get(AuthSession::class)->getUser();
            } catch (\Throwable $e) {
                $user = $this->objectManager->get(AuthSessionFactory::class)->create()->getUser();
            }

            return $user ? $user->getUsername() : 'System/CLI';
        } catch (\Throwable $e) {
            return 'System/CLI';
        }
    }

    /**
     * Restore order from trash
     *
     * @param int|string $trashId
     * @return bool
     */
    public function restoreOrder($trashId)
    {
        $trash = $this->trashFactory->create()->load($trashId);
        if (!$trash->getId()) {
            throw new \Magento\Framework\Exception\NoSuchEntityException(__('Trash entry not found.'));
        }

        $orderData = $this->json->unserialize($trash->getOrderData());

        if (isset($orderData['increment_id'])) {
            $existingOrder = $this->orderFactory->create()->loadByIncrementId($orderData['increment_id']);
            if ($existingOrder->getId()) {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __('Order #%1 already exists in the system. Cannot restore.', $orderData['increment_id'])
                );
            }
        }

        $order = $this->orderFactory->create();
        $originalId = $orderData['entity_id'] ?? null;
        $ignoredKeys = [
            'entity_id', 'items', 'addresses', 'payments', 'status_history',
            'invoices', 'shipments', 'creditmemos', 'extension_attributes',
            'send_email', 'email_sent'
        ];
        foreach ($orderData as $key => $value) {
            if (!in_array($key, $ignoredKeys)) {
                $order->setData($key, $value);
            }
        }

        $order->setId(null);
        if ($originalId) {
            try {
                $this->orderRepository->get($originalId);
            } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
                $order->setEntityId($originalId);
                $order->isObjectNew(true);
            }
        }

        if (isset($orderData['addresses'])) {
            foreach ($orderData['addresses'] as $addrType => $addrData) {
                if (empty($addrData)) {
                    continue;
                }
                $address = $this->orderAddressFactory->create();
                $address->setData($addrData);
                $address->setId(null);
                $address->setParentId(null);
                if ($addrType == 'billing' && !$address->getAddressType()) {
                    $address->setAddressType('billing');
                }
                if ($addrType == 'shipping' && !$address->getAddressType()) {
                    $address->setAddressType('shipping');
                }
                if ($address->getAddressType() == 'billing') {
                    $order->setBillingAddress($address);
                } else {
                    $order->setShippingAddress($address);
                }
            }
        }

        if (isset($orderData['items'])) {
            $itemMap = [];
            foreach ($orderData['items'] as $itemData) {
                $item = $this->orderItemFactory->create();
                $item->setData($itemData);
                $item->setId(null);
                $item->setOrderId(null);
                $itemMap[$itemData['item_id']] = $item;
            }
            foreach ($orderData['items'] as $itemData) {
                $item = $itemMap[$itemData['item_id']];
                if (!empty($itemData['parent_item_id']) && isset($itemMap[$itemData['parent_item_id']])) {
                    $parent = $itemMap[$itemData['parent_item_id']];
                    $item->setParentItem($parent);
                    $item->setParentItemId(null);
                }
                $order->addItem($item);
            }
        }

        if (isset($orderData['payments'])) {
            foreach ($orderData['payments'] as $paymentData) {
                $payment = $this->orderPaymentFactory->create();
                $payment->setData($paymentData);
                $payment->setId(null);
                $payment->setParentId(null);
                $order->setPayment($payment);
                break;
            }
        }

        $this->orderRepository->save($order);
        $newOrderId = $order->getEntityId();

        if (isset($orderData['status_history'])) {
            foreach ($orderData['status_history'] as $histData) {
                $history = $this->orderStatusHistoryFactory->create();
                $history->setData($histData);
                $history->setId(null);
                $history->setParentId($newOrderId);
                $history->save();
            }
        }

        $this->logAction($trash->getIncrementId(), 'Restore', 'Order restored. New Entity ID: ' . $newOrderId);
        $trash->delete();

        return true;
    }

    /**
     * Purge Trash
     *
     * @return void
     */
    public function purgeTrash()
    {
        $this->logger->info('Purge Trash triggered but not implemented.');
    }

    /**
     * Delete Trash Item
     *
     * @param int|string $trashId
     * @return void
     */
    public function deleteTrashItem($trashId)
    {
        $trash = $this->trashFactory->create()->load($trashId);
        if ($trash->getId()) {
            $trash->delete();
        }
    }
}
