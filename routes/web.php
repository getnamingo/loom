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

use App\Controllers\Auth\AuthController;
use App\Controllers\Auth\PasswordController;
use App\Controllers\HomeController;
use App\Controllers\ProfileController;
use App\Controllers\UsersController;
use App\Controllers\FinancialsController;
use App\Controllers\OrdersController;
use App\Controllers\ServicesController;
use App\Controllers\ContactsController;
use App\Controllers\ProvidersController;
use App\Controllers\SupportController;
use App\Controllers\MosapiController;
use App\Controllers\SparkController;
use App\Middleware\AuthMiddleware;
use App\Middleware\GuestMiddleware;
use Slim\Exception\HttpNotFoundException;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Response;
use Tqdev\PhpCrudApi\Api;
use Tqdev\PhpCrudApi\Config\Config;

$app->get('/', HomeController::class .':index')->setName('index');
$app->get('/terms', HomeController::class .':terms')->setName('terms');
$app->get('/privacy', HomeController::class .':privacy')->setName('privacy');
$app->get('/contact', HomeController::class .':contact')->setName('contact');
$app->get('/report-abuse', HomeController::class .':reportabuse')->setName('reportabuse');

$app->get('/validation/{token}', HomeController::class .':validation')->setName('validation');
$app->get('/whois', HomeController::class .':whois')->setName('whois');
$app->post('/lookup', HomeController::class .':lookup')->setName('lookup');
$app->map(['GET', 'POST'], '/registrant-contact/{domain}', HomeController::class .':registrantContact')->setName('registrantContact');

$app->post('/spark/domain/check', [SparkController::class, 'domainCheck']);

$app->group('', function ($route) {
    $route->get('/register', AuthController::class . ':createRegister')->setName('register');
    $route->post('/register', AuthController::class . ':register');
    $route->get('/login', AuthController::class . ':createLogin')->setName('login');
    $route->map(['GET', 'POST'], '/login/verify', AuthController::class . ':verify2FA')->setName('verify2FA');
    $route->post('/login', AuthController::class . ':login');

    $route->post('/webauthn/login/challenge', AuthController::class . ':getLoginChallenge')->setName('webauthn.login.challenge');
    $route->post('/webauthn/login/verify', AuthController::class . ':verifyLogin')->setName('webauthn.login.verify');

    $route->get('/verify-email', AuthController::class.':verifyEmail')->setName('verify.email');
    $route->get('/verify-email-resend',AuthController::class.':verifyEmailResend')->setName('verify.email.resend');

    $route->get('/forgot-password', PasswordController::class . ':createForgotPassword')->setName('forgot.password');
    $route->post('/forgot-password', PasswordController::class . ':forgotPassword');
    $route->get('/reset-password', PasswordController::class.':resetPassword')->setName('reset.password');
    $route->get('/update-password', PasswordController::class.':createUpdatePassword')->setName('update.password');
    $route->post('/update-password', PasswordController::class.':updatePassword');

    $route->map(['GET', 'POST'], '/payment/{gateway}/return', FinancialsController::class . ':paymentReturn')->setName('payment.return');
    $route->post('/payment/{gateway}/webhook', FinancialsController::class . ':paymentWebhook')->setName('payment.webhook');
})->add(new GuestMiddleware($container));

