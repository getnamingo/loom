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

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Container\ContainerInterface;
use Mpociot\VatCalculator\VatCalculator;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Ramsey\Uuid\Uuid;
use League\ISO3166\ISO3166;
use LiqPay;

class FinancialsController extends Controller
{
    public function transactions(Request $request, Response $response)
    {
        return view($response,'admin/financials/transactions.twig');
    }

    public function invoices(Request $request, Response $response)
    {
        return view($response,'admin/financials/invoices.twig');
    }

    public function viewInvoice(Request $request, Response $response, $args)
    {
        $args = trim($args);

        if (preg_match('/^[A-Za-z0-9\-]+$/', $args)) {
            $invoiceNumber = $args;
        } else {
            $this->container->get('flash')->addMessage('error', 'Invalid invoice number');
            return $response->withHeader('Location', '/invoices')->withStatus(302);
        }

        $iso3166 = new ISO3166();
        $db = $this->container->get('db');
        // Get the current URI
        $uri = $request->getUri()->getPath();
        $invoice_details = $db->selectRow('SELECT * FROM invoices WHERE invoice_number = ?',
        [ $invoiceNumber ]
        );

        if ($_SESSION["auth_roles"] != 0 && $_SESSION["auth_user_id"] !== $invoice_details["user_id"]) {
            return $response->withHeader('Location', '/invoices')->withStatus(302);
        }

        if (!$invoice_details) {
            $this->container->get('flash')->addMessage('error', 'Invoice not found');
            return $response->withHeader('Location', '/invoices')->withStatus(302);
        }

        $billing = $db->selectRow(
            'SELECT * FROM users_contact WHERE id = ? AND type = \'billing\'',
            [ $invoice_details['billing_contact_id'] ]
        );
        $userData = $db->selectRow(
            'SELECT currency, nin, vat_number, nin_type FROM users WHERE id = ?',
            [ $invoice_details['user_id'] ]
        );

        $currency = $userData['currency'] ?? null;
        $nin = $userData['nin'] ?? null;
        $billing_vat = $userData['vat_number'] ?? null;
        $ninType = $userData['nin_type'] ?? null;

        $company_name = envi('COMPANY_NAME');
        $address      = envi('COMPANY_ADDRESS');
        $address2     = envi('COMPANY_ADDRESS2');
        $cc           = envi('COMPANY_COUNTRY_CODE');
        $vat_number   = envi('COMPANY_VAT_NUMBER');
        $phone        = envi('COMPANY_PHONE');
        $email        = envi('COMPANY_EMAIL');

        $orders = null;
        $deposit = null;
        $locale = $_SESSION['_lang'] ?? 'en_US'; // Fallback to 'en_US' if no locale is set

        if ($invoice_details['invoice_type'] === 'deposit') {
            $deposit = $db->selectRow(
                'SELECT category, description, amount, currency, created_at
                 FROM transactions
                 WHERE related_entity_id = ?',
                [ $invoice_details['id'] ]
            );
        } else {
            $orders = $db->select(
                'SELECT service_type, amount_due, currency, service_data, created_at 
                 FROM orders 
                 WHERE invoice_id = ?', 
                [ $invoice_details['id'] ]
            );

            $currencyFormatterStatement = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
            foreach ($orders as &$order) {
                $order['service_data'] = json_decode($order['service_data'], true);
                $order['amount_formatted'] = $currencyFormatterStatement->formatCurrency($order['amount_due'], $order['currency']);
            }
        }

        $vatCalculator = new VatCalculator();
        $vatCalculator->setBusinessCountryCode(strtoupper($cc));
        $grossPrice = $vatCalculator->calculate($invoice_details['total_amount'], strtoupper($billing['cc']));
        $taxRate = $vatCalculator->getTaxRate();
        $netPrice = $vatCalculator->getNetPrice(); 
        $taxValue = $vatCalculator->getTaxValue(); 
        if ($vatCalculator->shouldCollectVAT(strtoupper($billing['cc']))) {
            $validVAT = $vatCalculator->isValidVatNumberFormat($vat_number);
        } else {
            $validVAT = null;
        }
        $totalAmount = $grossPrice + $taxValue;
        $billing_country = $iso3166->alpha2($billing['cc']);
        $billing_country = $billing_country['name'];

        // Initialize the number formatter for the locale
        $numberFormatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $currencyFormatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);

        // Format values explicitly with the session currency
        $formattedVatRate = $numberFormatter->format($taxRate * 100) . "%";
        $formattedVatAmount = $currencyFormatter->formatCurrency($taxValue, $currency);
        $formattedNetPrice = $currencyFormatter->formatCurrency($netPrice, $currency);
        $formattedTotalAmount = $currencyFormatter->formatCurrency($totalAmount, $currency);

