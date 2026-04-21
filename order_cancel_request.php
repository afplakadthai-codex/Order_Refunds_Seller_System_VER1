<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$root = __DIR__;

$guardCandidates = [
    $root . '/includes/auth.php',
    $root . '/includes/auth_bootstrap.php',
    $root . '/includes/head.php',
];

foreach ($guardCandidates as $guardFile) {
    if (is_file($guardFile)) {
        require_once $guardFile;
        break;
    }
}

require_once $root . '/includes/order_cancel.php';

$refundHelperFile = $root . '/includes/order_refund.php';
if (is_file($refundHelperFile)) {
    require_once $refundHelperFile;
}

if (!function_exists('bvocr_pdo_db')) {
    function bvocr_pdo_db(): PDO
    {
        $candidates = [
            $GLOBALS['pdo'] ?? null,
            $GLOBALS['PDO'] ?? null,
            $GLOBALS['db'] ?? null,
            $GLOBALS['conn'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate instanceof PDO) {
                return $candidate;
            }
        }

        throw new RuntimeException('PDO connection not found.');
    }
}

if (!function_exists('bvocr_pdo_table_columns')) {
    function bvocr_pdo_table_columns(PDO $pdo, string $table): array
    {
        static $cache = [];

        $table = trim(str_replace('`', '', $table));
        if ($table === '') {
            return [];
        }

        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $columns = [];
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($row['Field'])) {
                $columns[(string)$row['Field']] = true;
            }
        }

        $cache[$table] = $columns;
        return $columns;
    }
}

