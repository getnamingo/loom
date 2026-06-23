<?php
/**
 * Argora Foundry
 *
 * A modular PHP boilerplate for building SaaS applications, admin panels, and control systems.
 *
 * @package    App
 * @author     Taras Kondratyuk <help@argora.org>
 * @copyright  Copyright (c) 2025 Argora
 * @license    MIT License
 * @link       https://github.com/getargora/foundry
 */

namespace App\Controllers;

use App\Models\Orders;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use Utopia\Domains\Domain as uDomain;

class OrdersController extends Controller
{
    public function listOrders(Request $request, Response $response): Response
    {
        return view($response, 'admin/orders/index.twig');
    }

    public function viewOrder(Request $request, Response $response, string $args): Response
    {
        $db = $this->container->get('db');
        $uri = $request->getUri()->getPath();

        if ($args) {
            $args = trim($args);

            if (!preg_match('/^[a-zA-Z0-9\-]+$/', $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid order ID format');
                return $response->withHeader('Location', '/orders')->withStatus(302);
            }

            $order = $db->selectRow('SELECT id, user_id, service_type, service_data, status, amount_due, currency, invoice_id, created_at, paid_at FROM orders WHERE id = ?',
            [ $args ]);

            if ($_SESSION["auth_roles"] != 0 && $_SESSION["auth_user_id"] !== $order["user_id"]) {
                return $response->withHeader('Location', '/orders')->withStatus(302);
            }

            if ($order) {
                $service_data = json_decode($order['service_data'], true);
                
                $user_name = $db->selectValue(
                    'SELECT username FROM users WHERE id = ?',
                    [ $order['user_id'] ]
                );

                $domain_name = $service_data['domain'] ?? '';

                $service_id = '';
                if ($domain_name !== '') {
                    $service_id = $db->selectValue(
                        'SELECT id FROM services WHERE service_name = ? AND order_id = ? LIMIT 1',
                        [ $domain_name, $order['id'] ]
                    ) ?: '';
                }

                $responseData = [
                    'order' => $order,
                    'service_data' => $service_data,
                    'domain_name' => $domain_name,
                    'service_id' => $service_id,
                    'user_name' => $user_name,
                    'currentUri' => $uri
                ];

                return view($response, 'admin/orders/view.twig', $responseData);
            } else {
                // Order does not exist, redirect to the orders view
                return $response->withHeader('Location', '/orders')->withStatus(302);
            }
        } else {
            // Redirect to the orders view
            return $response->withHeader('Location', '/orders')->withStatus(302);
        }
    }

    public function createOrder(Request $request, Response $response): Response
    {
        $db = $this->container->get('db');
        $providers = $db->select("SELECT id, name, type, api_endpoint, credentials, pricing FROM providers WHERE status = 'active'") ?? [];

        $domainProducts = [];
        $otherProducts = [];
        $domainPricingTable = [];
        $domainPrices = [];

        foreach ($providers as &$provider) {
            $provider['credentials'] = json_decode($provider['credentials'], true) ?? [];
            $rawProducts = json_decode($provider['pricing'], true) ?? [];
            $credentials = $provider['credentials'];

            $enrichedProducts = [];

            foreach ($rawProducts as $label => $actions) {
                $price = $actions['register']['1'] ?? $actions['price'] ?? 0;

                $product = [
                    'type' => $provider['type'],
                    'label' => $label,
                    'description' => ucfirst($provider['type']) . ' service: ' . $label,
                    'price' => $price,
                    'billing' => $actions['billing'] ?? 'year',
                    'fields' => $credentials['required_fields'] ?? [],
                    'actions' => $actions,
                ];

                $enrichedProducts[$label] = $product;

                if ($product['type'] === 'domain') {
                    $domainProducts[] = ['provider' => $provider, 'product' => $product];

                    $register = $actions['register'][1] ?? null;
                    $renew = $actions['renew'][1] ?? null;
                    $transfer = $actions['transfer'][1] ?? null;

                    $domainPricingTable[] = [
                        'tld' => $label,
                        'register' => $register,
                        'renew' => $renew,
                        'transfer' => $transfer,
                    ];

                    $domainPrices[ltrim($label, '.')] = $price;
                } else {
                    $otherProducts[] = ['provider' => $provider, 'product' => $product];
                }
            }

            $provider['products'] = $enrichedProducts;
        }

        return $this->container->get('view')->render($response, 'admin/orders/create.twig', [
            'domainProducts' => $domainProducts,
            'otherProducts' => $otherProducts,
            'domainPrices' => $domainPrices,
            'domainPricingTable' => $domainPricingTable,
            'currency' => $_SESSION['_currency'] ?? 'EUR'
        ]);
    }

    public function activateOrder(Request $request, Response $response, string $args): Response
    {
        if ($args) {
            $args = trim($args);
            $db = $this->container->get('db');

            if (preg_match('/^[A-Za-z0-9\-]+$/', $args)) {
                $invoiceNumber = $args;
            } else {
                $this->container->get('flash')->addMessage('error', 'Invalid order number');
                return $response->withHeader('Location', '/orders')->withStatus(302);
            }

            $order_details = $db->selectRow('SELECT id, user_id, status, invoice_id FROM orders WHERE id = ?',
            [ $args ]
            );
            if (!$order_details) {
                $this->container->get('flash')->addMessage('error', 'Order not found');
                return $response->withHeader('Location', '/orders')->withStatus(302);
            }

            if ($_SESSION["auth_roles"] != 0 && $_SESSION["auth_user_id"] !== $order_details["user_id"]) {
                return $response->withHeader('Location', '/orders')->withStatus(302);
            }

            if (!in_array($order_details['status'], ['failed', 'inactive'], true)) {
                $this->container->get('flash')->addMessage('error', 'Order cannot be reprovisioned due to its current status');
                return $response->withHeader('Location', '/orders')->withStatus(302);
            }

            try {
                provisionService($db, $order_details['invoice_id'], $_SESSION["auth_user_id"]);
                $this->container->get('flash')->addMessage('success', 'Order ' . $order_details['id'] . ' has been reprovisioned successfully.');
            } catch (\Exception $e) {
                $this->container->get('flash')->addMessage('error', 'Reprovision failed: ' . $e->getMessage());
            } catch (\Throwable $e) {
                $this->container->get('flash')->addMessage('error', 'Reprovision failed: ' . $e->getMessage());
            }

            return $response->withHeader('Location', '/orders')->withStatus(302);
        } else {
            // Redirect to the orders view
            return $response->withHeader('Location', '/orders')->withStatus(302);
        }
    }

    public function cancelOrder(Request $request, Response $response, string $args): Response
    {
        $db = $this->container->get('db');
        $uri = $request->getUri()->getPath();

        if ($args) {
            $args = trim($args);

            if (!preg_match('/^[a-zA-Z0-9\-]+$/', $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid order ID format');
                return $response->withHeader('Location', '/orders')->withStatus(302);
            }

            $order = $db->selectRow('SELECT id, user_id, invoice_id FROM orders WHERE id = ?',
            [ $args ]);

            if ($_SESSION["auth_roles"] != 0 && $_SESSION["auth_user_id"] !== $order["user_id"]) {
                return $response->withHeader('Location', '/orders')->withStatus(302);
            }

            if ($order) {
                try {
                    $db->beginTransaction();

                    $currentDateTime = new \DateTime();
                    $update = $currentDateTime->format('Y-m-d H:i:s.v');

                    $db->update('orders', [
                        'status'     => 'cancelled',
                        'paid_at'    => null,
                    ], [
                        'id' => $order['id'],
                    ]);

                    $db->update('invoices', [
                        'payment_status'     => 'cancelled',
                        'updated_at'     => $update,
                        'due_date'    => null,
                    ], [
                        'id' => $order['invoice_id'],
                    ]);

                   $db->commit();
                } catch (Exception $e) {
                    $db->rollBack();
                    $this->container->get('flash')->addMessage('error', 'Database failure: ' . $e->getMessage());
                    return $response->withHeader('Location', '/orders')->withStatus(302);
                }

                $this->container->get('flash')->addMessage('success','Order ' . $order['id'] . ' has been cancelled.');
                return $response->withHeader('Location', '/orders')->withStatus(302);
            } else {
                // Order does not exist, redirect to the orders view
                return $response->withHeader('Location', '/orders')->withStatus(302);
            }
        } else {
            // Redirect to the orders view
            return $response->withHeader('Location', '/orders')->withStatus(302);
        }
    }

    public function retryOrder(Request $request, Response $response, string $args): Response
    {
        $args = trim($args);
        $db = $this->container->get('db');

        if (preg_match('/^[A-Za-z0-9\-]+$/', $args)) {
            $invoiceNumber = $args;
        } else {
            $this->container->get('flash')->addMessage('error', 'Invalid order number');
            return $response->withHeader('Location', '/orders')->withStatus(302);
        }

        $invoice_details = $db->selectRow('SELECT user_id, invoice_id FROM orders WHERE id = ?',
        [ $args ]
        );
        if (!$invoice_details) {
            $this->container->get('flash')->addMessage('error', 'Order not found');
            return $response->withHeader('Location', '/orders')->withStatus(302);
        }

        if ($_SESSION["auth_roles"] != 0 && $_SESSION["auth_user_id"] !== $invoice_details["user_id"]) {
            return $response->withHeader('Location', '/orders')->withStatus(302);
        }

        $invoice = $db->selectRow(
            'SELECT id FROM invoices WHERE id = ? AND payment_status IN ("unpaid", "overdue")',
            [ $invoice_details["invoice_id"] ]
        );

        if (!$invoice) {
            $this->container->get('flash')->addMessage('error', 'Invoice not found or already paid');
            return $response->withHeader('Location', '/orders')->withStatus(302);
        }

        return $response->withHeader('Location', '/invoice/'.$invoice['id'].'/pay')->withStatus(302);
    }

    public function registerOrder(Request $request, Response $response, string $args): Response
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');

            $userId = $_SESSION['auth_user_id'];
            $currency = $_SESSION['_currency'];
            $domainName = $_SESSION['domains_to_create'][0];
            $amount = $_SESSION['domains_to_register_price'][0];
            $years = (int) ($data['reg-years'] ?? 1);
            $authInfo = trim($data['authInfo'] ?? '');
            $nameservers = $data['nameserver'] ?? [];

            if (empty($authInfo)) {
                $this->container->get('flash')->addMessage('error', 'AuthInfo is required');
                return $response->withHeader('Location', '/orders/transfer/'.$domainName)->withStatus(302);
            }

            if (mb_strlen($authInfo) > 128) {
                $this->container->get('flash')->addMessage('error', 'AuthInfo is too long');
                return $response->withHeader('Location', '/orders/transfer/'.$domainName)->withStatus(302);
            }

            if (!preg_match('/^[\p{L}\p{N}\p{P}\p{S} ]+$/u', $authInfo)) {
                $this->container->get('flash')->addMessage('error', 'AuthInfo contains invalid characters');
                return $response->withHeader('Location', '/orders/transfer/'.$domainName)->withStatus(302);
            }

            $domain = new uDomain($domainName);
            $tldPart = $domain->getSuffix() ?: $domain->getTLD();
            $tld = '.' . strtolower($tldPart);

            try {
                $db->beginTransaction();

                // Get provider name
                $providers = $db->select('SELECT name, pricing FROM providers WHERE status = ?', [ 'active' ]);

                $providerName = null;

                foreach ($providers as $provider) {
                    $pricing = json_decode($provider['pricing'], true);
                    if (isset($pricing[$tld])) {
                        $providerName = $provider['name'];
                        break; // stop at the first match
                    }
                }

                // Get billing contact ID
                $billingContactId = $db->selectValue(
                    'SELECT id FROM users_contact WHERE user_id = ? AND type = ? LIMIT 1',
                    [ $userId, 'billing' ]
                );

                // Insert into invoices
                $currentDateTime = new \DateTime();
                $createdAt = $currentDateTime->format('Y-m-d H:i:s.v');

                $db->insert('invoices', [
                    'user_id' => $userId,
                    'billing_contact_id' => $billingContactId,
                    'issue_date' => $createdAt,
                    'due_date' => date('Y-m-d H:i:s', strtotime('+24 hours')),
                    'total_amount' => $amount,
                    'payment_status' => 'unpaid',
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);

                $invoiceId = $db->getLastInsertId();

                $db->update('invoices', [
                    'invoice_number' => $invoiceId
                ],
                [
                    'id' => $invoiceId
                ]
                );

                $contacts = [];

                foreach (['registrant' => 'owner', 'admin' => 'admin', 'tech' => 'tech', 'billing' => 'billing'] as $role => $type) {
                    $row = $db->selectRow(
                        'SELECT * FROM users_contact WHERE user_id = ? AND type = ? LIMIT 1',
                        [ $userId, $type ]
                    );

                    $contacts[$role] = [
                        'name' => (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: '',
                        'org' => $row['org'] ?? '',
                        'street1' => $row['street1'] ?? '',
                        'street2' => $row['street2'] ?? '',
                        'street3' => $row['street3'] ?? '',
                        'city' => $row['city'] ?? '',
                        'sp' => $row['sp'] ?? '',
                        'pc' => $row['pc'] ?? '',
                        'cc' => $row['cc'] ?? '',
                        'voice' => $row['voice'] ?? '',
                        'email' => $row['email'] ?? ''
                    ];
                }

                $overrides = [
                    'registrant' => $data['contactOwner']   ?? null,
                    'admin'      => $data['contactAdmin']   ?? null,
                    'tech'       => $data['contactTech']    ?? null,
                    'billing'    => $data['contactBilling'] ?? null,
                ];

                foreach ($overrides as $role => $identifier) {
                    $identifier = is_string($identifier) ? trim($identifier) : '';
                    if ($identifier !== '') {
                        if ($contact = fetchContactByIdentifier($db, $identifier)) {
                            $contacts[$role] = $contact;
                        }
                        // else: silently keep default; optionally collect a warning
                    }
                }

                $dnssec = [
                    'enabled' => false,
                    'ds_records' => []
                ];

                if (
                    isset($data['addDnssec']) && $data['addDnssec'] === 'on' &&
                    !empty($data['dsKeyTag']) &&
                    !empty($data['dsAlg']) &&
                    !empty($data['dsDigestType']) &&
                    !empty($data['dsDigest'])
                ) {
                    $dnssec = [
                        'enabled' => true,
                        'ds_records' => [[
                            'keytag' => $data['dsKeyTag'],
                            'alg' => $data['dsAlg'],
                            'digesttype' => $data['dsDigestType'],
                            'digest' => $data['dsDigest']
                        ]]
                    ];
                }

                $custom = [];

                foreach ($data as $key => $value) {
                    if (strpos($key, 'c_') === 0) {
                        $custom[substr($key, 2)] = $value;
                    }
                }

                // Build service_data
                $serviceData = json_encode([
                    'type' => 'domain_register',
                    'domain' => $domainName,
                    'years' => $years,
                    'tld' => $tld,
                    'provider' => $providerName,
                    'authInfo' => $authInfo,
                    'error_message' => null,
                    'notes' => 'Customer requested domain registration',
                    'contacts' => $contacts,
                    'nameservers' => array_values($nameservers),
                    'dnssec' => $dnssec,
                    'custom' => $custom
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

                // Insert into orders
                $currentDateTime = new \DateTime();
                $createdAt = $currentDateTime->format('Y-m-d H:i:s.v');

                $db->insert('orders', [
                    'user_id' => $userId,
                    'service_type' => 'domain.register',
                    'service_data' => $serviceData,
                    'status' => 'pending',
                    'amount_due' => $amount,
                    'currency' => $currency,
                    'invoice_id' => $invoiceId,
                    'created_at' => $createdAt,
                ]);
           
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure during order creation: ' . $e->getMessage());
                return $response->withHeader('Location', '/orders/register/'.$domainName)->withStatus(302);
            }

            unset($_SESSION['domains_to_create']);
            unset($_SESSION['domains_to_register_price']);
            $this->container->get('flash')->addMessage('success','Registration order for domain ' . $domainName . ' has been created. Please proceed with payment to complete the order.');
            return $response->withHeader('Location', '/invoice/'.$invoiceId)->withStatus(302);
        }

        $db = $this->container->get('db');
        $uri = $request->getUri()->getPath();

        if ($args) {
            $args = trim($args);

            $regex = '/^
              (?: # 1st or 2nd label (e.g., example or example.co)
                (?:xn--)?[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?
                \.
              ){1,2}
              (?: # Final label (TLD)
                (?:xn--)?[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])
              )
            $/ix';

            if (!preg_match($regex, $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid domain name requested');
                return $response->withHeader('Location', '/orders')->withStatus(302);
            }

            $db = $this->container->get('db');
            $providers = $db->select("SELECT id, name, type, api_endpoint, credentials, pricing FROM providers WHERE status = 'active'");

            $domainName = strtolower(trim($args));
            $asciiDomain = idn_to_ascii($domainName, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            $domain = new uDomain($asciiDomain);
            $tldPart = $domain->getSuffix() ?: $domain->getTLD();
            $tld = '.' . strtolower($tldPart);

            foreach ($providers as $provider) {
                $pricingData = json_decode($provider['pricing'], true);

                if (isset($pricingData[$tld])) {
                    $tldKey = ltrim($tld, '.');
                    $credentials = json_decode($provider['credentials'], true);
                    $requiredFields = $credentials['required_fields'] ?? [];

                    $responseData = [
                        'domain' => $args,
                        'currentUri' => $uri,
                        'type' => 'register',
                        'provider' => $provider['name'],
                        'pricing' => [
                            $tldKey => $pricingData[$tld]
                        ],
                        'required_fields' => $requiredFields,
                        'tldKey' => $tldKey
                    ];

                    $_SESSION['domains_to_create'] = [$args];
                    $_SESSION['domains_to_register_price'] = [$responseData['pricing'][$tldKey]['register']['1']] ?? null;

                    return view($response, 'admin/orders/register.twig', $responseData);
                }
            }

            $this->container->get('flash')->addMessage('error', 'Invalid domain name requested');
            return $response->withHeader('Location', '/orders')->withStatus(302);
        } else {
            // Redirect to the orders view
            return $response->withHeader('Location', '/orders')->withStatus(302);
        }
    }

    public function transferOrder(Request $request, Response $response, string $args): Response
    {
        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');

            $userId = $_SESSION['auth_user_id'];
            $currency = $_SESSION['_currency'];
            $domainName = $_SESSION['domains_to_transfer'][0];
            $amount = $_SESSION['domains_to_transfer_price'][0];
            
            $authInfo = trim($data['authInfo'] ?? '');

            if (empty($authInfo)) {
                $this->container->get('flash')->addMessage('error', 'AuthInfo is required');
                return $response->withHeader('Location', '/orders/transfer/'.$domainName)->withStatus(302);
            }

            if (mb_strlen($authInfo) > 128) {
                $this->container->get('flash')->addMessage('error', 'AuthInfo is too long');
                return $response->withHeader('Location', '/orders/transfer/'.$domainName)->withStatus(302);
            }

            if (!preg_match('/^[\p{L}\p{N}\p{P}\p{S} ]+$/u', $authInfo)) {
                $this->container->get('flash')->addMessage('error', 'AuthInfo contains invalid characters');
                return $response->withHeader('Location', '/orders/transfer/'.$domainName)->withStatus(302);
            }


            $domain = new uDomain($domainName);
            $tldPart = $domain->getSuffix() ?: $domain->getTLD();
            $tld = '.' . strtolower($tldPart);

            try {
                $db->beginTransaction();

                // Get provider name
                $providers = $db->select('SELECT name, pricing FROM providers WHERE status = ?', [ 'active' ]);

                $providerName = null;

                foreach ($providers as $provider) {
                    $pricing = json_decode($provider['pricing'], true);
                    if (isset($pricing[$tld])) {
                        $providerName = $provider['name'];
                        break; // stop at the first match
                    }
                }

                // Get billing contact ID
                $billingContactId = $db->selectValue(
                    'SELECT id FROM users_contact WHERE user_id = ? AND type = ? LIMIT 1',
                    [ $userId, 'billing' ]
                );

                // Insert into invoices
                $currentDateTime = new \DateTime();
                $createdAt = $currentDateTime->format('Y-m-d H:i:s.v');

                $db->insert('invoices', [
                    'user_id' => $userId,
                    'billing_contact_id' => $billingContactId,
                    'issue_date' => $createdAt,
                    'due_date' => date('Y-m-d H:i:s', strtotime('+24 hours')),
                    'total_amount' => $amount,
                    'payment_status' => 'unpaid',
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);

                $invoiceId = $db->getLastInsertId();

                $db->update('invoices', [
                    'invoice_number' => $invoiceId
                ],
                [
                    'id' => $invoiceId
                ]
                );

                // Build service_data
                $serviceData = json_encode([
                    'type' => 'domain_transfer',
                    'domain' => $domainName,
                    'provider' => $providerName,
                    'authInfo' => $authInfo,
                    'error_message' => null,
                    'notes' => 'Customer requested domain transfer',
                ]);

                // Insert into orders
                $currentDateTime = new \DateTime();
                $createdAt = $currentDateTime->format('Y-m-d H:i:s.v');

                $db->insert('orders', [
                    'user_id' => $userId,
                    'service_type' => 'domain.transfer',
                    'service_data' => $serviceData,
                    'status' => 'pending',
                    'amount_due' => $amount,
                    'currency' => $currency,
                    'invoice_id' => $invoiceId,
                    'created_at' => $createdAt,
                ]);
           
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack();
                $this->container->get('flash')->addMessage('error', 'Database failure during order creation: ' . $e->getMessage());
                return $response->withHeader('Location', '/orders/transfer/'.$domainName)->withStatus(302);
            }

            unset($_SESSION['domains_to_transfer']);
            unset($_SESSION['domains_to_transfer_price']);
            $this->container->get('flash')->addMessage('success','Transfer order for domain ' . $domainName . ' has been created. Please proceed with payment to start the transfer.');
            return $response->withHeader('Location', '/invoice/'.$invoiceId)->withStatus(302);
        }

        $db = $this->container->get('db');
        $uri = $request->getUri()->getPath();

        if ($args) {
            $args = trim($args);

            $regex = '/^
              (?: # 1st or 2nd label (e.g., example or example.co)
                (?:xn--)?[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?
                \.
              ){1,2}
              (?: # Final label (TLD)
                (?:xn--)?[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])
              )
            $/ix';

            if (!preg_match($regex, $args)) {
                $this->container->get('flash')->addMessage('error', 'Invalid domain name requested');
                return $response->withHeader('Location', '/orders')->withStatus(302);
            }

            $db = $this->container->get('db');
            $providers = $db->select("SELECT id, name, type, api_endpoint, credentials, pricing FROM providers WHERE status = 'active'");

            $domainName = strtolower(trim($args));
            $asciiDomain = idn_to_ascii($domainName, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            $domain = new uDomain($asciiDomain);
            $tldPart = $domain->getSuffix() ?: $domain->getTLD();
            $tld = '.' . strtolower($tldPart);

            foreach ($providers as $provider) {
                $pricingData = json_decode($provider['pricing'], true);

                if (isset($pricingData[$tld])) {
                    $tldKey = ltrim($tld, '.');
                    $credentials = json_decode($provider['credentials'], true);
                    $requiredFields = $credentials['required_fields'] ?? [];

                    $responseData = [
                        'domain' => $args,
                        'currentUri' => $uri,
                        'type' => 'transfer',
                        'provider' => $provider['name'],
                        'pricing' => [
                            $tldKey => $pricingData[$tld]
                        ],
                        'required_fields' => $requiredFields
                    ];

                    $_SESSION['domains_to_transfer'] = [$args];
                    $_SESSION['domains_to_transfer_price'] = [$responseData['pricing'][$tldKey]['transfer']['1']] ?? null;

                    return view($response, 'admin/orders/transfer.twig', $responseData);
                }
            }

            $this->container->get('flash')->addMessage('error', 'Invalid domain name requested');
            return $response->withHeader('Location', '/orders')->withStatus(302);
        } else {
            // Redirect to the orders view
            return $response->withHeader('Location', '/orders')->withStatus(302);
        }
    }

    public function deleteOrder(Request $request, Response $response, string $args): Response
    {
        if ($args) {
            $args = trim($args);
            $db = $this->container->get('db');

            if (preg_match('/^[A-Za-z0-9\-]+$/', $args)) {
                $invoiceNumber = $args;
            } else {
                $this->container->get('flash')->addMessage('error', 'Invalid order number');
                return $response->withHeader('Location', '/orders')->withStatus(302);
            }

            $order_details = $db->selectRow('SELECT id, user_id, status FROM orders WHERE id = ?',
            [ $args ]
            );
            if (!$order_details) {
                $this->container->get('flash')->addMessage('error', 'Order not found');
                return $response->withHeader('Location', '/orders')->withStatus(302);
            }

            if ($_SESSION["auth_roles"] != 0 && $_SESSION["auth_user_id"] !== $order_details["user_id"]) {
                return $response->withHeader('Location', '/orders')->withStatus(302);
            }

            if (!in_array($order_details['status'], ['cancelled', 'inactive'])) {
                $this->container->get('flash')->addMessage('error', 'This order cannot be deleted due to its current status');
                return $response->withHeader('Location', '/orders')->withStatus(302);
            } else {
                $db->delete(
                    'orders',
                    [
                        'id' => $args
                    ]
                );

                $this->container->get('flash')->addMessage('success', 'Order ' . $args . ' deleted successfully');
                return $response->withHeader('Location', '/orders')->withStatus(302);
            }
        } else {
            // Redirect to the orders view
            return $response->withHeader('Location', '/orders')->withStatus(302);
        }
    }
}