$app->group('', function ($route) {
    $route->get('/dashboard', HomeController::class .':dashboard')->setName('home');

    $route->get('/users', UsersController::class .':listUsers')->setName('listUsers');
    $route->map(['GET', 'POST'], '/user/create', UsersController::class . ':createUser')->setName('createUser');
    $route->get('/user/update/{user}', UsersController::class . ':updateUser')->setName('updateUser');
    $route->post('/user/update', UsersController::class . ':updateUserProcess')->setName('updateUserProcess');
    $route->get('/user/impersonate/{user}', UsersController::class . ':impersonateUser')->setName('impersonateUser');
    $route->get('/leave_impersonation', UsersController::class . ':leave_impersonation')->setName('leave_impersonation');

    $route->get('/orders', OrdersController::class .':listOrders')->setName('listOrders');
    $route->map(['GET', 'POST'], '/orders/create', OrdersController::class .':createOrder')->setName('createOrder');
    $route->get('/orders/{order}', OrdersController::class .':viewOrder')->setName('viewOrder');
    $route->map(['GET', 'POST'], '/orders/{order}/activate', OrdersController::class .':activateOrder')->setName('activateOrder');
    $route->map(['GET', 'POST'], '/orders/{order}/cancel', OrdersController::class .':cancelOrder')->setName('cancelOrder');
    $route->map(['GET', 'POST'], '/orders/{order}/retry', OrdersController::class .':retryOrder')->setName('retryOrder');
    $route->map(['GET', 'POST'], '/orders/register/{domain}', OrdersController::class .':registerOrder')->setName('registerOrder');
    $route->map(['GET', 'POST'], '/orders/transfer/{domain}', OrdersController::class .':transferOrder')->setName('transferOrder');
    $route->get('/orders/{order}/delete', OrdersController::class .':deleteOrder')->setName('deleteOrder');

    $route->get('/services', ServicesController::class .':listServices')->setName('listServices');
    $route->get('/services/{service}/edit', ServicesController::class . ':editService')->setName('editService');
    $route->post('/services/{service}/update', ServicesController::class . ':updateService')->setName('updateService');
    $route->map(['GET', 'POST'], '/services/{service}/renew', ServicesController::class . ':renewService')->setName('renewService');
    $route->get('/services/{service}/lock', ServicesController::class . ':lockService')->setName('lockService');
    $route->get('/services/{service}/{keytag}/deletesecdns', ServicesController::class . ':domainDeleteSecdns')->setName('domainDeleteSecdns');
    $route->get('/service-logs', ServicesController::class .':serviceLogs')->setName('serviceLogs');

    $route->get('/contacts', ContactsController::class .':listContacts')->setName('listContacts');
    $route->map(['GET', 'POST'], '/contact/create', ContactsController::class . ':createContact')->setName('createContact');
    $route->get('/contact/view/{contact}', ContactsController::class . ':viewContact')->setName('viewContact');
    $route->get('/contact/update/{contact}', ContactsController::class . ':updateContact')->setName('updateContact');
    $route->get('/contact/validate/{contact}', ContactsController::class . ':validateContact')->setName('validateContact');
    $route->post('/contact/update', ContactsController::class . ':updateContactProcess')->setName('updateContactProcess');
    $route->post('/contact/approve', ContactsController::class . ':approveContact')->setName('approveContact');
    $route->map(['GET', 'POST'], '/contact/delete/{contact}', ContactsController::class . ':deleteContact')->setName('deleteContact');

    $route->get('/providers', ProvidersController::class .':listProviders')->setName('listProviders');
    $route->map(['GET', 'POST'], '/providers/create', ProvidersController::class . ':createProvider')->setName('createProvider');
    $route->get('/providers/{provider}/edit', ProvidersController::class . ':editProvider')->setName('editProvider');
    $route->post('/providers/{provider}/update', ProvidersController::class . ':updateProvider')->setName('updateProvider');
    $route->get('/providers/{provider}/delete', ProvidersController::class . ':deleteProvider')->setName('deleteProvider');

    $route->get('/invoices', FinancialsController::class .':invoices')->setName('invoices');
    $route->get('/invoice/{invoice}', FinancialsController::class . ':viewInvoice')->setName('viewInvoice');
    $route->map(['GET', 'POST'], '/invoice/{invoice}/pay', FinancialsController::class . ':payInvoice')->setName('payInvoice');
    $route->map(['GET', 'POST'], '/deposit', FinancialsController::class .':deposit')->setName('deposit');
    $route->map(['GET', 'POST'], '/balance-payment', FinancialsController::class .':balancePayment')->setName('balancePayment');
    $route->post('/payment/{gateway}/create', FinancialsController::class . ':createPayment')->setName('payment.create');
    $route->map(['GET', 'POST'], '/payment-cancel', FinancialsController::class .':cancel')->setName('cancel');
    $route->get('/transactions', FinancialsController::class .':transactions')->setName('transactions');

    $route->post('/clear-cache', HomeController::class .':clearCache')->setName('clearCache');

    $route->get('/support', SupportController::class .':view')->setName('ticketview');
    $route->map(['GET', 'POST'], '/support/new', SupportController::class .':newticket')->setName('newticket');
    $route->get('/ticket/{ticket}', SupportController::class . ':viewTicket')->setName('viewTicket');
    $route->post('/support/reply', SupportController::class . ':replyTicket')->setName('replyTicket');
    $route->post('/support/status', SupportController::class . ':statusTicket')->setName('statusTicket');

    $route->get('/profile', ProfileController::class .':profile')->setName('profile');
    $route->post('/profile/2fa', ProfileController::class .':activate2fa')->setName('activate2fa');
    $route->post('/profile/logout-everywhere', ProfileController::class . ':logoutEverywhereElse')->setName('profile.logout.everywhere');
    $route->get('/webauthn/register/challenge', ProfileController::class . ':getRegistrationChallenge')->setName('webauthn.register.challenge');
    $route->post('/webauthn/register/verify', ProfileController::class . ':verifyRegistration')->setName('webauthn.register.verify');
    $route->post('/token-well', ProfileController::class .':tokenWell')->setName('tokenWell');
    $route->post('/profile/update-contacts', ProfileController::class .':updateContacts')->setName('updateContacts');

    $route->get('/mode', HomeController::class .':mode')->setName('mode');
    $route->post('/theme', HomeController::class . ':selectTheme')->setName('select.theme');
    $route->get('/lang', HomeController::class .':lang')->setName('lang');
    $route->get('/logout', AuthController::class . ':logout')->setName('logout');
    $route->post('/change-password', PasswordController::class . ':changePassword')->setName('change.password');

    $route->get('/mosapi', MosapiController::class .':mosapi')->setName('mosapi');
    $route->get('/mosapi/refresh', MosapiController::class .':mosapiRefresh')->setName('mosapiRefresh');

    $route->get('/spark/orders', [SparkController::class, 'listOrders']);
    $route->get('/spark/transactions', [SparkController::class, 'listTransactions']);
})->add(new AuthMiddleware($container));

