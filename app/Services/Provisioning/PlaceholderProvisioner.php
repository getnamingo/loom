<?php
declare(strict_types=1);

namespace App\Services\Provisioning;

final class PlaceholderProvisioner implements ProvisionerInterface
{
    /** @var list<string> */
    private array $serviceTypes;

    /**
     * @param list<string> $serviceTypes
     */
    public function __construct(array $serviceTypes)
    {
        $normalized = [];

        foreach ($serviceTypes as $serviceType) {
            $serviceType = strtolower(trim($serviceType));
            if ($serviceType !== '') {
                $normalized[] = $serviceType;
            }
        }

        $this->serviceTypes = array_values(array_unique($normalized));

        if ($this->serviceTypes === []) {
            throw new \InvalidArgumentException('At least one placeholder service type is required.');
        }
    }

    public function serviceTypes(): array
    {
        return $this->serviceTypes;
    }

    public function provision(
        string $serviceType,
        string $action,
        array $order,
        array $serviceData
    ): ?int {
        throw new \LogicException(sprintf(
            'Provisioning adapter for "%s.%s" is not configured yet.',
            $serviceType,
            $action
        ));
    }
}
