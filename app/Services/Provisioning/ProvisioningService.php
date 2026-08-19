<?php
declare(strict_types=1);

namespace App\Services\Provisioning;

use Pinga\Db\PdoDatabase;

final class ProvisioningService
{
    private const ELIGIBLE_ORDER_STATUSES = ['pending', 'failed', 'inactive'];

    /** @var array<string, ProvisionerInterface> */
    private array $provisioners = [];

    /**
     * @param iterable<ProvisionerInterface> $provisioners
     */
    public function __construct(
        private readonly PdoDatabase $db,
        iterable $provisioners
    ) {
        foreach ($provisioners as $provisioner) {
            $this->register($provisioner);
        }

        if ($this->provisioners === []) {
            throw new \InvalidArgumentException('At least one provisioner must be registered.');
        }
    }

    public static function createDefault(PdoDatabase $db): self
    {
        return new self($db, [
            new DomainProvisioner($db),
            new PlaceholderProvisioner([
                'dns',
                'server',
                'hosting',
                'cloud',
                'cloud-hosting',
                'vps',
            ]),
        ]);
    }

    /**
     * Provision every eligible order on a paid invoice.
     */
    public function provisionInvoice(int $invoiceId, int $actorId = 0): void
    {
        $invoice = $this->db->selectRow(
            'SELECT id, user_id, payment_status FROM invoices WHERE id = ? LIMIT 1',
            [$invoiceId]
        );

        if (!$invoice) {
            throw new \RuntimeException("Invoice {$invoiceId} was not found.");
        }

        if (($invoice['payment_status'] ?? null) !== 'paid') {
            throw new \LogicException("Invoice {$invoiceId} must be paid before provisioning.");
        }

        $orders = $this->db->select(
            'SELECT id
             FROM orders
             WHERE invoice_id = ?
               AND user_id = ?
               AND status IN (?, ?)
             ORDER BY id ASC',
            [$invoiceId, $invoice['user_id'], 'pending', 'failed']
        ) ?? [];

        $failures = [];
        $firstFailure = null;

        foreach ($orders as $order) {
            try {
                $this->provisionOrder((int)$order['id'], $actorId);
            } catch (\Throwable $exception) {
                $firstFailure ??= $exception;
                $failures[] = sprintf('#%d: %s', (int)$order['id'], $exception->getMessage());
            }
        }

        if ($failures !== []) {
            throw new \RuntimeException(
                'Provisioning failed for order(s) ' . implode('; ', $failures),
                0,
                $firstFailure
            );
        }
    }

    /**
     * Provision one paid order.
     *
     * Returns false when the order is already final or was completed by another
     * request before this one acquired the transaction.
     */
    public function provisionOrder(int $orderId, int $actorId = 0): bool
    {
        $order = $this->loadOrder($orderId);

        if (!$order || !in_array($order['status'], self::ELIGIBLE_ORDER_STATUSES, true)) {
            return false;
        }

        if (($order['payment_status'] ?? null) !== 'paid') {
            throw new \LogicException("Order {$orderId} cannot be provisioned before its invoice is paid.");
        }

        $transactionStarted = false;

        try {
            $this->db->beginTransaction();
            $transactionStarted = true;

            // Re-read after opening the transaction so repeated webhook and retry
            // calls can observe an order completed by an earlier request.
            $order = $this->loadOrder($orderId);
            if (!$order || !in_array($order['status'], self::ELIGIBLE_ORDER_STATUSES, true)) {
                $this->db->commit();
                return false;
            }

            [$serviceType, $action] = $this->parseServiceType((string)$order['service_type']);
            $serviceData = $this->decodeServiceData($order['service_data'] ?? null, $orderId);

            $existingServiceId = $this->db->selectValue(
                'SELECT id FROM services WHERE order_id = ? LIMIT 1',
                [$orderId]
            );

            if ($existingServiceId) {
                $this->db->update('orders', ['status' => 'active'], ['id' => $orderId]);
                $this->recordLog(
                    (int)$existingServiceId,
                    'provision_reconciled',
                    $actorId,
                    $orderId,
                    $serviceType,
                    $action
                );
                $this->db->commit();
                return true;
            }

            $provisioner = $this->provisioners[$serviceType] ?? null;
            if (!$provisioner) {
                throw new \LogicException(
                    "No provisioning adapter is registered for service type \"{$serviceType}\"."
                );
            }

            $serviceId = $provisioner->provision(
                $serviceType,
                $action,
                $order,
                $serviceData
            );

            $this->db->update('orders', ['status' => 'active'], ['id' => $orderId]);
            $this->recordLog(
                $serviceId ?? 0,
                $action === 'renew' ? 'renewed' : 'provisioned',
                $actorId,
                $orderId,
                $serviceType,
                $action
            );

            $this->db->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($transactionStarted) {
                try {
                    $this->db->rollBack();
                } catch (\Throwable) {
                    // Preserve the original provisioning exception.
                }
            }

            $this->recordFailure($orderId, $actorId, $exception);
            throw $exception;
        }
    }