$app->any('/api[/{params:.*}]', function (
    ServerRequest $request,
    Response $response,
    $args
) use ($container) {
    $db = config('connections');
    if (config('default') == 'mysql') {
        $db_username = $db['mysql']['username'];
        $db_password = $db['mysql']['password'];
        $db_database = $db['mysql']['database'];
        $db_address = 'localhost';
    } elseif (config('default') == 'pgsql') {
        $db_username = $db['pgsql']['username'];
        $db_password = $db['pgsql']['password'];
        $db_database = $db['pgsql']['database'];
        $db_address = 'localhost';
    } elseif (config('default') == 'sqlite') {
        $db_username = null;
        $db_password = null;
        $db_database = null;
        $db_address = $db['sqlite']['database'];
    }
    $config = new Config([
        'driver' => config('default'),
        'username' => $db_username,
        'password' => $db_password,
        'database' => $db_database,
        'address' => $db_address,
        'basePath' => '/api',
        'cachePath' => __DIR__ . '/../cache',
        'middlewares' => 'customization,dbAuth,authorization,sanitation,multiTenancy',
        'authorization.tableHandler' => function ($operation, $tableName) {
        $restrictedTables = ['example_restricted_table'];
            return !in_array($tableName, $restrictedTables);
        },
        'authorization.columnHandler' => function ($operation, $tableName, $columnName) {
            if ($tableName == 'users' && $columnName == 'password') {
                return false;
            }
            return true;
        },
        'sanitation.handler' => function ($operation, $tableName, $column, $value) {
            return is_string($value) ? strip_tags($value) : $value;
        },
        'customization.beforeHandler' => function ($operation, $tableName, $request, $environment) {
            if (!isset($_SESSION['auth_logged_in']) || $_SESSION['auth_logged_in'] !== true) {
                header('HTTP/1.1 401 Unauthorized');
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => 'Authentication required']);
                exit;
            }
            $_SESSION['user'] = $_SESSION['auth_username'];
        },
        'customization.afterHandler' => function ($operation, $tableName, $response, $environment) {
            $bodyContent = (string) $response->getBody();
            $response->getBody()->rewind();
            $data = json_decode($bodyContent, true);

            // Sample table overwrite
            /* if ($tableName == 'domain') {
                if (isset($data['records']) && is_array($data['records'])) {
                    foreach ($data['records'] as &$record) {
                        if (isset($record['name']) && stripos($record['name'], 'xn--') === 0) {
                            $record['name_o'] = $record['name'];
                            $record['name'] = idn_to_utf8($record['name'], 0, INTL_IDNA_VARIANT_UTS46);
                        } else {
                            $record['name_o'] = $record['name'];
                        }
                    }
                    unset($record);
                }
            } */

            $modifiedBodyContent = json_encode($data, JSON_UNESCAPED_UNICODE);
            $stream = \Nyholm\Psr7\Stream::create($modifiedBodyContent);
            $response = $response->withBody($stream);
            $response = $response->withHeader('Content-Length', strlen($modifiedBodyContent));
            return $response;
        },
        'dbAuth.usersTable' => 'users',
        'dbAuth.usernameColumn' => 'email',
        'dbAuth.passwordColumn' => 'password',
        'dbAuth.returnedColumns' => 'email,roles_mask',
        'dbAuth.registerUser' => false,
        'multiTenancy.handler' => function ($operation, $tableName) {   
            if (isset($_SESSION['auth_roles']) && $_SESSION['auth_roles'] === 0) {
                return [];
            }

            $columnMap = [
                'services',
                'invoices',
                'statement',
                'support_tickets',
                'users_audit',
            ];

            if (in_array($tableName, $columnMap)) {
                return ['user_id' => $_SESSION['auth_user_id']];
            }

            return ['1' => '0'];
        },
    ]);
    $api = new Api($config);
    $response = $api->handle($request);
    return $response;
});

$app->add(function (Psr\Http\Message\ServerRequestInterface $request, Psr\Http\Server\RequestHandlerInterface $handler) {
    try {
        return $handler->handle($request);
    } catch (HttpNotFoundException $e) {
        $responseFactory = new \Nyholm\Psr7\Factory\Psr17Factory();
        $response = $responseFactory->createResponse();
        return $response
            ->withHeader('Location', '/')
            ->withStatus(302);
    }
});