        // Pass formatted values to Twig
        return view($response, 'admin/financials/viewInvoice.twig', [
            'invoice_details' => $invoice_details,
            'billing' => $billing,
            'billing_company' => $nin,
            'billing_vat' => $billing_vat,
            'statement' => $orders,
            'deposit' => $deposit,
            'company_name' => $company_name,
            'address' => $address,
            'address2' => $address2,
            'cc' => $cc,
            'vat_number' => $vat_number,
            'phone' => $phone,
            'email' => $email,
            'vatRate' => $formattedVatRate,
            'vatAmount' => $formattedVatAmount,
            'validVAT' => $validVAT,
            'netPrice' => $formattedNetPrice,
            'total' => $formattedTotalAmount,
            'currentUri' => $uri,
            'billing_country' => $billing_country,
        ]);

    }

    public function payInvoice(Request $request, Response $response, $args)
    {
        $args = trim($args);

        if (preg_match('/^[A-Za-z0-9\-]+$/', $args)) {
            $invoiceNumber = $args;
        } else {
            $this->container->get('flash')->addMessage('error', 'Invalid invoice number');
            return $response->withHeader('Location', '/invoices')->withStatus(302);
        }

        $iso3166 = new ISO3166();
        $db = $this->container->get('db');
        $uri = $request->getUri()->getPath();
        $invoice_details = $db->selectRow('SELECT * FROM invoices WHERE invoice_number = ?',
        [ $invoiceNumber ]
        );

        if ($_SESSION["auth_roles"] != 0 && $_SESSION["auth_user_id"] !== $invoice_details["user_id"]) {
            return $response->withHeader('Location', '/invoices')->withStatus(302);
        }

        if (!$invoice_details) {
            $this->container->get('flash')->addMessage('error', 'Invoice not found');
            return $response->withHeader('Location', '/invoices')->withStatus(302);
        }

        $billing = $db->selectRow(
            'SELECT * FROM users_contact WHERE id = ? AND type = \'billing\'',
            [ $invoice_details['billing_contact_id'] ]
        );
        $userData = $db->selectRow(
            'SELECT currency, nin, vat_number, nin_type, account_balance, credit_limit FROM users WHERE id = ?',
            [ $invoice_details['user_id'] ]
        );

        if (!in_array($invoice_details['payment_status'] ?? '', ['unpaid', 'overdue'], true)) {
            $this->container->get('flash')->addMessage('error', 'This invoice cannot be paid because it is already settled or not payable');
            return $response->withHeader('Location', '/invoices')->withStatus(302);
        }

        $currency = $userData['currency'] ?? null;
        $cc           = envi('COMPANY_COUNTRY_CODE');
        $vatCalculator = new VatCalculator();
        $vatCalculator->setBusinessCountryCode(strtoupper($cc));
        $grossPrice = $vatCalculator->calculate($invoice_details['total_amount'], strtoupper($billing['cc']));
        $taxValue = $vatCalculator->getTaxValue(); 
        $totalAmount = $grossPrice + $taxValue;
        $locale = $_SESSION['_lang'] ?? 'en_US'; // Fallback to 'en_US' if no locale is set
        $stripe_key = envi('STRIPE_PUBLISHABLE_KEY');

        $currencyFormatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        $formattedTotalAmount = $currencyFormatter->formatCurrency($totalAmount, $currency);

        $enabledGateways = array_map('trim', explode(',', envi('ENABLED_GATEWAYS')));
        $_SESSION['pending_invoice_amount'] = $totalAmount;
        $_SESSION['pending_invoice_id'] = $invoiceNumber;

        $canPayWithBalance = false;
        if (isset($userData['account_balance'], $userData['credit_limit'])) {
            $availableFunds = $userData['account_balance'] + $userData['credit_limit'];
            $canPayWithBalance = $availableFunds >= $totalAmount;
        }

        // Pass formatted values to Twig
        return view($response, 'admin/financials/payInvoice.twig', [
            'invoice_details' => $invoice_details,
            'total' => $formattedTotalAmount,
            'currentUri' => $uri,
            'stripe_key' => $stripe_key,
            'enabledGateways' => $enabledGateways,
            'canPayWithBalance' => $canPayWithBalance,
        ]);

    }

    public function deposit(Request $request, Response $response)
    {
        if ($_SESSION["auth_roles"] != 0) {
            $db = $this->container->get('db');
            $balance = $db->selectRow('SELECT username, account_balance, credit_limit FROM users WHERE id = ?',
            [ $_SESSION["auth_user_id"] ]
            );
            $currency = $_SESSION['_currency'];
            $stripe_key = envi('STRIPE_PUBLISHABLE_KEY');

            $enabledGateways = array_map('trim', explode(',', envi('ENABLED_GATEWAYS')));

            return view($response,'admin/financials/deposit-user.twig', [
                'balance' => $balance,
                'currency' => $currency,
                'stripe_key' => $stripe_key,
                'enabledGateways' => $enabledGateways,
            ]);
        }

        if ($request->getMethod() === 'POST') {
            // Retrieve POST data
            $data = $request->getParsedBody();
            $db = $this->container->get('db');
            $user_id = $data['user'];
            $amount = $data['amount'];
            $description = "funds added to account balance";
            if (!empty($data['description'])) {
                $description .= " (" . $data['description'] . ")";
            }

            $isPositiveNumberWithTwoDecimals = filter_var($amount, FILTER_VALIDATE_FLOAT) !== false && preg_match('/^\d+(\.\d{1,2})?$/', $amount);

            if ($isPositiveNumberWithTwoDecimals) {
                $db->beginTransaction();

                try {
                    $currentDateTime = new \DateTime();
                    $date = $currentDateTime->format('Y-m-d H:i:s.v');

                    // Get billing contact ID
                    $billingContactId = $db->selectValue(
                        'SELECT id FROM users_contact WHERE user_id = ? AND type = ? LIMIT 1',
                        [ $user_id, 'billing' ]
                    );

                    // Insert into invoices
                    $currentDateTime = new \DateTime();
                    $createdAt = $currentDateTime->format('Y-m-d H:i:s.v');

                    $db->insert('invoices', [
                        'user_id' => $user_id,
                        'invoice_type' => 'deposit',
                        'billing_contact_id' => $billingContactId,
                        'issue_date' => $createdAt,
                        'due_date' => date('Y-m-d H:i:s', strtotime('+24 hours')),
                        'total_amount' => $amount,
                        'payment_status' => 'paid',
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

                    $db->insert(
                        'transactions',
                        [
                            'user_id' => $user_id,
                            'related_entity_type' => 'invoice',
                            'related_entity_id' => $invoiceId,
                            'type' => 'credit',
                            'category' => 'deposit',
                            'description' => $description,
                            'amount' => $amount,
                            'currency' => $_SESSION['_currency'],
                            'status' => 'completed',
                            'created_at' => $date
                        ]
                    );

                    $db->exec(
                        'UPDATE users SET account_balance = (account_balance + ?) WHERE id = ?',
                        [
                            $amount,
                            $user_id
                        ]
                    );

                    $db->commit();
                } catch (Exception $e) {
                    $db->rollBack();
                    $this->container->get('flash')->addMessage('error', 'Database failure: '.$e->getMessage());
                    return $response->withHeader('Location', '/deposit')->withStatus(302);
                }
                
                $this->container->get('flash')->addMessage('success', 'Deposit successfully added. The user\'s account balance has been updated.');
                return $response->withHeader('Location', '/deposit')->withStatus(302);
            } else {
                $this->container->get('flash')->addMessage('error', 'Invalid entry: Deposit amount must be positive. Please enter a valid amount.');
                return $response->withHeader('Location', '/deposit')->withStatus(302);
            }
        }
            
        $db = $this->container->get('db');
        $users = $db->select("SELECT id, email, username FROM users");

        return view($response,'admin/financials/deposit.twig', [
            'users' => $users
        ]);
    }

    public function balancePayment(Request $request, Response $response)
    {
        $postData = $request->getParsedBody();
        $amount = $postData['amount'] ?? null;
        $db = $this->container->get('db');

        if (!$amount && isset($_SESSION['pending_invoice_amount'])) {
            $amount = $_SESSION['pending_invoice_amount'];
            $paymentDescription = 'Invoice Payment #' . ($_SESSION['pending_invoice_id'] ?? '');
            $invoiceId = $_SESSION['pending_invoice_id'] ?? '';
            unset($_SESSION['pending_invoice_amount']);
            unset($_SESSION['pending_invoice_id']);
        }

        if ($amount && $invoiceId) {
            try {
                $paymentType = 'invoice';
                $isPositiveNumberWithTwoDecimals = filter_var($amount, FILTER_VALIDATE_FLOAT) !== false && preg_match('/^\d+(\.\d{1,2})?$/', $amount);

                if ($isPositiveNumberWithTwoDecimals) {
                    $userId = $_SESSION["auth_user_id"] ?? $userId;

                    $userData = $db->selectRow(
                        'SELECT currency, account_balance, credit_limit FROM users WHERE id = ?',
                        [ $userId ]
                    );

                    if (($userData['account_balance'] + $userData['credit_limit']) < $amount) {
                        $this->container->get('flash')->addMessage('error', 'There is no money on the account to pay for the invoice');
                        $response->getBody()->write(json_encode([
                            'success' => false,
                            'invoice_url' => "/invoice/{$invoiceId}"
                        ]));
                        return $response
                            ->withHeader('Content-Type', 'application/json')
                            ->withStatus(200);
                    }

                    try {
                        $db->beginTransaction();
                        $currentDateTime = new \DateTime();
                        $date = $currentDateTime->format('Y-m-d H:i:s.v');

                        $db->insert('transactions', [
                            'user_id'             => $userId,
                            'related_entity_type' => 'invoice',
                            'related_entity_id'   => $invoiceId,
                            'type'                => 'debit',
                            'category'            => 'invoice',
                            'description'         => "Payment for Invoice #{$invoiceId}",
                            'amount'              => $amount,
                            'currency'            => $_SESSION['_currency'],
                            'status'              => 'completed',
                            'created_at'          => $date
                        ]);

                        $currentDateTime = new \DateTime();
                        $updatedAt = $currentDateTime->format('Y-m-d H:i:s.v');

                        $db->update(
                            'invoices',
                            [
                                'payment_status' => 'paid',
                                'updated_at' => $updatedAt
                            ],
                            [
                                'id' => $invoiceId,
                                'user_id' => $userId
                            ]
                        );

                        $db->exec(
                            'UPDATE users SET account_balance = (account_balance - ?) WHERE id = ?',
                            [
                                $amount,
                                $userId
                            ]
                        );

                        $db->commit();
                    } catch (Exception $e) {
                        $db->rollBack();

                        $this->container->get('flash')->addMessage('error', 'Failure: '.$e->getMessage());
                        $response->getBody()->write(json_encode([
                            'success' => false,
                            'invoice_url' => "/invoice/{$invoiceId}"
                        ]));
                        return $response
                            ->withHeader('Content-Type', 'application/json')
                            ->withStatus(200);
                    }

                    try {
                        provisionService($db, $invoiceId, $_SESSION["auth_user_id"]);
                    } catch (Exception $e) {
                        $this->container->get('flash')->addMessage('error', 'Failure: '.$e->getMessage());
                        $response->getBody()->write(json_encode([
                            'success' => false,
                            'invoice_url' => "/invoice/{$invoiceId}"
                        ]));
                        return $response
                            ->withHeader('Content-Type', 'application/json')
                            ->withStatus(200);
                    }

                    $this->container->get('flash')->addMessage('success', "Invoice payment received successfully. Your service will be processed shortly");
                    $response->getBody()->write(json_encode([
                        'success' => true,
                        'invoice_url' => "/invoice/{$invoiceId}"
                    ]));
                    return $response
                        ->withHeader('Content-Type', 'application/json')
                        ->withStatus(200);
                } else {
                    $this->container->get('flash')->addMessage('error', 'Invalid entry: Amount must be positive. Please enter a valid amount');
                    $response->getBody()->write(json_encode([
                        'success' => false,
                        'invoice_url' => "/invoice/{$invoiceId}"
                    ]));
                    return $response
                        ->withHeader('Content-Type', 'application/json')
                        ->withStatus(200);
                }
            } catch (\Exception $e) {
                $currentDateTime = new \DateTime();
                $createdAt = $currentDateTime->format('Y-m-d H:i:s.v');

                $db->update('orders', ['status' => 'failed'], ['invoice_id' => $invoiceId]);

                $db->insert('service_logs', [
                    'service_id' => 0,
                    'event' => 'payment_failed',
                    'actor_type' => 'system',
                    'actor_id' => $_SESSION["auth_user_id"],
                    'details' => 'invoice ' . $invoiceId . '|' . $e->getMessage(),
                    'created_at' => $createdAt
                ]);

                $this->container->get('flash')->addMessage('error', 'We encountered an issue while processing your order. Please contact our support team');
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'invoice_url' => "/invoice/{$invoiceId}"
                ]));
                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus(200);
            }
        }
    }

    public function createStripePayment(Request $request, Response $response)
    {
        $postData = $request->getParsedBody();
        $amount = $postData['amount'] ?? null;

        if (!$amount && isset($_SESSION['pending_invoice_amount'])) {
            $amount = $_SESSION['pending_invoice_amount'];
            $paymentDescription = 'Invoice Payment #' . ($_SESSION['pending_invoice_id'] ?? '');
            $invoiceId = $_SESSION['pending_invoice_id'] ?? '';
            unset($_SESSION['pending_invoice_amount']);
            unset($_SESSION['pending_invoice_id']);
        } else {
            $paymentDescription = 'Client Balance Deposit';
        }

        $amount = filter_var($amount, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $amountInCents = (int) round($amount * 100);
        \Stripe\Stripe::setApiKey(envi('STRIPE_SECRET_KEY'));

        // Create Stripe Checkout session
        $checkout_session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card', 'paypal'],
            'line_items' => [[
                'price_data' => [
                    'currency' => $_SESSION['_currency'],
                    'product_data' => [
                        'name' => $paymentDescription,
                    ],
                    'unit_amount' => $amountInCents,
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => envi('APP_URL').'/payment-success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => envi('APP_URL').'/payment-cancel',
            'metadata' => [
                'invoice_id' => $invoiceId ?? null,
                'user_id' => $_SESSION['auth_user_id'] ?? null,
                'payment_type' => $paymentDescription,
            ]
        ]);

        // Return session ID to the frontend
        $response->getBody()->write(json_encode(['id' => $checkout_session->id]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function createLiqPayPayment(Request $request, Response $response)
    {
        $postData = $request->getParsedBody();
        $amount = $postData['amount'] ?? null;

        // Keep Loom’s “pending invoice” shortcut
        if (!$amount && isset($_SESSION['pending_invoice_amount'])) {
            $amount = $_SESSION['pending_invoice_amount'];
            $invoiceId = $_SESSION['pending_invoice_id'] ?? null;
            $paymentDescription = 'Payment for Invoice #' . ($invoiceId ?? '');
            unset($_SESSION['pending_invoice_amount'], $_SESSION['pending_invoice_id']);
        } else {
            $invoiceId = null;
            $paymentDescription = 'Account balance deposit';
        }

        $amount = filter_var($amount, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        if (!is_numeric($amount) || $amount <= 0) {
            $response->getBody()->write(json_encode(['error' => 'Invalid amount']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Order id encodes type for easy parsing later
        $orderPrefix = $invoiceId ? ('inv-' . (int)$invoiceId) : ('dep-' . ((int)($_SESSION['auth_user_id'] ?? 0)));
        $orderId = $orderPrefix . '.' . uniqid('', true);

        $liqpay = new LiqPay(envi('LIQPAY_PUBLIC_KEY'), envi('LIQPAY_PRIVATE_KEY'));
        $lang = strtolower(substr($_SESSION['_lang'] ?? 'en', 0, 2));

        $params = [
            'version'     => 3,
            'action'      => 'pay',
            'amount'      => (float)$amount,
            'currency'    => $_SESSION['_currency'] ?? 'USD',
            'description' => $paymentDescription,
            'language'    => (in_array($lang, ['uk','en']) ? $lang : 'en'),
            'order_id'    => $orderId,
            'result_url'  => rtrim(envi('APP_URL'), '/') . '/payment-success-liqpay?order_id=' . rawurlencode($orderId),
        ];

        // Give the frontend raw payload so it can submit a form or render LiqPay button
        $raw = $liqpay->cnb_form_raw($params);
        // $raw = ['url' => 'https://www.liqpay.ua/api/3/checkout','data'=>'...','signature'=>'...']

        $response->getBody()->write(json_encode($raw));
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function createLiqPayPaymentPage(Request $req, Response $res)
    {
        $post = $req->getParsedBody();
        $amount = isset($post['amount']) ? (float)$post['amount'] : (float)($_SESSION['pending_invoice_amount'] ?? 0);
        $invoiceId = $_SESSION['pending_invoice_id'] ?? null;

        $orderPrefix = $invoiceId ? ('inv-' . (int)$invoiceId) : ('dep-' . ((int)($_SESSION['auth_user_id'] ?? 0)));
        $orderId = $orderPrefix . '.' . uniqid('', true);

        $liqpay = new LiqPay(envi('LIQPAY_PUBLIC_KEY'), envi('LIQPAY_PRIVATE_KEY'));
        $lang = strtolower(substr($_SESSION['_lang'] ?? 'en', 0, 2));
        $params = [
            'version'     => 3,
            'action'      => 'pay',
            'amount'      => $amount,
            'currency'    => $_SESSION['_currency'] ?? 'USD',
            'description' => $invoiceId ? ("Payment for Invoice #{$invoiceId}") : 'Account balance deposit',
            'language'    => (in_array($lang, ['uk','en']) ? $lang : 'en'),
            'order_id'    => $orderId,
            'result_url'  => rtrim(envi('APP_URL'), '/') . '/payment-success-liqpay?order_id=' . rawurlencode($orderId),
        ];
        $raw = $liqpay->cnb_form_raw($params); // ['url','data','signature']

        return $this->view->render($res, 'admin/financials/liqpay_post_bridge.twig', ['raw' => $raw]);
    }

    public function createPlataPayment(Request $request, Response $response)
    {
        $postData = $request->getParsedBody();
        $amount   = $postData['amount'] ?? null;

        // Same shortcut logic as LiqPay
        if (!$amount && isset($_SESSION['pending_invoice_amount'])) {
            $amount = $_SESSION['pending_invoice_amount'];
            $invoiceId = $_SESSION['pending_invoice_id'] ?? null;
            $paymentDescription = 'Payment for Invoice #' . ($invoiceId ?? '');
            unset($_SESSION['pending_invoice_amount'], $_SESSION['pending_invoice_id']);
        } else {
            $invoiceId = null;
            $paymentDescription = 'Account balance deposit';
        }

        $amount = filter_var($amount, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        if (!is_numeric($amount) || $amount <= 0) {
            $response->getBody()->write(json_encode(['error' => 'Invalid amount']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Order id encodes type for easy parsing later
        $orderPrefix = $invoiceId ? ('inv-' . (int)$invoiceId) : ('dep-' . ((int)($_SESSION['auth_user_id'] ?? 0)));
        $orderId = $orderPrefix . '.' . uniqid('', true);

        $amountMinor = (int) round(((float)$amount) * 100); // kopiyky
        if (($currency = strtoupper($_SESSION['_currency'] ?? 'UAH')) !== 'UAH') {
            $response->getBody()->write(json_encode(['error' => 'Plata: only UAH supported']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
        }

        // Build payload for Plata API
        $payload = [
            'amount' => $amountMinor,
            'ccy'    => 980, // UAH ISO code
            'merchantPaymInfo' => [
                'reference'   => $orderId,
                'destination' => $paymentDescription,
                'comment'     => $paymentDescription,
                'basketOrder' => [[
                    'name'  => $paymentDescription,
                    'qty'   => 1,
                    'sum'   => $amountMinor,
                    'total' => $amountMinor,
                    'unit'  => 'шт.',
                    'code'  => (string)($invoiceId ?? 0),
                ]],
            ],
            'redirectUrl' => rtrim(envi('APP_URL'), '/') . '/payment-success-plata?order_id=' . rawurlencode($orderId),
            'validity'    => 24 * 3600,
            'paymentType' => 'debit',
        ];

        $token = envi('PLATA_TOKEN');
        $ch = curl_init('https://api.monobank.ua/api/merchant/invoice/create');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'X-Token: ' . $token,
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $code >= 400) {
            $response->getBody()->write(json_encode(['error' => 'Plata API error: ' . $raw]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        $resp = json_decode($raw, true);
        if (!isset($resp['invoiceId'], $resp['pageUrl'])) {
            $response->getBody()->write(json_encode(['error' => 'Plata: no invoiceId/pageUrl returned']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }

        // Return URL to frontend so it can redirect
        $response->getBody()->write(json_encode([
            'invoice_id' => $resp['invoiceId'],
            'url'        => $resp['pageUrl'],
        ]));
        $_SESSION['plata_invoice_id'] = $resp['invoiceId'];
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function createAdyenPayment(Request $request, Response $response)
    {
        $postData = $request->getParsedBody();
        $amount = $postData['amount'] ?? null;

        if (!$amount && isset($_SESSION['pending_invoice_amount'])) {
            $amount = $_SESSION['pending_invoice_amount'];
            unset($_SESSION['pending_invoice_amount']);
            unset($_SESSION['pending_invoice_id']);
        }

        $amount = filter_var($amount, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $amountInCents = (int) round($amount * 100);

        $userId = $_SESSION["auth_user_id"];
        $uniqueIdentifier = Uuid::uuid4()->toString();

        $delimiter = '|';
        $combinedString = $userId . $delimiter . $uniqueIdentifier;
        $merchantReference = bin2hex($combinedString);

        $client = new \Adyen\Client();
        $client->setApplicationName('Loom');
        $client->setEnvironment(\Adyen\Environment::TEST);
        $client->setXApiKey(envi('ADYEN_API_KEY'));
        $service = new \Adyen\Service\Checkout($client);
        $params = array(
           'amount' => array(
               'currency' => $_SESSION['_currency'],
               'value' => $amountInCents
           ),
           'merchantAccount' => envi('ADYEN_MERCHANT_ID'),
           'reference' => $merchantReference,
           'returnUrl' => envi('APP_URL').'/payment-success-adyen',
           'mode' => 'hosted',
           'themeId' => envi('ADYEN_THEME_ID')
        );
        $result = $service->sessions($params);
        
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    public function createCryptoPayment(Request $request, Response $response)
    {
        $postData = $request->getParsedBody();
        $amount = $postData['amount'] ?? null;

        if (!$amount && isset($_SESSION['pending_invoice_amount'])) {
            $amount = $_SESSION['pending_invoice_amount'];
            unset($_SESSION['pending_invoice_amount']);
            unset($_SESSION['pending_invoice_id']);
        }

        $amount = filter_var($amount, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $amountWhole = (int) round($amount);

        $userId = $_SESSION["auth_user_id"];
        $uniqueIdentifier = Uuid::uuid4()->toString();

        $delimiter = '|';
        $combinedString = $userId . $delimiter . $uniqueIdentifier;
        $merchantReference = bin2hex($combinedString);
        
        $data = [
            'price_amount' => $amountWhole,
            'price_currency' => $_SESSION['_currency'],
            'order_id' => $merchantReference,
            'success_url' => envi('APP_URL').'/payment-success-crypto',
            'cancel_url' => envi('APP_URL').'/payment-cancel',
        ];
        
        $client = new Client();
        $apiKey = envi('NOW_API_KEY');

        try {
            $apiResponse = $client->request('POST', 'https://api.nowpayments.io/v1/invoice', [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $data,
            ]);

            $body = $apiResponse->getBody()->getContents();
            $response->getBody()->write($body);
            return $response->withHeader('Content-Type', 'application/json');
        } catch (GuzzleException $e) {
            $errorResponse = [
                'error' => 'We encountered an issue while processing your payment.',
                'details' => $e->getMessage(),
            ];

            $response->getBody()->write(json_encode($errorResponse));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }

    public function createNickyPayment(Request $request, Response $response)
    {
        $postData = $request->getParsedBody();
        $amount = $postData['amount'] ?? null;
        $userId = $_SESSION["auth_user_id"];

        if (!$amount && isset($_SESSION['pending_invoice_amount'])) {
            $amount = $_SESSION['pending_invoice_amount'];
            $paymentDescription = 'Invoice Payment #' . ($_SESSION['pending_invoice_id'] ?? '');
            unset($_SESSION['pending_invoice_amount']);
            unset($_SESSION['pending_invoice_id']);
        } else {
            $paymentDescription = 'Client Balance Deposit (' . $userId .')';
        }

        $amount = filter_var($amount, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $amountWhole = (int) round($amount);

        $invoiceReference = strtoupper(bin2hex(random_bytes(5)));

        // Map currency to Nicky's blockchainAssetId
        $blockchainAssetId = match ($_SESSION['_currency']) {
            'USD' => 'USD.USD',
            'EUR' => 'EUR.EUR',
            default => throw new Exception('Unsupported currency: ' . $_SESSION['_currency']),
        };

        // Prepare the payload for the API
        $data = [
            'blockchainAssetId' => $blockchainAssetId,
            'amountExpectedNative' => $amountWhole,
            'billDetails' => [
                'invoiceReference' => $invoiceReference,
                'description' => $paymentDescription,
            ],
            'requester' => [
                'email' => $_SESSION['auth_email'],
                'name' => $_SESSION['auth_username'],
            ],
            'sendNotification' => true,
            'successUrl' => envi('APP_URL') . '/payment-success-nicky',
            'cancelUrl' => envi('APP_URL') . '/payment-cancel',
        ];

        $url = 'https://api-public.pay.nicky.me/api/public/PaymentRequestPublicApi/create';
        $apiKey = envi('NICKY_API_KEY');

        $client = new Client();

        try {
            $apiResponse = $client->request('POST', $url, [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $data,
            ]);

            $body = json_decode($apiResponse->getBody()->getContents(), true);

            if (isset($body['bill']['shortId'])) {
                $paymentUrl = "https://pay.nicky.me/home?paymentId=" . $body['bill']['shortId'];
                $_SESSION['nicky_shortId'] = $body['bill']['shortId'];
                $response->getBody()->write(json_encode(['invoice_url' => $paymentUrl]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
            } else {
                throw new Exception('API response does not contain a payment URL.');
            }
        } catch (GuzzleException $e) {
            unset($_SESSION['nicky_shortId']);

            $errorResponse = [
                'error' => 'We encountered an issue while processing your payment.',
                'details' => $e->getMessage(),
            ];

            $response->getBody()->write(json_encode($errorResponse));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }
    }
    
    public function successStripe(Request $request, Response $response)
    {
        $session_id = $request->getQueryParams()['session_id'] ?? null;
        $db = $this->container->get('db');

        if ($session_id) {
            \Stripe\Stripe::setApiKey(envi('STRIPE_SECRET_KEY'));

            try {
                $session = \Stripe\Checkout\Session::retrieve($session_id);
                $amountPaid = $session->amount_total; // Amount paid, in cents
                $amount = $amountPaid / 100;
                $amountPaidFormatted = number_format($amount, 2, '.', '');
                $paymentIntentId = $session->payment_intent;

                $invoiceId        = $session->metadata->invoice_id   ?? null;
                $userId           = $session->metadata->user_id      ?? null;
                $paymentTypeLabel = $session->metadata->payment_type ?? '';

                if ((!$invoiceId || !$paymentTypeLabel || !$userId) && $paymentIntentId) {
                    $pi = \Stripe\PaymentIntent::retrieve($paymentIntentId);
                    $invoiceId        = $invoiceId        ?: ($pi->metadata->invoice_id   ?? null);
                    $userId           = $userId           ?: ($pi->metadata->user_id      ?? null);
                    $paymentTypeLabel = $paymentTypeLabel ?: ($pi->metadata->payment_type ?? '');
                }

                $paymentType = 'unknown';
                if (stripos($paymentTypeLabel, 'invoice') !== false) {
                    $paymentType = 'invoice';
                } elseif (stripos($paymentTypeLabel, 'deposit') !== false) {
                    $paymentType = 'deposit';
                }

                $link = $paymentType === 'invoice' && $invoiceId ? "/invoice/{$invoiceId}" : '/deposit';

                $isPositiveNumberWithTwoDecimals = filter_var($amount, FILTER_VALIDATE_FLOAT) !== false && preg_match('/^\d+(\.\d{1,2})?$/', $amount);

                if (($session->payment_status ?? null) !== 'paid') {
                    $this->container->get('flash')->addMessage('error', 'Payment was not completed. If you believe this is a mistake, please try again or contact support.');
                    return $response->withHeader('Location', $link)->withStatus(302);
                }

                if ($isPositiveNumberWithTwoDecimals) {
                    $userId = $_SESSION["auth_user_id"] ?? $userId;

                    try {
                        $db->beginTransaction();
                        $currentDateTime = new \DateTime();
                        $date = $currentDateTime->format('Y-m-d H:i:s.v');

                        $relatedType = $paymentType === 'invoice' ? 'invoice' : 'deposit';
                        $relatedId = $paymentType === 'invoice' ? $invoiceId : 0;
                        $category    = $paymentType === 'invoice' ? 'invoice' : 'deposit';
                        $description = $paymentType === 'invoice'
                            ? "Payment for Invoice #{$invoiceId}"
                            : "Account balance deposit";

                        $db->insert('transactions', [
                            'user_id'             => $userId,
                            'related_entity_type' => $relatedType,
                            'related_entity_id'   => $relatedId,
                            'type'                => 'debit',
                            'category'            => $category,
                            'description'         => $description,
                            'amount'              => $amount,
                            'currency'            => $_SESSION['_currency'],
                            'status'              => 'completed',
                            'created_at'          => $date
                        ]);

                        if ($paymentType === 'invoice' && $invoiceId) {
                            $currentDateTime = new \DateTime();
                            $updatedAt = $currentDateTime->format('Y-m-d H:i:s.v');

                            $db->update(
                                'invoices',
                                [
                                    'payment_status' => 'paid',
                                    'updated_at' => $updatedAt
                                ],
                                [
                                    'id' => $invoiceId,
                                    'user_id' => $userId
                                ]
                            );
                        }

                        if ($paymentType === 'deposit') {
                            $db->exec(
                                'UPDATE users SET account_balance = (account_balance + ?) WHERE id = ?',
                                [
                                    $amount,
                                    $userId
                                ]
                            );
                        }

                        $db->commit();
                    } catch (Exception $e) {
                        $db->rollBack();

                        $this->container->get('flash')->addMessage('error', 'Failure: '.$e->getMessage());
                        return $response->withHeader('Location', $link)->withStatus(302);
                    }
                    
                    $message = $paymentType === 'invoice'
                        ? "Invoice payment received successfully. Your service will be processed shortly."
                        : "Deposit added successfully. Your account balance has been updated.";

                    try {
                        provisionService($db, $invoiceId, $_SESSION["auth_user_id"]);
                    } catch (Exception $e) {
                        $this->container->get('flash')->addMessage('error', 'Failure: '.$e->getMessage());
                        return $response->withHeader('Location', $link)->withStatus(302);
                    }

                    $this->container->get('flash')->addMessage('success', $message);
                    return $response->withHeader('Location', $link)->withStatus(302);
                } else {
                    $this->container->get('flash')->addMessage('error', 'Invalid entry: Amount must be positive. Please enter a valid amount.');
                    return $response->withHeader('Location', $link)->withStatus(302);
                }
            } catch (\Exception $e) {
                $currentDateTime = new \DateTime();
                $createdAt = $currentDateTime->format('Y-m-d H:i:s.v');

                $db->update('orders', ['status' => 'failed'], ['invoice_id' => $invoiceId]);

                $db->insert('service_logs', [
                    'service_id' => 0,
                    'event' => 'payment_failed',
                    'actor_type' => 'system',
                    'actor_id' => $_SESSION["auth_user_id"],
                    'details' => 'invoice ' . $invoiceId . '|' . $e->getMessage(),
                    'created_at' => $createdAt
                ]);

                $this->container->get('flash')->addMessage('error', 'We encountered an issue while processing your order. Please contact our support team');
                return $response->withHeader('Location', $link)->withStatus(302);
            }
        }
    }

    public function successLiqPay(Request $request, Response $response)
    {
        $q = $request->getQueryParams();
        $orderId = $q['order_id'] ?? null;
        $db = $this->container->get('db');

        // Fallback link if we can’t infer destination
        $fallbackLink = '/deposit';

        if (!$orderId) {
            $this->container->get('flash')->addMessage('error', 'Missing order ID.');
            return $response->withHeader('Location', $fallbackLink)->withStatus(302);
        }

        // Determine intent (invoice vs deposit) from our order_id prefix
        $firstToken = explode('.', $orderId)[0]; // e.g., inv-123 or dep-45
        $paymentType = str_starts_with($firstToken, 'inv-') ? 'invoice' : 'deposit';
        $invoiceId = null;
        if ($paymentType === 'invoice') {
            $invoiceId = (int)substr($firstToken, 4);
        }
        $link = ($paymentType === 'invoice' && $invoiceId) ? "/invoice/{$invoiceId}" : $fallbackLink;

        try {
            $liqpay = new \LiqPay(envi('LIQPAY_PUBLIC_KEY'), envi('LIQPAY_PRIVATE_KEY'));
            // Poll LiqPay for the status of this order
            $resp = $liqpay->api('payment/status', [
                'version'  => 3,
                'action'   => 'status',
                'order_id' => $orderId,
            ]);

            // Basic sanity checks
            if (!is_object($resp)) {
                $this->container->get('flash')->addMessage('error', 'Unable to verify payment status.');
                return $response->withHeader('Location', $link)->withStatus(302);
            }

            // Map LiqPay status to our states
            // Common statuses: success, failure, error, processing, sandbox, reversed, subscribed, unsubscribed, 3ds_verify
            $status = strtolower((string)($resp->status ?? ''));
            $isPaid = ($status === 'success' || $status === 'sandbox');

            if (!$isPaid) {
                $this->container->get('flash')->addMessage('error', 'Payment not completed (status: ' . $status . ').');
                return $response->withHeader('Location', $link)->withStatus(302);
            }

            // Amount & currency returned by LiqPay
            $amount = (float)($resp->amount ?? 0);
            $currency = (string)($resp->currency ?? ($_SESSION['_currency'] ?? 'USD'));
            $amountValid = $amount > 0 && preg_match('/^\d+(\.\d{1,2})?$/', (string)$amount);

            if (!$amountValid) {
                $this->container->get('flash')->addMessage('error', 'Invalid paid amount.');
                return $response->withHeader('Location', $link)->withStatus(302);
            }

            $userId = $_SESSION['auth_user_id'] ?? null;
            if (!$userId) {
                // As a fallback you could look up the invoice owner if $invoiceId is set.
                // Keeping it simple & consistent with your existing flow:
                $this->container->get('flash')->addMessage('error', 'User session missing.');
                return $response->withHeader('Location', $link)->withStatus(302);
            }

            // Record transaction and update balances/statuses
            try {
                $db->beginTransaction();
                $now = (new \DateTime())->format('Y-m-d H:i:s.v');

                $relatedType = $paymentType === 'invoice' ? 'invoice' : 'deposit';
                $relatedId   = $paymentType === 'invoice' ? $invoiceId : 0;
                $category    = $paymentType === 'invoice' ? 'invoice' : 'deposit';
                $description = $paymentType === 'invoice'
                    ? "Payment for Invoice #{$invoiceId}"
                    : "Account balance deposit";

                $db->insert('transactions', [
                    'user_id'             => $userId,
                    'related_entity_type' => $relatedType,
                    'related_entity_id'   => $relatedId,
                    'type'                => 'debit',
                    'category'            => $category,
                    'description'         => $description,
                    'amount'              => $amount,
                    'currency'            => $currency,
                    'status'              => 'completed',
                    'created_at'          => $now
                ]);

                if ($paymentType === 'invoice' && $invoiceId) {
                    $db->update(
                        'invoices',
                        ['payment_status' => 'paid', 'updated_at' => $now],
                        ['id' => $invoiceId, 'user_id' => $userId]
                    );
                } else {
                    $db->exec(
                        'UPDATE users SET account_balance = (account_balance + ?) WHERE id = ?',
                        [$amount, $userId]
                    );
                }

                $db->commit();
            } catch (\Exception $e) {
                $db->rollBack();

                $this->container->get('flash')->addMessage('error', 'Failure: ' . $e->getMessage());
                return $response->withHeader('Location', $link)->withStatus(302);
            }

            // Optional: provision on successful invoice payment
            if ($paymentType === 'invoice' && $invoiceId) {
                try {
                    provisionService($db, $invoiceId, $userId);
                } catch (\Exception $e) {
                    $this->container->get('flash')->addMessage('error', 'Failure: ' . $e->getMessage());
                    return $response->withHeader('Location', $link)->withStatus(302);
                }
            }

            $msg = ($paymentType === 'invoice')
                ? 'Invoice payment received successfully. Your service will be processed shortly.'
                : 'Deposit added successfully. Your account balance has been updated.';
            $this->container->get('flash')->addMessage('success', $msg);

            return $response->withHeader('Location', $link)->withStatus(302);
        } catch (\Exception $e) {
            // Best-effort logging similar to your Stripe handler
            $now = (new \DateTime())->format('Y-m-d H:i:s.v');
            if ($paymentType === 'invoice' && $invoiceId) {
                $db->update('orders', ['status' => 'failed'], ['invoice_id' => $invoiceId]);
            }
            $db->insert('service_logs', [
                'service_id' => 0,
                'event'      => 'payment_failed',
                'actor_type' => 'system',
                'actor_id'   => $_SESSION['auth_user_id'] ?? 0,
                'details'    => 'order ' . $orderId . '|' . $e->getMessage(),
                'created_at' => $now
            ]);

            $this->container->get('flash')->addMessage('error', 'We encountered an issue while processing your order. Please contact our support team.');
            return $response->withHeader('Location', $link)->withStatus(302);
        }
    }

    public function successPlata(Request $request, Response $response)
    {
        $q        = $request->getQueryParams();
        $orderId  = $q['order_id']  ?? null;

        if (!$orderId) {
            $this->container->get('flash')->addMessage('error', 'Missing order ID.');
            return $response->withHeader('Location', '/deposit')->withStatus(302);
        }

        $firstToken  = explode('.', $orderId)[0];
        $isInvoice   = str_starts_with($firstToken, 'inv-');
        $invoiceId   = $isInvoice ? (int)substr($firstToken, 4) : null;
        $redirectUrl = ($isInvoice && $invoiceId) ? "/invoice/{$invoiceId}" : '/deposit';

        // We need Plata's invoiceId for polling:
        $db = $this->container->get('db');
        $plataInvoiceId = $_SESSION['plata_invoice_id'] ?? null;
        unset($_SESSION['plata_invoice_id']);

        if (!$plataInvoiceId) {
            $this->container->get('flash')->addMessage('error', 'Missing Plata invoice identifier.');
            return $response->withHeader('Location', $redirectUrl)->withStatus(302);
        }

        // Poll Plata 2–3 times quickly to catch the just-finished payment
        $statusPayload = null;
        for ($i = 0; $i < 3; $i++) {
            $statusPayload = $this->plataFetchStatus($plataInvoiceId);
            if ($statusPayload && !empty($statusPayload['status']) && $statusPayload['status'] !== 'processing') {
                break;
            }
            usleep(250000); // 250ms between tries (tune as you wish)
        }

        if (!$statusPayload) {
            $this->container->get('flash')->addMessage('error', 'Unable to verify payment status. Please try again shortly.');
            return $response->withHeader('Location', $redirectUrl)->withStatus(302);
        }

        $plataStatus = strtolower($statusPayload['status'] ?? 'processing');
        $txnId       = $statusPayload['invoiceId'] ?? $plataInvoiceId;
        $amountMinor = $statusPayload['finalAmount'] ?? ($statusPayload['amount'] ?? null);
        $currencyIso = (int)($statusPayload['ccy'] ?? 980);
        $amount      = $amountMinor !== null ? round(((int)$amountMinor) / 100, 2) : null;
        $currency    = ($currencyIso === 980) ? 'UAH' : 'UAH'; // Plata is UAH today

        // Decide action by status
        if ($plataStatus === 'success') {
            try {
                $now = (new \DateTime())->format('Y-m-d H:i:s');

                // Insert transaction if not exists
                $db->insert('transactions', [
                    'user_id'             => $_SESSION['auth_user_id'] ?? null,
                    'related_entity_type' => $isInvoice ? 'invoice' : 'deposit',
                    'related_entity_id'   => $isInvoice ? $invoiceId : 0,
                    'type'                => 'debit',
                    'category'            => $isInvoice ? 'invoice' : 'deposit',
                    'description'         => $isInvoice ? "Payment for Invoice #{$invoiceId}" : "Account balance deposit",
                    'amount'              => $amount ?? 0,
                    'currency'            => $currency,
                    'status'              => 'completed',
                    'created_at'          => $now
                ]);

                if ($isInvoice && $invoiceId) {
                    $db->update('invoices', [
                        'payment_status' => 'paid',
                        'updated_at'     => $now
                    ], ['id' => $invoiceId]);

                    // Optional provisioning
                    try {
                        provisionService($db, $invoiceId, $_SESSION['auth_user_id'] ?? null);
                    } catch (\Throwable $e) {
                        $db->insert('service_logs', [
                            'service_id' => 0,
                            'event'      => 'provision_failed',
                            'actor_type' => 'system',
                            'actor_id'   => $_SESSION['auth_user_id'] ?? 0,
                            'details'    => 'invoice ' . $invoiceId . '|' . $e->getMessage(),
                            'created_at' => $now
                        ]);
                    }
                } else {
                    if (!empty($_SESSION['auth_user_id']) && $amount !== null) {
                        $db->exec('UPDATE users SET account_balance = (account_balance + ?) WHERE id = ?', [
                            $amount,
                            $_SESSION['auth_user_id']
                        ]);
                    }
                }

            } catch (\Throwable $e) {
                $this->container->get('flash')->addMessage('danger', 'Payment recorded but post-processing failed. Support has been notified.');
                return $response->withHeader('Location', $redirectUrl)->withStatus(302);
            }

            $this->container->get('flash')->addMessage('success', 'Payment successful.');
            return $response->withHeader('Location', $redirectUrl)->withStatus(302);
        }

        if ($plataStatus === 'failure' || $plataStatus === 'expired') {
            $this->container->get('flash')->addMessage('danger', 'Payment failed or expired.');
            return $response->withHeader('Location', $redirectUrl)->withStatus(302);
        }

        // processing / unknown
        $this->container->get('flash')->addMessage('warning', 'Payment is processing. It will be confirmed shortly.');
        return $response->withHeader('Location', $redirectUrl)->withStatus(302);
    }

    private function plataFetchStatus(string $invoiceId): ?array
    {
        $token = envi('PLATA_TOKEN');
        $url   = "https://api.monobank.ua/api/merchant/invoice/status?invoiceId=" . rawurlencode($invoiceId);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['X-Token: ' . $token],
            CURLOPT_TIMEOUT        => 10,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($code !== 200 || $raw === false) {
            // optional: log error
            return null;
        }
        $json = json_decode($raw, true);
        return is_array($json) ? $json : null;
    }

    public function successAdyen(Request $request, Response $response)
    {
        $sessionId = $request->getQueryParams()['sessionId'] ?? null;
        $sessionResult = $request->getQueryParams()['sessionResult'] ?? null;
        $db = $this->container->get('db');

        $client = new Client([
            'base_uri' => envi('ADYEN_BASE_URI'),
            'timeout'  => 2.0,
        ]);

        try {
            $apicall = $client->request('GET', "sessions/$sessionId", [
                'query' => ['sessionResult' => $sessionResult],
                'headers' => [
                    'X-API-Key' => envi('ADYEN_API_KEY'),
                    'Content-Type' => 'application/json',
                ],
            ]);

            $data = json_decode($apicall->getBody(), true);

            $status = $data['status'] ?? 'unknown';
            if ($status == 'completed') {
                echo $status;
                $this->container->get('flash')->addMessage('success', 'Deposit successfully added. The registrar\'s account balance has been updated.');
                return $response->withHeader('Location', '/deposit')->withStatus(302);
            } else {
                $this->container->get('flash')->addMessage('error', 'We encountered an issue while processing your payment. Please check your payment details and try again.');
                return $response->withHeader('Location', '/deposit')->withStatus(302);
            }

        } catch (RequestException $e) {
            $this->container->get('flash')->addMessage('error', 'Failure: '.$e->getMessage());
            return $response->withHeader('Location', '/deposit')->withStatus(302);
        }
    }
    
    public function successCrypto(Request $request, Response $response)
    {
        $client = new Client();
        
        $queryParams = $request->getQueryParams();

        if (!isset($queryParams['paymentId']) || $queryParams['paymentId'] == 0) {
            $this->container->get('flash')->addMessage('info', 'No paymentId provided.');
            return view($response,'admin/financials/success-crypto.twig');
        } else {
            $paymentId = $queryParams['paymentId'];
            $apiKey = envi('NOW_API_KEY');
            $url = 'https://api.nowpayments.io/v1/payment/' . $paymentId;

            try {
                $apiclient = $client->request('GET', $url, [
                    'headers' => [
                        'x-api-key' => $apiKey,
                    ],
                ]);

                $statusCode = $apiclient->getStatusCode();
                $body = $apiclient->getBody()->getContents();
                $data = json_decode($body, true);

                if ($statusCode === 200) { // Check if the request was successful
                    if (isset($data['payment_status']) && $data['payment_status'] === 'finished') {
                        try {
                            $amount = $data['pay_amount'];
                            $merchantReference = hex2bin($data['order_description']);
                            $delimiter = '|';

                            // Split to get the original components
                            list($registrarId, $uniqueIdentifier) = explode($delimiter, $merchantReference, 2);

                            $isPositiveNumberWithTwoDecimals = filter_var($amount, FILTER_VALIDATE_FLOAT) !== false && preg_match('/^\d+(\.\d{1,2})?$/', $amount);

                            if ($isPositiveNumberWithTwoDecimals) {
                                $db->beginTransaction();

                                try {
                                    $currentDateTime = new \DateTime();
                                    $date = $currentDateTime->format('Y-m-d H:i:s.v');
                                    $db->insert(
                                        'statement',
                                        [
                                            'registrar_id' => $registrarId,
                                            'date' => $date,
                                            'command' => 'create',
                                            'domain_name' => 'deposit',
                                            'length_in_months' => 0,
                                            'fromS' => $date,
                                            'toS' => $date,
                                            'amount' => $amount
                                        ]
                                    );

                                    $db->insert(
                                        'payment_history',
                                        [
                                            'registrar_id' => $registrarId,
                                            'date' => $date,
                                            'description' => 'registrar balance deposit via Crypto ('.$data['payment_id'].')',
                                            'amount' => $amount
                                        ]
                                    );
                                    
                                    $db->exec(
                                        'UPDATE registrar SET accountBalance = (accountBalance + ?) WHERE id = ?',
                                        [
                                            $amount,
                                            $registrarId,
                                        ]
                                    );
                                    
                                    $db->commit();
                                } catch (Exception $e) {
                                    $this->container->get('flash')->addMessage('success', 'Request failed: ' . $e->getMessage());
                                    return $response->withHeader('Location', '/payment-success-crypto')->withStatus(302);
                                }
                                
                                return view($response, 'admin/financials/success-crypto.twig', [
                                    'status' => $data['payment_status'],
                                    'paymentId' => $paymentId
                                ]);
                            } else {
                                $this->container->get('flash')->addMessage('success', 'Request failed. Reload page.');
                                return $response->withHeader('Location', '/payment-success-crypto')->withStatus(302);
                            }
                        } catch (\Exception $e) {
                            $this->container->get('flash')->addMessage('success', 'Request failed: ' . $e->getMessage());
                            return $response->withHeader('Location', '/payment-success-crypto')->withStatus(302);
                        }
                    } else if (isset($data['payment_status']) && $data['payment_status'] === 'expired') {
                        return view($response, 'admin/financials/success-crypto.twig', [
                            'status' => $data['payment_status'],
                            'paymentId' => $paymentId
                        ]);
                    } else {
                        return view($response, 'admin/financials/success-crypto.twig', [
                            'status' => $data['payment_status'],
                            'paymentId' => $paymentId
                        ]);
                    }
                } else {
                    $this->container->get('flash')->addMessage('success', 'Failed to retrieve payment information. Status Code: ' . $statusCode);
                    return $response->withHeader('Location', '/payment-success-crypto')->withStatus(302);
                }

            } catch (GuzzleException $e) {
                $this->container->get('flash')->addMessage('success', 'Request failed: ' . $e->getMessage());
                return $response->withHeader('Location', '/payment-success-crypto')->withStatus(302);
            }
        }
        
        return view($response,'admin/financials/success-crypto.twig');
    }

    public function successNicky(Request $request, Response $response)
    {
        $client = new Client();
        $sessionShortId = $_SESSION['nicky_shortId'] ?? null;

        if (!$sessionShortId) {
            $this->container->get('flash')->addMessage('info', 'No payment reference found in session.');
            return view($response, 'admin/financials/success-nicky.twig');
        }

        $url = 'https://api-public.pay.nicky.me/api/public/PaymentRequestPublicApi/get-by-short-id?shortId=' . urlencode($sessionShortId);
        $apiKey = envi('NICKY_API_KEY');

        try {
            $apiResponse = $client->request('GET', $url, [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $statusCode = $apiResponse->getStatusCode();
            $responseBody = json_decode($apiResponse->getBody()->getContents(), true);

            if ($statusCode === 200 && isset($responseBody['status'])) {
                $status = $responseBody['status'];
                $amount = $responseBody['amountNative'] ?? 0;
                $paymentId = $responseBody['id'] ?? null;
                $description = $responseBody['bill']['description'] ?? 'No description';

                if ($status === "None" || $status === "PaymentValidationRequired" || $status === "PaymentPending") {
                    return view($response, 'admin/financials/success-nicky.twig', [
                        'status' => $status,
                        'paymentId' => $paymentId
                    ]);
                } elseif ($status === "Finished") {
                    // Record the successful transaction in the database
                    $db = $this->container->get('db');
                    $registrarId = $_SESSION["auth_user_id"];

                    $currentDateTime = new \DateTime();
                    $date = $currentDateTime->format('Y-m-d H:i:s.v');

                    $db->beginTransaction();
                    try {
                        $db->insert(
                            'statement',
                            [
                                'registrar_id' => $registrarId,
                                'date' => $date,
                                'command' => 'create',
                                'domain_name' => 'deposit',
                                'length_in_months' => 0,
                                'fromS' => $date,
                                'toS' => $date,
                                'amount' => $amount,
                            ]
                        );

                        $db->insert(
                            'payment_history',
                            [
                                'registrar_id' => $registrarId,
                                'date' => $date,
                                'description' => 'Registrar balance deposit via Nicky ('.$paymentId.')',
                                'amount' => $amount,
                            ]
                        );

                        $db->exec(
                            'UPDATE registrar SET accountBalance = (accountBalance + ?) WHERE id = ?',
                            [
                                $amount,
                                $registrarId,
                            ]
                        );

                        $db->commit();
                    } catch (\Exception $e) {
                        $db->rollBack();
                        $this->container->get('flash')->addMessage('error', 'Transaction recording failed: ' . $e->getMessage());
                        return $response->withHeader('Location', '/payment-success-nicky')->withStatus(302);
                    }

                    unset($_SESSION['nicky_shortId']);

                    // Redirect to success page with details
                    return view($response, 'admin/financials/success-nicky.twig', [
                        'status' => $status,
                        'paymentId' => $paymentId,
                    ]);
                } else {
                    unset($_SESSION['nicky_shortId']);
                    
                    // Handle unexpected statuses
                    return view($response, 'admin/financials/success-nicky.twig', [
                        'status' => $status,
                        'paymentId' => $paymentId,
                    ]);
                }
            } else {
                unset($_SESSION['nicky_shortId']);
                $this->container->get('flash')->addMessage('error', 'Failed to retrieve payment information.');
                return $response->withHeader('Location', '/payment-success-nicky')->withStatus(302);
            }
        } catch (GuzzleException $e) {
            $this->container->get('flash')->addMessage('error', 'Request failed: ' . $e->getMessage());
            return $response->withHeader('Location', '/payment-success-nicky')->withStatus(302);
        }
    }

    public function webhookAdyen(Request $request, Response $response)
    {
        $data = json_decode($request->getBody()->getContents(), true);
        $db = $this->container->get('db');
        
        // Basic auth credentials
        $username = envi('ADYEN_BASIC_AUTH_USER');
        $password = envi('ADYEN_BASIC_AUTH_PASS');

        // Check for basic auth header
        if (!isset($_SERVER['PHP_AUTH_USER'])) {
            return $response->withStatus(401)->withHeader('WWW-Authenticate', 'Basic realm="MyRealm"');
        }

        // Validate username and password
        if ($_SERVER['PHP_AUTH_USER'] != $username || $_SERVER['PHP_AUTH_PW'] != $password) {
            $response = $response->withStatus(403)->withHeader('Content-Type', 'application/json');
            $response->getBody()->write(json_encode(['forbidden' => true]));
            return $response;
        }
        
        $hmac = new \Adyen\Util\HmacSignature();
        $hmacKey = envi('ADYEN_HMAC_KEY');

        foreach ($data['notificationItems'] as $item) {
            $notificationRequestItem = $item['NotificationRequestItem'];
            
            if (isset($notificationRequestItem['eventCode']) && $notificationRequestItem['eventCode'] == 'AUTHORISATION' && $notificationRequestItem['success'] == 'true') {
                $merchantReference = $notificationRequestItem['merchantReference'] ?? null;
                $paymentStatus = $notificationRequestItem['success'] ?? null;

                if ($merchantReference && $paymentStatus && $hmac->isValidNotificationHMAC($hmacKey, $notificationRequestItem)) {
                    try {
                        $amountPaid = $notificationRequestItem['amount']['value']; // Amount paid, in cents
                        $amount = $amountPaid / 100;
                        $amountPaidFormatted = number_format($amount, 2, '.', '');
                        $paymentIntentId = $notificationRequestItem['reason'];
                        $merchantReference = hex2bin($merchantReference);
                        $delimiter = '|';

                        // Split to get the original components
                        list($registrarId, $uniqueIdentifier) = explode($delimiter, $merchantReference, 2);

                        $isPositiveNumberWithTwoDecimals = filter_var($amount, FILTER_VALIDATE_FLOAT) !== false && preg_match('/^\d+(\.\d{1,2})?$/', $amount);

                        if ($isPositiveNumberWithTwoDecimals) {
                            $db->beginTransaction();

                            try {
                                $currentDateTime = new \DateTime();
                                $date = $currentDateTime->format('Y-m-d H:i:s.v');
                                $db->insert(
                                    'statement',
                                    [
                                        'registrar_id' => $registrarId,
                                        'date' => $date,
                                        'command' => 'create',
                                        'domain_name' => 'deposit',
                                        'length_in_months' => 0,
                                        'fromS' => $date,
                                        'toS' => $date,
                                        'amount' => $amount
                                    ]
                                );

                                $db->insert(
                                    'payment_history',
                                    [
                                        'registrar_id' => $registrarId,
                                        'date' => $date,
                                        'description' => 'registrar balance deposit via Adyen ('.$paymentIntentId.')',
                                        'amount' => $amount
                                    ]
                                );
                                
                                $db->exec(
                                    'UPDATE registrar SET accountBalance = (accountBalance + ?) WHERE id = ?',
                                    [
                                        $amount,
                                        $registrarId,
                                    ]
                                );
                                
                                $db->commit();
                            } catch (Exception $e) {
                                $db->rollBack();

                                $response = $response->withStatus(500)->withHeader('Content-Type', 'application/json');
                                $response->getBody()->write(json_encode(['failure' => true]));
                                return $response;
                            }
                            
                            $response->getBody()->write(json_encode(['received' => true]));
                            return $response->withHeader('Content-Type', 'application/json');
                        } else {
                            $response = $response->withStatus(500)->withHeader('Content-Type', 'application/json');
                            $response->getBody()->write(json_encode(['failure' => true]));
                            return $response;
                        }
                    } catch (\Exception $e) {
                        $response = $response->withStatus(500)->withHeader('Content-Type', 'application/json');
                        $response->getBody()->write(json_encode(['failure' => true]));
                        return $response;
                    }
                }            
            } else {
                $response = $response->withStatus(500)->withHeader('Content-Type', 'application/json');
                $response->getBody()->write(json_encode(['failure' => true]));
                return $response;
            }
        }
        
        $response = $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode(['failure' => true]));
        return $response;
    }

    public function cancel(Request $request, Response $response)
    {
        $type = $request->getQueryParams()['type'] ?? '';
        $redirectRoute = match ($type) {
            'deposit' => 'deposit',
            'invoice' => 'invoices',
            'order' => 'orders',
            default => 'home',
        };
        $name = match ($type) {
            'deposit' => 'deposit',
            'invoice' => 'invoices',
            'order' => 'orders',
            default => 'home',
        };

        return view($response, 'admin/financials/cancel.twig', [
            'type' => $type,
            'redirectRoute' => $redirectRoute,
            'name' => $name,
        ]);
    }
}