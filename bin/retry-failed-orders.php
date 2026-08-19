<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../bootstrap/helper.php';

use App\Services\Provisioning\ProvisioningService;
use Pinga\Db\PdoDataSource;
use Pinga\Db\PdoDatabase;
use Dotenv\Dotenv;

// Load .env
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Setup DB
$dataSource = new PdoDataSource($_ENV['DB_DRIVER']);
$dataSource->setHostname($_ENV['DB_HOST']);
$dataSource->setPort((int)$_ENV['DB_PORT']);
$dataSource->setDatabaseName($_ENV['DB_DATABASE']);
$dataSource->setCharset('utf8mb4');
if ($_ENV['DB_USERNAME'] !== '') $dataSource->setUsername($_ENV['DB_USERNAME']);
if ($_ENV['DB_PASSWORD'] !== '') $dataSource->setPassword($_ENV['DB_PASSWORD']);

$db = PdoDatabase::fromDataSource($dataSource);
$provisioning = ProvisioningService::createDefault($db);

try {
    // 30-day threshold using PHP datetime
    $threshold = (new DateTime())->modify('-30 days')->format('Y-m-d H:i:s');

    $orders = $db->select(
        'SELECT id FROM orders WHERE status = ? AND created_at >= ? ORDER BY id ASC',
        ['failed', $threshold]
    );

    foreach ($orders ?? [] as $order) {
        $orderId = (int)$order['id'];
        echo "Re-attempting provisioning for order ID {$orderId}\n";

        try {
            $provisioned = $provisioning->provisionOrder($orderId);
            echo $provisioned
                ? "Order ID {$orderId} provisioned successfully.\n"
                : "Order ID {$orderId} no longer requires provisioning.\n";
        } catch (Throwable $exception) {
            echo "Order ID {$orderId} still failed: {$exception->getMessage()}\n";
        }
    }

} catch (Throwable $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}
