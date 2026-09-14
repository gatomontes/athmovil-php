<?php
declare(strict_types=1);

use AthMovil\{Amount, Payment};
use AthMovil\Exception\{ApiException, TransportException};
use AthMovil\Simulation\{Simulator, FileStore, Fault, Operation};

// This example is intentionally bound to PHP's local development server.
$host = $_SERVER['HTTP_HOST'] ?? '';
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    || !preg_match('/^(127\.0\.0\.1|localhost|\[::1\])(?::[0-9]+)?$/D', $host)) {
    http_response_code(403);
    exit('Run this simulation demo with the documented local PHP server command.');
}

header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$root = dirname(__DIR__, 2);
if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(503);
    exit('Run composer install from the repository root, then reload.');
}
require $root . '/vendor/autoload.php';

$storage = getenv('ATHMOVIL_DEMO_STORAGE');
$storage = $storage !== false && $storage !== '' ? $storage : $root . '/var/checkout';
foreach ([$storage, $storage . '/sessions'] as $directory) {
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        http_response_code(503);
        exit('Cannot create local demo storage. Check directory permissions.');
    }
}
session_save_path($storage . '/sessions');
session_name('ATHMOVIL_CHECKOUT_DEMO');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'path' => '/']);
session_start(); // Keep the session lock through mutations to serialize form submissions.
$_SESSION['business'] ??= 'checkout-' . bin2hex(random_bytes(16));
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$_SESSION['orders'] ??= [];

// There is no production mode, credential form, or live Client constructor here.
$simulation = new Simulator($_SESSION['business'], new FileStore($storage . '/simulator'));
$client = $simulation->client();

function escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function field(array $input, string $key, string $default = ''): string
{
    $value = $input[$key] ?? $default;
    if (!is_string($value) || strlen($value) > 4096) {
        throw new InvalidArgumentException('Invalid form field.');
    }
    return $value;
}

function defaults(): array
{
    return ['phone' => '7875550100', 'metadata1' => 'ORDER-1001', 'metadata2' => 'STORE-MANATI',
        'total' => '25.00', 'subtotal' => '25.00', 'tax' => '0.00', 'timeout' => '600',
        'items' => [
            ['name' => 'Coffee', 'description' => 'Puerto Rican ground coffee', 'quantity' => '2', 'price' => '8.00', 'tax' => '', 'metadata' => 'SKU-COFFEE'],
            ['name' => 'Mug', 'description' => 'Ceramic mug', 'quantity' => '1', 'price' => '9.00', 'tax' => '', 'metadata' => 'SKU-MUG'],
            ['name' => '', 'description' => '', 'quantity' => '1', 'price' => '0.00', 'tax' => '', 'metadata' => ''],
        ]];
}