    private function register(ProvisionerInterface $provisioner): void
    {
        foreach ($provisioner->serviceTypes() as $serviceType) {
            $serviceType = strtolower(trim($serviceType));

            if ($serviceType === '') {
                throw new \InvalidArgumentException('Provisioner service types cannot be empty.');
            }

            if (isset($this->provisioners[$serviceType])) {
                throw new \LogicException(
                    "A provisioning adapter is already registered for \"{$serviceType}\"."
                );
            }

            $this->provisioners[$serviceType] = $provisioner;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadOrder(int $orderId): ?array
    {
        $order = $this->db->selectRow(
            'SELECT o.id, o.user_id, o.invoice_id, o.service_type, o.service_data,
                    o.status, i.payment_status
             FROM orders o
             LEFT JOIN invoices i ON i.id = o.invoice_id
             WHERE o.id = ?
             LIMIT 1',
            [$orderId]
        );

        return $order ?: null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseServiceType(string $value): array
    {
        $parts = explode('.', strtolower(trim($value)), 2);
        $serviceType = trim($parts[0] ?? '');
        $action = trim($parts[1] ?? 'create');

        if ($serviceType === '' || $action === '') {
            throw new \UnexpectedValueException("Invalid order service type \"{$value}\".");
        }

        return [$serviceType, $action];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeServiceData(mixed $value, int $orderId): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \UnexpectedValueException(
                "Order {$orderId} contains invalid service data.",
                0,
                $exception
            );
        }

        if (!is_array($decoded)) {
            throw new \UnexpectedValueException("Order {$orderId} service data must be a JSON object.");
        }

        return $decoded;
    }

    private function recordLog(
        int $serviceId,
        string $event,
        int $actorId,
        int $orderId,
        string $serviceType,
        string $action
    ): void {
        $this->db->insert('service_logs', [
            'service_id' => $serviceId,
            'event' => $event,
            'actor_type' => 'system',
            'actor_id' => $actorId,
            'details' => json_encode([
                'order_id' => $orderId,
                'service_type' => $serviceType,
                'action' => $action,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => $this->now(),
        ]);
    }

    private function recordFailure(int $orderId, int $actorId, \Throwable $exception): void
    {
        try {
            $this->db->exec(
                'UPDATE orders
                 SET status = ?
                 WHERE id = ?
                   AND status IN (?, ?, ?)',
                ['failed', $orderId, 'pending', 'failed', 'inactive']
            );

            $this->db->insert('service_logs', [
                'service_id' => 0,
                'event' => 'provision_failed',
                'actor_type' => 'system',
                'actor_id' => $actorId,
                'details' => "order {$orderId}|" . $exception->getMessage(),
                'created_at' => $this->now(),
            ]);
        } catch (\Throwable $loggingException) {
            error_log(sprintf(
                'Could not persist provisioning failure for order %d: %s',
                $orderId,
                $loggingException->getMessage()
            ));
        }
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
    }
}
