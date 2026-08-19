<?php
declare(strict_types=1);

namespace App\Services\Provisioning;

use Pinga\Db\PdoDatabase;

final class DomainProvisioner implements ProvisionerInterface
{
    public function __construct(private readonly PdoDatabase $db)
    {
    }

    public function serviceTypes(): array
    {
        return ['domain'];
    }

    public function provision(
        string $serviceType,
        string $action,
        array $order,
        array $serviceData
    ): ?int {
        if ($serviceType !== 'domain') {
            throw new \LogicException("DomainProvisioner cannot handle \"{$serviceType}\".");
        }

        return match ($action) {
            'register', 'create' => $this->registerDomain($order, $serviceData),
            'renew' => $this->renewDomain($order, $serviceData),
            'transfer' => throw new \LogicException(
                'Domain transfer provisioning is not implemented yet.'
            ),
            default => throw new \LogicException(
                "Unsupported domain provisioning action \"{$action}\"."
            ),
        };
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $serviceData
     */
    private function registerDomain(array $order, array $serviceData): int
    {
        $domainName = $this->requiredDomainName($serviceData);
        $years = max(1, (int)($serviceData['years'] ?? 1));
        $provider = $this->providerFor($domainName, 'registration');
        $registryType = \getRegistryExtensionByTld('.' . $provider['tld']);
        $epp = $this->connect($registryType, $provider);

        try {
            $authInfo = trim((string)($serviceData['authInfo'] ?? ''));
            if ($authInfo === '') {
                $authInfo = \generateAuthInfo();
                $serviceData['authInfo'] = $authInfo;
            }

            $roles = $provider['contact_roles'] ?? ['registrant', 'admin', 'tech', 'billing'];
            if (!is_array($roles) || $roles === []) {
                $roles = ['registrant', 'admin', 'tech', 'billing'];
            }

            $contactType = strtolower((string)($provider['contact_type'] ?? 'int'));
            if (!in_array($contactType, ['int', 'loc'], true)) {
                $contactType = 'int';
            }

            $roleContactIds = $this->createContacts(
                $epp,
                $roles,
                $contactType,
                $registryType,
                $serviceData
            );

            $domainParams = [
                'domainname' => $domainName,
                'period' => $years,
                'authInfoPw' => $authInfo,
            ];

            $nameservers = array_values(array_filter(
                array_map(
                    static fn (mixed $nameserver): string => trim((string)$nameserver),
                    is_array($serviceData['nameservers'] ?? null)
                        ? $serviceData['nameservers']
                        : []
                ),
                static fn (string $nameserver): bool => $nameserver !== ''
            ));

            if ($nameservers !== []) {
                $domainParams['nss'] = $nameservers;

                if (filter_var($provider['auto_create_hosts'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    foreach ($nameservers as $host) {
                        // Registries commonly return "already exists" during a retry;
                        // preserve the previous best-effort host creation behavior.
                        $epp->hostCreate(['hostname' => strtolower($host)]);
                    }
                }
            }

            if (!empty($roleContactIds['registrant'])) {
                $domainParams['registrant'] = $roleContactIds['registrant'];
            }

            $contacts = [];
            foreach (['admin', 'tech', 'billing'] as $role) {
                if (!empty($roleContactIds[$role])) {
                    $contacts[$role] = $roleContactIds[$role];
                }
            }
            if ($contacts !== []) {
                $domainParams['contacts'] = $contacts;
            }

            $domainParams = \extendParamsForRegistry(
                'domain',
                $domainParams,
                $registryType,
                $serviceData
            );
            $domainCreate = $epp->domainCreate($domainParams);
            if (!is_array($domainCreate)) {
                throw new \RuntimeException('DomainCreate returned an invalid response.');
            }
            $error = $this->responseError($domainCreate);
            if ($error !== null) {
                throw new \RuntimeException('DomainCreate Error: ' . $error);
            }
        } finally {
            $this->logout($epp);
        }

        $now = $this->now();
        $serviceData['authcode'] = $serviceData['authInfo'];
        $serviceData['status'] = $serviceData['status'] ?? ['ok'];
        $encodedConfig = json_encode(
            $serviceData,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        // Keep the registry contact IDs on the order as well as the service so a
        // later retry can reuse them instead of creating duplicate contacts.
        $this->db->update(
            'orders',
            ['service_data' => $encodedConfig],
            ['id' => (int)$order['id']]
        );

        $this->db->insert('services', [
            'user_id' => (int)$order['user_id'],
            'provider_id' => (int)$provider['provider_id'],
            'order_id' => (int)$order['id'],
            'type' => 'domain',
            'status' => 'active',
            'config' => $encodedConfig,
            'service_name' => $domainName,
            'registered_at' => $now,
            'expires_at' => isset($domainCreate['exDate'])
                ? $this->formatDate((string)$domainCreate['exDate'])
                : (new \DateTimeImmutable("+{$years} year"))->format('Y-m-d H:i:s.v'),
            'updated_at' => $now,
            'created_at' => isset($domainCreate['crDate'])
                ? $this->formatDate((string)$domainCreate['crDate'])
                : $now,
        ]);

        return (int)$this->db->getLastInsertId();
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $serviceData
     */
    private function renewDomain(array $order, array $serviceData): int
    {
        $domainName = $this->requiredDomainName($serviceData);
        $years = max(1, (int)($serviceData['years'] ?? 1));
        $service = $this->db->selectRow(
            'SELECT id
             FROM services
             WHERE service_name = ?
               AND user_id = ?
               AND type = ?
             LIMIT 1',
            [$domainName, (int)$order['user_id'], 'domain']
        );

        if (!$service) {
            throw new \RuntimeException("Domain service {$domainName} was not found for renewal.");
        }

        $provider = $this->providerFor($domainName, 'renewal');
        $registryType = \getRegistryExtensionByTld('.' . $provider['tld']);
        $epp = $this->connect($registryType, $provider);

        try {
            $domainRenew = $epp->domainRenew([
                'domainname' => $domainName,
                'regperiod' => $years,
            ]);

            if (!is_array($domainRenew)) {
                throw new \RuntimeException('DomainRenew returned an invalid response.');
            }
            $error = $this->responseError($domainRenew);
            if ($error !== null) {
                throw new \RuntimeException('DomainRenew Error: ' . $error);
            }
        } finally {
            $this->logout($epp);
        }

        $this->db->update('services', [
            'updated_at' => $this->now(),
            'expires_at' => isset($domainRenew['exDate'])
                ? $this->formatDate((string)$domainRenew['exDate'])
                : (new \DateTimeImmutable("+{$years} year"))->format('Y-m-d H:i:s.v'),
        ], [
            'id' => (int)$service['id'],
        ]);

        return (int)$service['id'];
    }

    /**
     * @param object $epp
     * @param list<string> $roles
     * @param array<string, mixed> $serviceData
     * @return array<string, string>
     */
    private function createContacts(
        object $epp,
        array $roles,
        string $contactType,
        string $registryType,
        array &$serviceData
    ): array {
        $roleContactIds = [];
        $fingerprints = [];

        if (!isset($serviceData['contacts']) || !is_array($serviceData['contacts'])) {
            $serviceData['contacts'] = [];
        }

        foreach ($roles as $role) {
            $role = (string)$role;
            $contact = $this->contactForRole($serviceData, $role);
            $registryId = trim((string)($contact['registry_id'] ?? ''));

            if ($registryId !== '') {
                $roleContactIds[$role] = $registryId;
                $fingerprints[$this->contactFingerprint($contact)] = $registryId;
            }
        }

        foreach ($roles as $role) {
            $role = (string)$role;
            if (isset($roleContactIds[$role])) {
                continue;
            }

            $contact = $this->contactForRole($serviceData, $role);

            if (
                $role !== 'registrant'
                && (empty($contact['name']) || empty($contact['email']))
                && !empty($roleContactIds['registrant'])
            ) {
                $roleContactIds[$role] = $roleContactIds['registrant'];
                $this->storeContactRegistryId(
                    $serviceData,
                    $role,
                    $roleContactIds['registrant']
                );
                continue;
            }

            $fingerprint = $this->contactFingerprint($contact);
            if (isset($fingerprints[$fingerprint])) {
                $roleContactIds[$role] = $fingerprints[$fingerprint];
                $this->storeContactRegistryId($serviceData, $role, $fingerprints[$fingerprint]);
                continue;
            }

            [$firstName, $lastName] = $this->splitName((string)($contact['name'] ?? 'John Doe'));
            $contactParams = [
                'id' => 'ct' . bin2hex(random_bytes(4)),
                'type' => $contactType,
                'firstname' => $firstName,
                'lastname' => $lastName,
                'companyname' => $contact['org'] ?? '',
                'address1' => $contact['street1'] ?? '',
                'address2' => $contact['street2'] ?? '',
                'city' => $contact['city'] ?? '',
                'state' => $contact['sp'] ?? '',
                'postcode' => $contact['pc'] ?? '',
                'country' => strtoupper((string)($contact['cc'] ?? 'XX')),
                'fullphonenumber' => $contact['voice'] ?? '',
                'email' => $contact['email'] ?? '',
                'authInfoPw' => $serviceData['authInfo'],
            ];

            $contactParams = \extendParamsForRegistry(
                'contact',
                $contactParams,
                $registryType,
                $serviceData
            );
            $response = $epp->contactCreate($contactParams);
            if (!is_array($response)) {
                throw new \RuntimeException("ContactCreate ({$role}) returned an invalid response.");
            }
            $error = $this->responseError($response);
            if ($error !== null) {
                throw new \RuntimeException("ContactCreate ({$role}) Error: {$error}");
            }

            $contactId = trim((string)($response['id'] ?? ''));
            if ($contactId === '') {
                throw new \RuntimeException("ContactCreate ({$role}) returned no contact ID.");
            }

            $roleContactIds[$role] = $contactId;
            $this->storeContactRegistryId($serviceData, $role, $contactId);
            $fingerprints[$fingerprint] = $contactId;
        }

        return $roleContactIds;
    }

    /**
     * @param array<string, mixed> $serviceData
     * @return array<string, mixed>
     */
    private function contactForRole(array $serviceData, string $role): array
    {
        $contact = $serviceData['contacts'][$role] ?? [];
        return is_array($contact) ? $contact : [];
    }

    /**
     * @param array<string, mixed> $serviceData
     */
    private function storeContactRegistryId(
        array &$serviceData,
        string $role,
        string $registryId
    ): void {
        if (!isset($serviceData['contacts'][$role]) || !is_array($serviceData['contacts'][$role])) {
            $serviceData['contacts'][$role] = [];
        }

        $serviceData['contacts'][$role]['registry_id'] = $registryId;
    }

    /**
     * @param array<string, mixed> $contact
     */
    private function contactFingerprint(array $contact): string
    {
        $normalize = static function (mixed $value): string {
            $value = preg_replace('/\s+/u', ' ', trim((string)$value));
            return mb_strtolower($value ?? '');
        };

        return hash('sha256', json_encode([
            'name' => $normalize($contact['name'] ?? ''),
            'org' => $normalize($contact['org'] ?? ''),
            'street1' => $normalize($contact['street1'] ?? ''),
            'street2' => $normalize($contact['street2'] ?? ''),
            'city' => $normalize($contact['city'] ?? ''),
            'sp' => $normalize($contact['sp'] ?? ''),
            'pc' => $normalize($contact['pc'] ?? ''),
            'cc' => strtoupper(trim((string)($contact['cc'] ?? ''))),
            'voice' => preg_replace('/\D+/', '', (string)($contact['voice'] ?? '')),
            'email' => $normalize($contact['email'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];

        if (count($parts) < 2) {
            return [$parts[0] ?? 'John', 'Doe'];
        }

        if (count($parts) === 2) {
            return [$parts[0], $parts[1]];
        }

        $middle = intdiv(count($parts), 2);
        return [
            implode(' ', array_slice($parts, 0, $middle)),
            implode(' ', array_slice($parts, $middle)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function providerFor(string $domainName, string $operation): array
    {
        $providers = \getDomainConfig([$domainName], $this->db);
        $provider = $providers[0] ?? null;

        if (!is_array($provider) || empty($provider['tld']) || empty($provider['provider_id'])) {
            throw new \RuntimeException("Could not resolve a domain provider for {$operation}.");
        }

        return $provider;
    }

    /**
     * @param array<string, mixed> $provider
     */
    private function connect(string $registryType, array $provider): object
    {
        $host = trim((string)($provider['host'] ?? ''));
        if ($host === '') {
            throw new \RuntimeException('The domain provider has no EPP host configured.');
        }

        return \connectEpp(
            $registryType,
            $host,
            (int)($provider['port'] ?? 700),
            (string)($provider['cafile'] ?? ''),
            (string)($provider['cert_file'] ?? ''),
            (string)($provider['key_file'] ?? ''),
            (string)($provider['passphrase'] ?? ''),
            (string)($provider['username'] ?? ''),
            (string)($provider['password'] ?? '')
        );
    }

    private function logout(object $epp): void
    {
        try {
            $epp->logout();
        } catch (\Throwable) {
            // Provisioning has already produced its authoritative result. A
            // transport error while closing the EPP session must not replace it.
        }
    }

    /**
     * @param array<string, mixed> $serviceData
     */
    private function requiredDomainName(array $serviceData): string
    {
        $domainName = strtolower(trim((string)($serviceData['domain'] ?? '')));
        if ($domainName === '') {
            throw new \UnexpectedValueException('Domain name is missing from the order service data.');
        }

        return $domainName;
    }

    private function responseError(mixed $response): ?string
    {
        if (!is_array($response) || !array_key_exists('error', $response)) {
            return null;
        }

        $error = $response['error'];
        if (is_scalar($error) || $error === null) {
            return (string)$error;
        }

        return json_encode($error, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ?: 'Unknown EPP error';
    }

    private function formatDate(string $value): string
    {
        return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s.v');
    }

    private function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s.v');
    }
}