function scenario(Simulator $simulation, Operation $operation, string $value): void
{
    if ($value === 'success') { return; }
    $fault = Fault::tryFrom($value);
    if ($fault === null) { throw new InvalidArgumentException('Unknown simulation scenario.'); }
    $simulation->failNext($operation, $fault);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        http_response_code(403);
        exit('This form expired or was already submitted. Reload the page and try again.');
    }
    // One-use token prevents replay/double-clicks, including partial refunds.
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    try {
        $action = field($_POST, 'action');
        if ($action === 'create') {
            $draft = [];
            foreach (['phone', 'metadata1', 'metadata2', 'total', 'subtotal', 'tax', 'timeout'] as $key) {
                $draft[$key] = field($_POST, $key);
            }
            $rows = $_POST['items'] ?? [];
            if (!is_array($rows) || !array_is_list($rows) || count($rows) > 3) {
                throw new InvalidArgumentException('Submit up to three item rows.');
            }
            $draft['items'] = [];
            foreach ($rows as $row) {
                if (!is_array($row)) { throw new InvalidArgumentException('Invalid item.'); }
                $clean = [];
                foreach (['name', 'description', 'quantity', 'price', 'tax', 'metadata'] as $key) { $clean[$key] = field($row, $key); }
                $draft['items'][] = $clean;
            }
            $_SESSION['draft'] = $draft;
            $items = [];
            foreach ($draft['items'] as $row) {
                if (trim($row['name']) === '') { continue; }
                if (!preg_match('/^[1-9][0-9]{0,3}$/D', $row['quantity'])) { throw new InvalidArgumentException('Item quantity must be 1–9999.'); }
                $items[] = ['name' => $row['name'], 'description' => $row['description'], 'quantity' => (int) $row['quantity'],
                    'price' => $row['price'], 'tax' => $row['tax'] === '' ? null : $row['tax'], 'metadata' => $row['metadata'] === '' ? null : $row['metadata']];
            }
            if (!preg_match('/^[0-9]{3}$/D', $draft['timeout'])) { throw new InvalidArgumentException('Payment timeout must be 120–600 seconds.'); }
            $payment = new Payment(Amount::fromDecimal($draft['total']), $draft['phone'], $draft['metadata1'], $draft['metadata2'], $items,
                $draft['subtotal'] === '' ? null : Amount::fromDecimal($draft['subtotal']),
                $draft['tax'] === '' ? null : Amount::fromDecimal($draft['tax']), (int) $draft['timeout']);
            $created = $client->createPayment($payment);
            $_SESSION['orders'][$created->ecommerceId()] = ['token' => $created->authToken(),
                'expectedTotal' => (string) Amount::fromDecimal($draft['total']), 'metadata1' => $draft['metadata1'], 'metadata2' => $draft['metadata2']];
            $_SESSION['active'] = $created->ecommerceId();
            $_SESSION['flash'] = ['success', 'Payment request created. Confirm it as the simulated customer, then authorize it.'];
        } elseif ($action === 'reset') {
            $simulation->reset();
            $_SESSION['orders'] = [];
            unset($_SESSION['active'], $_SESSION['draft']);
            $_SESSION['flash'] = ['success', 'Your demo payments and captures have been cleared.'];
        } else {
            $id = field($_POST, 'id');
            $order = $_SESSION['orders'][$id] ?? null;
            if ($order === null) { throw new OutOfBoundsException('Payment does not belong to this browser session.'); }
            $_SESSION['active'] = $id;
            switch ($action) {
                case 'confirm': $simulation->confirmPayment($id); break;
                case 'cancel': $client->cancelPayment($id); break;
                case 'expire': $simulation->expirePayment($id); break;
                case 'phone': $client->updatePhoneNumber($id, field($_POST, 'phone'), $order['token']); break;
                case 'authorize':
                    scenario($simulation, Operation::Authorize, field($_POST, 'scenario', 'success'));
                    $client->authorizePayment($order['token']);
                    break;
                case 'refund':
                    // Validate before arming a one-shot fault, so bad forms cannot leave a pending fault.
                    $amount = Amount::fromDecimal(field($_POST, 'amount'));
                    $message = field($_POST, 'message');
                    if ($amount->cents < 1) { throw new InvalidArgumentException('Refund amount must be positive.'); }
                    $messageLength = preg_match_all('/./us', $message);
                    if ($messageLength === false || $messageLength > 50) { throw new InvalidArgumentException('Refund message must be valid UTF-8 and at most 50 characters.'); }
                    $found = $client->findPayment($id, $order['token']);
                    if (!$found->isCompleted()) { throw new LogicException('Payment must be completed before refunding.'); }
                    $reference = $found->requireString('referenceNumber');
                    scenario($simulation, Operation::Refund, field($_POST, 'scenario', 'success'));
                    $client->refundPayment($reference, $amount, $message === '' ? null : $message);
                    break;
                default: throw new InvalidArgumentException('Unknown action.');
            }
            $_SESSION['flash'] = ['success', 'Action completed. The transaction below was loaded again from persistent simulator storage.'];
        }
    } catch (TransportException) {
        $_SESSION['flash'] = ['warning', 'Simulated timeout: the outcome was unknown to the caller. We did not retry. Review the refreshed payment, refunds, and captured server outcome below.'];
    } catch (ApiException $error) {
        $_SESSION['flash'] = ['error', 'Simulator rejected the request: ' . ($error->providerCode ?? 'HTTP ' . $error->httpStatus) . '.'];
    } catch (LogicException | OutOfBoundsException $error) {
        $_SESSION['flash'] = ['error', $error->getMessage()];
    }
    header('Location: /', true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); header('Allow: GET, POST'); exit; }
if (isset($_GET['export'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="athmovil-simulation-history.json"');
    echo $simulation->exportHistory();
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$draft = $_SESSION['draft'] ?? defaults();
$id = is_string($_GET['id'] ?? null) ? $_GET['id'] : ($_SESSION['active'] ?? null);
$order = $id === null ? null : ($_SESSION['orders'][$id] ?? null);
if ($id !== null && $order === null) { http_response_code(404); exit('Payment not found in this browser session.'); }
$result = $order === null ? null : $client->findPayment($id, $order['token']);
$data = $result?->data();
$status = $result?->paymentStatus();
$reconciled = $result !== null && $result->isCompleted() && $result->ecommerceId() === $id
    && (string) $result->total() === $order['expectedTotal'] && $result->metadata1() === $order['metadata1']
    && $result->metadata2() === $order['metadata2'] && ($result->referenceNumber() ?? '') !== '';
$history = $simulation->history();
$refunds = [];
foreach ($history as $capture) {
    $envelope = $capture['response']['body'] ?? $capture['simulatedServerResponse'] ?? null;
    $original = is_array($envelope['data'] ?? null) ? ($envelope['data']['originalTransaction'] ?? null) : null;
    if (is_array($original) && $result !== null && $original['referenceNumber'] === $result->referenceNumber()) {
        $refunds[] = $envelope['data']['refund'];
    }
}
$csrf = $_SESSION['csrf'];
$orders = $_SESSION['orders'];
$captureJson = $simulation->exportHistory();
session_write_close();
require __DIR__ . '/view.php';
