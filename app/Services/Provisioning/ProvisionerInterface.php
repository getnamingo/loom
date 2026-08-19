<?php
declare(strict_types=1);

namespace App\Services\Provisioning;

interface ProvisionerInterface
{
    /**
     * Service type prefixes handled by this provisioner.
     *
     * An order service type uses the `<type>.<action>` format, for example
     * `domain.register` or `server.create`.
     *
     * @return list<string>
     */
    public function serviceTypes(): array;

    /**
     * Provision one order and return the affected service ID, when available.
     *
     * The caller owns the database transaction and the order status update.
     *
     * @param array<string, mixed> $order
     * @param array<string, mixed> $serviceData
     */
    public function provision(
        string $serviceType,
        string $action,
        array $order,
        array $serviceData
    ): ?int;
}