if (!function_exists('bvocr_load_order_items_by_order_id')) {
    function bvocr_load_order_items_by_order_id(int $orderId, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?: bvocr_pdo_db();

        if ($orderId <= 0) {
            return [];
        }

        $stmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = :order_id ORDER BY id ASC');
        $stmt->execute(['order_id' => $orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('bvocr_cancellation_items_exist')) {
    function bvocr_cancellation_items_exist(int $cancellationId, ?PDO $pdo = null): bool
    {
        $pdo = $pdo ?: bvocr_pdo_db();

        if ($cancellationId <= 0) {
            return false;
        }

        $stmt = $pdo->prepare('SELECT 1 FROM order_cancellation_items WHERE cancellation_id = :cancellation_id LIMIT 1');
        $stmt->execute(['cancellation_id' => $cancellationId]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('bvocr_insert_cancellation_items_from_order_items')) {
    function bvocr_insert_cancellation_items_from_order_items(int $orderId, int $cancellationId, ?PDO $pdo = null): int
    {
        $pdo = $pdo ?: bvocr_pdo_db();

        if ($orderId <= 0 || $cancellationId <= 0) {
            return 0;
        }

        if (bvocr_cancellation_items_exist($cancellationId, $pdo)) {
            return 0;
        }

        $orderItems = bvocr_load_order_items_by_order_id($orderId, $pdo);
        if (empty($orderItems)) {
            return 0;
        }

$columns = bvocr_pdo_table_columns($pdo, 'order_cancellation_items');
        if (empty($columns)) {
            return 0;
        }

        $inserted = 0;
        foreach ($orderItems as $item) {
            $qty = isset($item['quantity']) && is_numeric($item['quantity'])
                ? (int)$item['quantity']
                : (isset($item['qty']) && is_numeric($item['qty']) ? (int)$item['qty'] : 1);
            if ($qty <= 0) {
                $qty = 1;
            }

            $unitPrice = 0.0;
            foreach (['unit_price', 'price', 'item_price'] as $priceKey) {
                if (isset($item[$priceKey]) && $item[$priceKey] !== '' && is_numeric($item[$priceKey])) {
                    $unitPrice = (float)$item[$priceKey];
                    break;
                }
            }

            $lineTotal = null;
            foreach (['line_total', 'total', 'amount_total'] as $totalKey) {
                if (isset($item[$totalKey]) && $item[$totalKey] !== '' && is_numeric($item[$totalKey])) {
                    $lineTotal = (float)$item[$totalKey];
                    break;
                }
            }
            if ($lineTotal === null) {
                $lineTotal = (float)$qty * $unitPrice;
            }

            $listingTitle = '';
            foreach (['listing_title_snapshot', 'title', 'item_name', 'name', 'product_name'] as $titleKey) {
                if (isset($item[$titleKey]) && (string)$item[$titleKey] !== '') {
                    $listingTitle = (string)$item[$titleKey];
                    break;
                }
            }

            $payload = [
                'cancellation_id' => $cancellationId,
                'order_id' => $orderId,
                'order_item_id' => (int)($item['id'] ?? 0),
                'listing_id' => isset($item['listing_id']) ? (int)$item['listing_id'] : null,
                'seller_user_id' => isset($item['seller_user_id']) ? (int)$item['seller_user_id'] : null,
                'qty' => $qty,
                'refund_qty' => $qty,
                'unit_price_snapshot' => round($unitPrice, 2),
                'line_total_snapshot' => round((float)$lineTotal, 2),
                'listing_title_snapshot' => $listingTitle,
                'restock_qty' => $qty,
                'stock_reversed' => 0,
                'stock_reversed_at' => null,
                'stock_reverse_note' => '',
                'item_refundable_amount' => round((float)$lineTotal, 2),
                'refund_line_amount' => round((float)$lineTotal, 2),
            ];

            $keys = [];
            $values = [];
            $params = [];
            foreach ($payload as $field => $value) {
                if (!isset($columns[$field])) {
                    continue;
                }
                $keys[] = "`{$field}`";
                $values[] = ':' . $field;
                $params[$field] = $value;
            }

            if (empty($keys)) {
                continue;
            }

            $sql = 'INSERT INTO order_cancellation_items (' . implode(', ', $keys) . ') VALUES (' . implode(', ', $values) . ')';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $inserted++;
        }

        return $inserted;
    }
}

if (!function_exists('bvocr_ensure_cancellation_items_for_refund_bridge')) {
    function bvocr_ensure_cancellation_items_for_refund_bridge(int $orderId, int $cancellationId, ?PDO $pdo = null): int
    {
        $pdo = $pdo ?: bvocr_pdo_db();

        if ($orderId <= 0 || $cancellationId <= 0) {
            return 0;
        }

        if (bvocr_cancellation_items_exist($cancellationId, $pdo)) {
            return 0;
        }

        return bvocr_insert_cancellation_items_from_order_items($orderId, $cancellationId, $pdo);
    }
}

if (!function_exists('bvocr_refund_request_prepare_bridge_items')) {
    function bvocr_refund_request_prepare_bridge_items(array $context, ?PDO $pdo = null): array
    {
        $pdo = $pdo ?: bvocr_pdo_db();

        $requestKind = strtolower(trim((string)($context['request_kind'] ?? '')));
        if ($requestKind !== 'refund') {
            return $context;
        }

        $orderId = (int)($context['order_id'] ?? 0);
        $cancellationId = (int)($context['cancellation_id'] ?? 0);

        if ($orderId > 0 && $cancellationId > 0) {
            bvocr_ensure_cancellation_items_for_refund_bridge($orderId, $cancellationId, $pdo);
        }

        if (function_exists('bvocr_refund_create_request')) {
            $context['refund_request'] = bvocr_refund_create_request($context);
        }

        return $context;
    }
}

if (!function_exists('bvocr_h')) {
    function bvocr_h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('bvocr_now')) {
    function bvocr_now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('bvocr_redirect')) {
    function bvocr_redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('bvocr_build_url')) {
    function bvocr_build_url(string $path, array $params = []): string
    {
        $path = trim($path);
        if ($path === '') {
            $path = '/';
        }

        $clean = [];
        foreach ($params as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $clean[$key] = $value;
        }

        if (!$clean) {
            return $path;
        }

        return $path . (strpos($path, '?') === false ? '?' : '&') . http_build_query($clean);
    }
}

if (!function_exists('bvocr_is_safe_return_url')) {
    function bvocr_is_safe_return_url(string $url): bool
    {
        if ($url === '') {
            return false;
        }

        if (preg_match('~^https?://~i', $url)) {
            return false;
        }

        if (stripos($url, 'javascript:') === 0) {
            return false;
        }

        if (strpos($url, "\r") !== false || strpos($url, "\n") !== false) {
            return false;
        }

        return true;
    }
}

if (!function_exists('bvocr_order_view_url')) {
    function bvocr_order_view_url(int $orderId): string
    {
        $candidates = [
            '/member/order_view.php',
            '/member/orders_view.php',
            '/member/order-detail.php',
            '/member/order_detail.php',
            '/order_detail.php',
            '/orders.php',
            '/member/orders.php',
        ];

        foreach ($candidates as $candidate) {
            $full = __DIR__ . $candidate;
            if (is_file($full)) {
                if (strpos(basename($candidate), 'orders.php') !== false) {
                    return bvocr_build_url($candidate, ['order_id' => $orderId]);
                }
                return bvocr_build_url($candidate, ['id' => $orderId]);
            }
        }

        return bvocr_build_url('/member/orders.php', ['order_id' => $orderId]);
    }
}

if (!function_exists('bvocr_get_return_url')) {
    function bvocr_get_return_url(int $orderId): string
    {
        $candidate = trim((string) ($_POST['return_url'] ?? $_GET['return_url'] ?? ''));
        if (bvocr_is_safe_return_url($candidate)) {
            return $candidate;
        }

        return bvocr_order_view_url($orderId);
    }
}

if (!function_exists('bvocr_request_kind')) {
    function bvocr_request_kind(): string
    {
        $candidates = [
            $_POST['request_kind'] ?? null,
            $_POST['request_type'] ?? null,
            $_POST['intent'] ?? null,
            $_GET['request_kind'] ?? null,
            $_GET['request_type'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = strtolower(trim((string) $candidate));
            if (in_array($value, ['cancel', 'refund'], true)) {
                return $value;
            }
        }

        return 'cancel';
    }
}

if (!function_exists('bvocr_flash_key')) {
    function bvocr_flash_key(string $requestKind): string
    {
        return $requestKind === 'refund' ? 'order_refund_flash' : 'order_cancel_flash';
    }
}

if (!function_exists('bvocr_flash_set')) {
    function bvocr_flash_set(string $requestKind, string $status, string $message, array $old = [], array $errors = []): void
    {
        $payload = [
            'request_kind' => $requestKind,
            'status' => $status,
            'message' => $message,
            'old' => $old,
            'errors' => $errors,
            'at' => bvocr_now(),
        ];

        $_SESSION['order_request_flash'] = $payload;
        $_SESSION[bvocr_flash_key($requestKind)] = $payload;

        if ($requestKind === 'refund') {
            $_SESSION['order_cancel_flash'] = $payload;
        } else {
            $_SESSION['order_refund_flash'] = $payload;
        }
    }
}

if (!function_exists('bvocr_flash_redirect')) {
    function bvocr_flash_redirect(string $requestKind, string $returnUrl, string $status, string $message, array $old = [], array $errors = []): void
    {
        bvocr_flash_set($requestKind, $status, $message, $old, $errors);
        bvocr_redirect($returnUrl);
    }
}

if (!function_exists('bvocr_csrf_scopes')) {
    function bvocr_csrf_scopes(string $requestKind): array
    {
        if ($requestKind === 'refund') {
            return ['order_request_form', 'order_refund_request', 'order_cancel_request'];
        }

        return ['order_request_form', 'order_cancel_request', 'order_refund_request'];
    }
}

if (!function_exists('bvocr_csrf_token')) {
    function bvocr_csrf_token(string $scope = 'order_request_form'): string
    {
        if (empty($_SESSION['_csrf_order_request'][$scope]) || !is_string($_SESSION['_csrf_order_request'][$scope])) {
            $_SESSION['_csrf_order_request'][$scope] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf_order_request'][$scope];
    }
}

if (!function_exists('bvocr_verify_csrf')) {
    function bvocr_verify_csrf(?string $token, array $scopes): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        foreach ($scopes as $scope) {
            $scope = trim((string) $scope);
            if ($scope === '') {
                continue;
            }

            $candidates = [
                $_SESSION['_csrf_order_request'][$scope] ?? null,
                $_SESSION['_csrf_order_cancel'][$scope] ?? null,
                $_SESSION['_csrf_order_refund'][$scope] ?? null,
                $_SESSION['csrf_token'] ?? null,
            ];

            foreach ($candidates as $candidate) {
                if (is_string($candidate) && $candidate !== '' && hash_equals($candidate, $token)) {
                    return true;
                }
            }
        }

        return false;
    }
}

if (!function_exists('bvocr_is_too_fast')) {
    function bvocr_is_too_fast($startedAt, int $minSeconds, array $scopes): bool
    {
        $postedStartedAt = is_numeric($startedAt) ? (int) $startedAt : 0;
        $effectiveStartedAt = $postedStartedAt;

        foreach ($scopes as $scope) {
            $scope = trim((string) $scope);
            if ($scope === '') {
                continue;
            }

            $candidates = [
                $_SESSION['_order_request_started_at'][$scope] ?? null,
                $_SESSION['_order_cancel_started_at'][$scope] ?? null,
                $_SESSION['_order_refund_started_at'][$scope] ?? null,
            ];

            foreach ($candidates as $candidate) {
                if (is_numeric($candidate)) {
                    $effectiveStartedAt = max($effectiveStartedAt, (int) $candidate);
                }
            }
        }

        if ($effectiveStartedAt <= 0) {
            return true;
        }

        return (time() - $effectiveStartedAt) < $minSeconds;
    }
}

if (!function_exists('bvocr_clear_started_at')) {
    function bvocr_clear_started_at(array $scopes): void
    {
        foreach ($scopes as $scope) {
            unset($_SESSION['_order_request_started_at'][$scope]);
            unset($_SESSION['_order_cancel_started_at'][$scope]);
            unset($_SESSION['_order_refund_started_at'][$scope]);
        }
    }
}

if (!function_exists('bvocr_current_user_id')) {
    function bvocr_current_user_id(): int
    {
        $candidates = [
            $_SESSION['user']['id'] ?? null,
            $_SESSION['auth_user']['id'] ?? null,
            $_SESSION['member']['id'] ?? null,
            $_SESSION['seller']['id'] ?? null,
            $_SESSION['admin']['id'] ?? null,
            $_SESSION['user_id'] ?? null,
            $_SESSION['member_id'] ?? null,
            $_SESSION['admin_id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }

        return 0;
    }
}

if (!function_exists('bvocr_current_role')) {
    function bvocr_current_role(): string
    {
        $candidates = [
            $_SESSION['user']['role'] ?? null,
            $_SESSION['auth_user']['role'] ?? null,
            $_SESSION['member']['role'] ?? null,
            $_SESSION['seller']['role'] ?? null,
            $_SESSION['admin']['role'] ?? null,
            $_SESSION['role'] ?? null,
            $_SESSION['user_role'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $role = strtolower(trim((string) $candidate));
            if ($role !== '') {
                if (in_array($role, ['super_admin', 'superadmin', 'owner'], true)) {
                    return 'admin';
                }
                return $role;
            }
        }

        return 'guest';
    }
}

if (!function_exists('bvocr_login_url')) {
    function bvocr_login_url(string $returnUrl): string
    {
        $candidates = [
            '/login.php',
            '/member/login.php',
            'login.php',
            'member/login.php',
        ];

        foreach ($candidates as $candidate) {
            $full = $candidate[0] === '/' ? __DIR__ . $candidate : __DIR__ . '/' . $candidate;
            if (is_file($full)) {
                return $candidate . '?redirect=' . rawurlencode($returnUrl);
            }
        }

        return '/login.php?redirect=' . rawurlencode($returnUrl);
    }
}

if (!function_exists('bvocr_reason_code')) {
    function bvocr_reason_code(?string $value): string
    {
        $value = strtolower(trim((string) $value));
        $allowed = [
            'changed_mind',
            'wrong_item',
            'duplicate_order',
            'payment_issue',
            'shipping_issue',
            'seller_delay',
            'mistake_order',
            'not_as_described',
            'damaged_item',
            'received_wrong_item',
            'other',
        ];

        return in_array($value, $allowed, true) ? $value : 'other';
    }
}

if (!function_exists('bvocr_reason_text')) {
    function bvocr_reason_text(?string $value): string
    {
        $value = trim((string) $value);
        $value = preg_replace("/\r\n|\r/u", "\n", $value ?? '');
        return mb_substr($value, 0, 3000);
    }
}

if (!function_exists('bvocr_reason_code_from_post')) {
    function bvocr_reason_code_from_post(): string
    {
        $candidates = [
            $_POST['reason_code'] ?? null,
            $_POST['cancel_reason_code'] ?? null,
            $_POST['refund_reason_code'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return bvocr_reason_code($value);
            }
        }

        return 'other';
    }
}

if (!function_exists('bvocr_reason_text_from_post')) {
    function bvocr_reason_text_from_post(): string
    {
        $candidates = [
            $_POST['reason_text'] ?? null,
            $_POST['cancel_reason_text'] ?? null,
            $_POST['refund_reason_text'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = bvocr_reason_text($candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}

if (!function_exists('bvocr_detect_status_from_exception')) {
    function bvocr_detect_status_from_exception(Throwable $e): string
    {
        $message = strtolower(trim($e->getMessage()));

        if ($message === '') {
            return 'error';
        }

        $pairs = [
            'not found' => 'not_found',
            'not allowed' => 'not_allowed',
            'already exists' => 'duplicate',
            'already' => 'duplicate',
            'open cancel request' => 'duplicate',
            'open refund request' => 'duplicate',
            'csrf' => 'csrf',
            'token' => 'csrf',
            'required cancellation tables are missing' => 'db_missing',
            'required refund tables are missing' => 'db_missing',
            'refund tables are not ready' => 'db_missing',
            'missing' => 'db_missing',
            'invalid' => 'invalid',
        ];

        foreach ($pairs as $needle => $status) {
            if (strpos($message, $needle) !== false) {
                return $status;
            }
        }

        return 'error';
    }
}

if (!function_exists('bvocr_render_error_page')) {
    function bvocr_render_error_page(string $title, string $message, string $backUrl, string $backLabel = 'Back'): void
    {
        http_response_code(400);
        ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= bvocr_h($title); ?></title>
    <style>
        body{
            margin:0;
            min-height:100vh;
            font-family:Inter,Arial,sans-serif;
            background:linear-gradient(180deg,#06110c 0%, #040b08 100%);
            color:#0f172a;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:24px;
        }
        .card{
            width:min(100%, 720px);
            background:#fff;
            border-radius:24px;
            padding:28px;
            box-shadow:0 24px 70px rgba(0,0,0,.22);
        }
        .eyebrow{
            display:inline-flex;
            align-items:center;
            padding:8px 12px;
            border-radius:999px;
            background:#fee2e2;
            color:#991b1b;
            font-size:12px;
            font-weight:900;
            letter-spacing:.04em;
            text-transform:uppercase;
        }
        h1{
            margin:16px 0 10px;
            font-size:34px;
            line-height:1.05;
        }
        p{
            margin:0 0 18px;
            color:#475569;
            line-height:1.8;
            font-size:15px;
        }
        .btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:48px;
            padding:0 18px;
            border-radius:14px;
            background:#253726;
            color:#fff;
            text-decoration:none;
            font-weight:900;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="eyebrow">Order Request</div>
        <h1><?= bvocr_h($title); ?></h1>
        <p><?= bvocr_h($message); ?></p>
        <a class="btn" href="<?= bvocr_h($backUrl); ?>"><?= bvocr_h($backLabel); ?></a>
    </div>
</body>
</html>
<?php
        exit;
    }
}

if (!function_exists('bvocr_db')) {
    function bvocr_db()
    {
        $candidates = [
            $GLOBALS['pdo'] ?? null,
            $GLOBALS['PDO'] ?? null,
            $GLOBALS['db'] ?? null,
            $GLOBALS['conn'] ?? null,
            $GLOBALS['mysqli'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate instanceof PDO || $candidate instanceof mysqli) {
                return $candidate;
            }
        }

        return null;
    }
}

if (!function_exists('bvocr_order_row')) {
    function bvocr_order_row(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        $db = bvocr_db();
        if (!$db) {
            return null;
        }

        try {
            if ($db instanceof PDO) {
                $stmt = $db->prepare('SELECT * FROM orders WHERE id = ? LIMIT 1');
                $stmt->execute([$orderId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                return $row ?: null;
            }

            if ($db instanceof mysqli) {
                $stmt = mysqli_prepare($db, 'SELECT * FROM orders WHERE id = ? LIMIT 1');
                if (!$stmt) {
                    return null;
                }

                mysqli_stmt_bind_param($stmt, 'i', $orderId);
                if (!mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    return null;
                }

                $result = mysqli_stmt_get_result($stmt);
                $row = $result ? mysqli_fetch_assoc($result) : null;

                if ($result) {
                    mysqli_free_result($result);
                }
                mysqli_stmt_close($stmt);

                return $row ?: null;
            }
        } catch (Throwable $e) {
            return null;
        }

        return null;
    }
}

if (!function_exists('bvocr_order_total')) {
    function bvocr_order_total(array $order): float
    {
        foreach (['total', 'grand_total', 'amount_total'] as $field) {
            if (isset($order[$field]) && $order[$field] !== '' && $order[$field] !== null && is_numeric($order[$field])) {
                return round((float) $order[$field], 2);
            }
        }

        $subtotal = isset($order['subtotal']) && is_numeric($order['subtotal']) ? (float) $order['subtotal'] : 0.0;
        $shipping = isset($order['shipping_amount']) && is_numeric($order['shipping_amount']) ? (float) $order['shipping_amount'] : 0.0;
        $discount = isset($order['discount_amount']) && is_numeric($order['discount_amount']) ? (float) $order['discount_amount'] : 0.0;

        return round(max(0, ($subtotal - $discount) + $shipping), 2);
    }
}

if (!function_exists('bvocr_order_is_paid_confirmed')) {
    function bvocr_order_is_paid_confirmed(array $order): bool
    {
        $paymentState = strtolower(trim((string) ($order['payment_state'] ?? $order['payment_status'] ?? '')));
        $orderStatus = strtolower(trim((string) ($order['order_status'] ?? $order['status'] ?? '')));

        $paidStates = ['paid', 'confirmed', 'completed', 'partially_refunded', 'refunded'];
        $confirmedStates = ['confirmed', 'processing', 'shipped', 'completed', 'delivered'];

        return in_array($paymentState, $paidStates, true) || in_array($orderStatus, $confirmedStates, true);
    }
}

if (!function_exists('bvocr_user_owns_order')) {
    function bvocr_user_owns_order(array $order, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $ownerFields = ['user_id', 'buyer_user_id', 'member_id', 'customer_id', 'user_idx'];
        foreach ($ownerFields as $field) {
            if (isset($order[$field]) && is_numeric($order[$field]) && (int) $order[$field] > 0) {
                return (int) $order[$field] === $userId;
            }
        }

        return false;
    }
}

if (!function_exists('bvocr_table_columns')) {
    function bvocr_table_columns(string $table): array
    {
        $db = bvocr_db();
        if (!$db) {
            return [];
        }

        $columns = [];

        try {
            if ($db instanceof PDO) {
                $stmt = $db->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
                $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
                foreach ($rows as $row) {
                    $name = (string) ($row['Field'] ?? '');
                    if ($name !== '') {
                        $columns[$name] = true;
                    }
                }
            } elseif ($db instanceof mysqli) {
                $query = 'SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`';
                $result = mysqli_query($db, $query);
                if ($result instanceof mysqli_result) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $name = (string) ($row['Field'] ?? '');
                        if ($name !== '') {
                            $columns[$name] = true;
                        }
                    }
                    mysqli_free_result($result);
                }
            }
        } catch (Throwable $e) {
            return [];
        }

        return $columns;
    }
}

if (!function_exists('bvocr_cancellation_find_by_order_id')) {
    function bvocr_cancellation_find_by_order_id(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        $db = bvocr_db();
        if (!$db) {
            return null;
        }

        try {
            if ($db instanceof PDO) {
                $stmt = $db->prepare('SELECT * FROM order_cancellations WHERE order_id = ? ORDER BY id DESC LIMIT 1');
                $stmt->execute([$orderId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                return $row ?: null;
            }

            if ($db instanceof mysqli) {
                $stmt = mysqli_prepare($db, 'SELECT * FROM order_cancellations WHERE order_id = ? ORDER BY id DESC LIMIT 1');
                if (!$stmt) {
                    return null;
                }
                mysqli_stmt_bind_param($stmt, 'i', $orderId);
                if (!mysqli_stmt_execute($stmt)) {
                    mysqli_stmt_close($stmt);
                    return null;
                }
                $result = mysqli_stmt_get_result($stmt);
                $row = $result ? mysqli_fetch_assoc($result) : null;
                if ($result) {
                    mysqli_free_result($result);
                }
                mysqli_stmt_close($stmt);
                return $row ?: null;
            }
        } catch (Throwable $e) {
            return null;
        }

        return null;
    }
}

if (!function_exists('bvocr_order_code_snapshot')) {
    function bvocr_order_code_snapshot(array $order): string
    {
        $candidates = ['order_code', 'code', 'order_number', 'order_no', 'id'];
        foreach ($candidates as $field) {
            $value = trim((string) ($order[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }
}

if (!function_exists('bvocr_order_currency')) {
    function bvocr_order_currency(array $order): string
    {
        $currency = trim((string) ($order['currency'] ?? $order['currency_code'] ?? ''));
        return $currency !== '' ? $currency : 'THB';
    }
}

if (!function_exists('bvocr_order_numeric')) {
    function bvocr_order_numeric(array $order, array $fields, float $fallback = 0.0): float
    {
        foreach ($fields as $field) {
            if (isset($order[$field]) && $order[$field] !== '' && $order[$field] !== null && is_numeric($order[$field])) {
                return round((float) $order[$field], 2);
            }
        }

        return round($fallback, 2);
    }
}

if (!function_exists('bvocr_insert_row')) {
    function bvocr_insert_row(string $table, array $data): int
    {
        $db = bvocr_db();
        if (!$db || !$data) {
            return 0;
        }

        $fields = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($fields), '?'));
        $sql = 'INSERT INTO `' . str_replace('`', '``', $table) . '` (`' . implode('`,`', array_map(static function ($f) {
            return str_replace('`', '``', (string) $f);
        }, $fields)) . '`) VALUES (' . $placeholders . ')';
        $values = array_values($data);

        if ($db instanceof PDO) {
            $stmt = $db->prepare($sql);
            $stmt->execute($values);
            return (int) $db->lastInsertId();
        }

        if ($db instanceof mysqli) {
            $stmt = mysqli_prepare($db, $sql);
            if (!$stmt) {
                return 0;
            }

            $types = '';
            $params = [];
            foreach ($values as $value) {
                if (is_int($value)) {
                    $types .= 'i';
                } elseif (is_float($value)) {
                    $types .= 'd';
                } else {
                    $types .= 's';
                }
                $params[] = $value;
            }

            mysqli_stmt_bind_param($stmt, $types, ...$params);
            $ok = mysqli_stmt_execute($stmt);
            $insertId = $ok ? (int) mysqli_insert_id($db) : 0;
            mysqli_stmt_close($stmt);
            return $insertId;
        }

        return 0;
    }
}

if (!function_exists('bvocr_create_refund_bridge_cancellation')) {
    function bvocr_create_refund_bridge_cancellation(array $order, int $orderId, int $userId, string $role, string $reasonCode, string $reasonText): int
    {
        $existing = bvocr_cancellation_find_by_order_id($orderId);
        if ($existing && isset($existing['id']) && is_numeric($existing['id']) && (int) $existing['id'] > 0) {
            return (int) $existing['id'];
        }

        $columns = bvocr_table_columns('order_cancellations');
        if (!$columns) {
            throw new RuntimeException('Cancellation table is missing.');
        }

        $now = bvocr_now();
        $subtotal = bvocr_order_numeric($order, ['subtotal', 'items_subtotal'], 0.0);
        $discount = bvocr_order_numeric($order, ['discount_amount', 'discount_total'], 0.0);
        $shipping = bvocr_order_numeric($order, ['shipping_amount', 'shipping_total'], 0.0);
        $total = bvocr_order_numeric($order, ['total', 'grand_total', 'amount_total'], bvocr_order_total($order));
        $refundable = bvocr_order_numeric($order, ['refundable_amount'], $total);
        $approvedRefundAmount = $refundable;
        $paymentSnapshot = trim((string) ($order['payment_state'] ?? $order['payment_status'] ?? ''));
        $orderStatusSnapshot = trim((string) ($order['order_status'] ?? $order['status'] ?? ''));
        $orderSourceSnapshot = trim((string) ($order['order_source'] ?? $order['source'] ?? $order['channel'] ?? 'web'));
        $requesterRole = $role !== '' ? $role : 'buyer';
        $cancelSource = bvocr_cancel_source($requesterRole);

        $insert = [
            'order_id' => $orderId,
            'order_code_snapshot' => bvocr_order_code_snapshot($order),
            'requested_by_user_id' => $userId,
            'requested_by_role' => $requesterRole,
            'cancel_source' => $cancelSource,
            'cancel_reason_code' => $reasonCode,
            'cancel_reason_text' => $reasonText,
            'status' => 'approved',
            'payment_state_snapshot' => $paymentSnapshot,
            'order_status_snapshot' => $orderStatusSnapshot,
            'order_source_snapshot' => $orderSourceSnapshot,
            'currency' => bvocr_order_currency($order),
            'subtotal_before_discount_snapshot' => $subtotal,
            'discount_amount_snapshot' => $discount,
            'shipping_amount_snapshot' => $shipping,
            'total_snapshot' => $total,
            'refundable_amount' => $refundable,
            'approved_refund_amount' => $approvedRefundAmount,
            'requested_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $filtered = [];
        foreach ($insert as $field => $value) {
            if (isset($columns[$field])) {
                $filtered[$field] = $value;
            }
        }

        if (!isset($filtered['order_id'])) {
            throw new RuntimeException('order_cancellations.order_id column is missing.');
        }
        if (!isset($filtered['status'])) {
            $filtered['status'] = 'approved';
        }

        $insertId = bvocr_insert_row('order_cancellations', $filtered);
        if ($insertId > 0) {
            return $insertId;
        }

        $existing = bvocr_cancellation_find_by_order_id($orderId);
        if ($existing && isset($existing['id']) && is_numeric($existing['id']) && (int) $existing['id'] > 0) {
            return (int) $existing['id'];
        }

        throw new RuntimeException('Unable to create cancellation bridge row for refund request.');
    }
}

if (!function_exists('bvocr_cancel_source')) {
    function bvocr_cancel_source(string $role): string
    {
        if ($role === 'seller') {
            return 'seller';
        }

        if (function_exists('bvoc_is_admin_role') && bvoc_is_admin_role($role)) {
            return 'admin';
        }

        return 'buyer';
    }
}

if (!function_exists('bvocr_is_privileged_role')) {
    function bvocr_is_privileged_role(string $role): bool
    {
        $role = strtolower(trim($role));
        if ($role === '') {
            return false;
        }

        if ($role === 'seller') {
            return true;
        }

        if (function_exists('bvoc_is_admin_role') && bvoc_is_admin_role($role)) {
            return true;
        }

        return in_array($role, ['admin', 'super_admin', 'superadmin', 'owner', 'manager', 'staff'], true);
    }
}

if (!function_exists('bvocr_refund_source')) { 
    function bvocr_refund_source(string $role): string
    {
        if ($role === 'seller') {
            return 'seller';
        }

        if (function_exists('bvoc_is_admin_role') && bvoc_is_admin_role($role)) {
            return 'admin';
        }

        return 'buyer_request';
    }
}

if (!function_exists('bvocr_refund_require_tables_ready')) {
    function bvocr_refund_require_tables_ready(): bool
    {
        $candidates = [
            'bv_order_refund_require_tables',
            'bvor_require_tables',
        ];

        foreach ($candidates as $fn) {
            if (function_exists($fn)) {
                return (bool) $fn();
            }
        }

        return is_file(__DIR__ . '/includes/order_refund.php');
    }
}

if (!function_exists('bvocr_refund_create_request')) {
    function bvocr_refund_create_request(array $payload): array
    {
        $candidates = [
            'bv_order_refund_create_request',
            'bv_order_refund_create',
            'bv_order_refund_create_from_cancellation',
        ];

        foreach ($candidates as $fn) {
            if (function_exists($fn)) {
                $result = $fn($payload);
                return is_array($result) ? $result : [];
            }
        }

        throw new RuntimeException('Refund helper create request function was not found.');
    }
}

function bvocr_refund_result_id(array $result): int
{
    $candidates = [
        $result['refund_id'] ?? null,
        $result['id'] ?? null,
        $result['request_id'] ?? null,
        $result['refund']['id'] ?? null,
        $result['refund']['refund_id'] ?? null,
    ];

    foreach ($candidates as $candidate) {
        if (is_numeric($candidate) && (int) $candidate > 0) {
            return (int) $candidate;
        }
    }

    return 0;
}

if (!function_exists('bvocr_message_label')) {
    function bvocr_message_label(string $requestKind, string $type): string
    {
        $requestLabel = $requestKind === 'refund' ? 'refund' : 'cancel';
        $requestLabelUc = ucfirst($requestLabel);

        $map = [
            'title_invalid_method' => 'Invalid request',
            'body_invalid_method' => "This page accepts {$requestLabel} requests by POST only.",
            'login_required' => "Please log in before requesting order {$requestLabel}.",
            'db_missing' => $requestKind === 'refund'
                ? 'Refund tables are not ready yet.'
                : 'Order cancel tables are not ready yet.',
            'db_missing_form' => $requestKind === 'refund'
                ? 'Refund tables are missing.'
                : 'Cancellation tables are missing.',
            'csrf' => 'Security token mismatch. Please refresh the page and try again.',
            'bot_blocked' => 'Request blocked.',
            'bot_fast' => 'Submission was too fast. Please try again.',
            'order_invalid' => 'Invalid order.',
            'reason_required' => $requestKind === 'refund'
                ? 'Please enter your refund reason.'
                : 'Please enter your cancellation reason.',
            'reason_short' => $requestKind === 'refund'
                ? 'Refund reason is too short.'
                : 'Cancellation reason is too short.',
            'invalid_fields' => 'Please correct the highlighted fields.',
            'submitted' => $requestKind === 'refund'
                ? 'Your refund request has been submitted successfully.'
                : 'Your cancel request has been submitted successfully.',
            'unable_submit' => $requestKind === 'refund'
                ? 'Unable to submit refund request.'
                : 'Unable to submit cancel request.',
            'duplicate' => $requestKind === 'refund'
                ? 'A refund request already exists for this order.'
                : 'A cancel request already exists for this order.',
            'not_allowed' => $requestKind === 'refund'
                ? 'This order cannot be refunded from your account at the moment.'
                : 'This order cannot be cancelled from your account at the moment.',
            'not_found' => 'Order not found.',
            'invalid' => $requestKind === 'refund'
                ? 'Invalid refund request.'
                : 'Invalid cancellation request.',
            'back_order' => 'Back to Order',
            'request_label' => $requestLabelUc,
        ];

        return $map[$type] ?? '';
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $orderId = isset($_GET['order_id']) && is_numeric($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
    $requestKind = bvocr_request_kind();
    $returnUrl = bvocr_get_return_url($orderId);

    bvocr_render_error_page(
        bvocr_message_label($requestKind, 'title_invalid_method'),
        bvocr_message_label($requestKind, 'body_invalid_method'),
        $returnUrl,
        bvocr_message_label($requestKind, 'back_order')
    );
}

$requestKind = bvocr_request_kind();
$orderId = isset($_POST['order_id']) && is_numeric($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
$returnUrl = bvocr_get_return_url($orderId);
$currentUserId = bvocr_current_user_id();
$currentRole = bvocr_current_role();
$scopes = bvocr_csrf_scopes($requestKind);

$old = [
    'request_kind' => $requestKind,
    'order_id' => (string) ($_POST['order_id'] ?? ''),
    'reason_code' => (string) ($_POST['reason_code'] ?? ($_POST['cancel_reason_code'] ?? ($_POST['refund_reason_code'] ?? ''))),
    'reason_text' => (string) ($_POST['reason_text'] ?? ($_POST['cancel_reason_text'] ?? ($_POST['refund_reason_text'] ?? ''))),
    'return_url' => (string) ($_POST['return_url'] ?? ''),
];

if (!bvoc_require_tables()) {
    bvocr_flash_redirect(
        $requestKind,
        $returnUrl,
        'db_missing',
        bvocr_message_label($requestKind, 'db_missing'),
        $old,
        ['form' => bvocr_message_label($requestKind, 'db_missing_form')]
    );
}

if ($requestKind === 'refund' && !bvocr_refund_require_tables_ready()) {
    bvocr_flash_redirect(
        $requestKind,
        $returnUrl,
        'db_missing',
        bvocr_message_label($requestKind, 'db_missing'),
        $old,
        ['form' => 'Refund tables are missing or the refund helper is not ready.']
    );
}

if ($currentUserId <= 0) {
    bvocr_flash_set(
        $requestKind,
        'login_required',
        bvocr_message_label($requestKind, 'login_required'),
        $old
    );
    bvocr_redirect(bvocr_login_url($returnUrl));
}

$csrfToken = (string) ($_POST['csrf_token'] ?? '');
if (!bvocr_verify_csrf($csrfToken, $scopes)) {
    bvocr_flash_redirect(
        $requestKind,
        $returnUrl,
        'csrf',
        bvocr_message_label($requestKind, 'csrf'),
        $old,
        ['csrf' => 'Invalid security token.']
    );
}

$honeypot = trim((string) ($_POST['website'] ?? ($_POST['bot_honeypot'] ?? '')));
if ($honeypot !== '') {
    bvocr_flash_redirect(
        $requestKind,
        $returnUrl,
        'bot_blocked',
        bvocr_message_label($requestKind, 'bot_blocked'),
        [],
        []
    );
}

$startedAt = $_POST['form_started_at'] ?? '';
if (bvocr_is_too_fast($startedAt, 2, $scopes)) {
    bvocr_flash_redirect(
        $requestKind,
        $returnUrl,
        'bot_blocked',
        bvocr_message_label($requestKind, 'bot_fast'),
        $old,
        ['form' => 'Submission was too fast.']
    );
}

$reasonCode = bvocr_reason_code_from_post();
$reasonText = bvocr_reason_text_from_post();

$errors = [];
if ($orderId <= 0) {
    $errors['order_id'] = bvocr_message_label($requestKind, 'order_invalid');
}
if ($reasonText === '') {
    $errors['reason_text'] = bvocr_message_label($requestKind, 'reason_required');
} elseif (mb_strlen($reasonText) < 5) {
    $errors['reason_text'] = bvocr_message_label($requestKind, 'reason_short');
}

$order = bvocr_order_row($orderId);

if ($requestKind === 'refund' && !$order) {
    $errors['order_id'] = 'Order not found.';
}

if ($errors) {
    bvocr_flash_redirect(
        $requestKind,
        $returnUrl,
        'invalid',
        bvocr_message_label($requestKind, 'invalid_fields'),
        $old,
        $errors
    );
}

try {
    $result = [];
    $cancellationId = 0;
    $refundId = 0;

  if ($requestKind === 'refund') {
        $existingCancellation = bvocr_cancellation_find_by_order_id($orderId);
        if ($existingCancellation && isset($existingCancellation['id']) && is_numeric($existingCancellation['id'])) {
            $cancellationId = (int) $existingCancellation['id'];
        }

        if ($cancellationId <= 0) {
            $isPaidConfirmedOrder = $order ? bvocr_order_is_paid_confirmed($order) : false;

            if ($isPaidConfirmedOrder) {
                if (!bvocr_is_privileged_role($currentRole) && !bvocr_user_owns_order($order, $currentUserId)) {
                    throw new RuntimeException('This order cannot be refunded from your account at the moment.');
                }

                $cancellationId = bvocr_create_refund_bridge_cancellation(
                    $order,
                    $orderId,
                    $currentUserId,
                    $currentRole,
                    $reasonCode,
                    $reasonText
                );
            } else {
                $cancelResult = bv_order_cancel_create_request([
                    'order_id' => $orderId,
                    'actor_user_id' => $currentUserId,
                    'actor_role' => $currentRole,
                    'cancel_source' => bvocr_cancel_source($currentRole),
                    'cancel_reason_code' => $reasonCode,
                    'cancel_reason_text' => $reasonText,
                    'admin_note' => 'Created from buyer/seller refund request endpoint.',
                ]);
                $cancellationId = (int) ($cancelResult['cancellation_id'] ?? 0);
            }
        }

        if ($cancellationId > 0) {
            bvocr_ensure_cancellation_items_for_refund_bridge($orderId, $cancellationId);
        }

        $requestedAmount = $order ? bvocr_order_total($order) : 0.0;
        $refundPayload = [
            'order_id' => $orderId,
            'order_cancellation_id' => $cancellationId > 0 ? $cancellationId : 0,
            'requested_by_user_id' => $currentUserId,
            'requested_by_role' => $currentRole,
            'actor_user_id' => $currentUserId,
            'actor_role' => $currentRole,
            'refund_source' => bvocr_refund_source($currentRole),
            'refund_reason_code' => $reasonCode,
            'refund_reason_text' => $reasonText,
            'requested_refund_amount' => $requestedAmount,
            'admin_note' => '',
            'internal_note' => 'Created from order_cancel_request.php with request_kind=refund',
        ];

        $result = bvocr_refund_create_request($refundPayload);
        $refundId = bvocr_refund_result_id($result);

        if ($refundId <= 0) {
            throw new RuntimeException('Refund request was created without a valid refund id.');
        }

        $nextUrl = bvocr_build_url($returnUrl, [
            'refund' => 'requested',
            'refund_id' => $refundId,
            'cancellation_id' => $cancellationId > 0 ? $cancellationId : null,
        ]);

        $payload = [
            'request_kind' => 'refund',
            'status' => 'requested',
            'message' => bvocr_message_label('refund', 'submitted'),
            'old' => [],
            'errors' => [],
            'refund_id' => $refundId,
            'cancellation_id' => $cancellationId,
            'at' => bvocr_now(),
        ];

        $_SESSION['order_request_flash'] = $payload;
        $_SESSION['order_refund_flash'] = $payload;
        $_SESSION['order_cancel_flash'] = $payload;

        bvocr_clear_started_at($scopes);
        bvocr_redirect($nextUrl);
    }

    $result = bv_order_cancel_create_request([
        'order_id' => $orderId,
        'actor_user_id' => $currentUserId,
        'actor_role' => $currentRole,
        'cancel_source' => bvocr_cancel_source($currentRole),
        'cancel_reason_code' => $reasonCode,
        'cancel_reason_text' => $reasonText,
        'admin_note' => '',
    ]);

    $cancellationId = (int) ($result['cancellation_id'] ?? 0);
    $nextUrl = bvocr_build_url($returnUrl, [
        'cancel' => 'requested',
        'cancellation_id' => $cancellationId > 0 ? $cancellationId : null,
    ]);

    $payload = [
        'request_kind' => 'cancel',
        'status' => 'requested',
        'message' => bvocr_message_label('cancel', 'submitted'),
        'old' => [],
        'errors' => [],
        'cancellation_id' => $cancellationId,
        'at' => bvocr_now(),
    ];

    $_SESSION['order_request_flash'] = $payload;
    $_SESSION['order_cancel_flash'] = $payload;
    $_SESSION['order_refund_flash'] = $payload;

    bvocr_clear_started_at($scopes);
    bvocr_redirect($nextUrl);
} catch (Throwable $e) {
    $status = bvocr_detect_status_from_exception($e);
    $message = bvocr_message_label($requestKind, 'unable_submit');

    if ($status === 'duplicate') {
        $message = bvocr_message_label($requestKind, 'duplicate');
    } elseif ($status === 'not_allowed') {
        $message = bvocr_message_label($requestKind, 'not_allowed');
    } elseif ($status === 'not_found') {
        $message = bvocr_message_label($requestKind, 'not_found');
    } elseif ($status === 'db_missing') {
        $message = bvocr_message_label($requestKind, 'db_missing');
    } elseif ($status === 'invalid') {
        $message = bvocr_message_label($requestKind, 'invalid');
    }

    bvocr_flash_redirect(
        $requestKind,
        $returnUrl,
        $status,
        $message,
        $old,
        ['form' => $e->getMessage()]
    );
}
?>