<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}

$root = dirname(__DIR__);
$memberRoot = __DIR__;

$guardCandidates = [
    $memberRoot . '/_guard.php',
    $memberRoot . '/guard.php',
    $root . '/includes/auth.php',
    $root . '/includes/auth_bootstrap.php',
];

foreach ($guardCandidates as $guardFile) {
    if (is_file($guardFile)) {
        require_once $guardFile;
    }
}

$configCandidates = [
    $root . '/config/db.php',
    $root . '/includes/db.php',
    $root . '/db.php',
];

foreach ($configCandidates as $configFile) {
    if (is_file($configFile)) {
        require_once $configFile;
        break;
    }
}

$orderCancelHelper = $root . '/includes/order_cancel.php';
if (is_file($orderCancelHelper)) {
    require_once $orderCancelHelper;
}

if (!function_exists('bv_member_order_detail_h')) {
    function bv_member_order_detail_h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('bv_member_order_detail_db')) {
    function bv_member_order_detail_db(): PDO
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

if (!function_exists('bv_member_order_detail_current_user_id')) {
    function bv_member_order_detail_current_user_id(): int
    {
        $candidates = [
            $_SESSION['user']['id'] ?? null,
            $_SESSION['auth_user']['id'] ?? null,
            $_SESSION['member']['id'] ?? null,
            $_SESSION['seller']['id'] ?? null,
            $_SESSION['user_id'] ?? null,
            $_SESSION['member_id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate) && (int) $candidate > 0) {
                return (int) $candidate;
            }
        }

        return 0;
    }
}

if (!function_exists('bv_member_order_detail_current_user_name')) {
    function bv_member_order_detail_current_user_name(): string
    {
        $candidates = [
            trim((string) ($_SESSION['user']['display_name'] ?? '')),
            trim((string) (((string) ($_SESSION['user']['first_name'] ?? '')) . ' ' . ((string) ($_SESSION['user']['last_name'] ?? '')))),
            trim((string) ($_SESSION['user']['email'] ?? '')),
            trim((string) ($_SESSION['member']['name'] ?? '')),
            trim((string) ($_SESSION['member']['email'] ?? '')),
        ];

        foreach ($candidates as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'Customer';
    }
}

if (!function_exists('bv_member_order_detail_is_logged_in')) {
    function bv_member_order_detail_is_logged_in(): bool
    {
        return bv_member_order_detail_current_user_id() > 0;
    }
}

if (!function_exists('bv_member_order_detail_build_url')) {
    function bv_member_order_detail_build_url(string $path, array $params = []): string
    {
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

if (!function_exists('bv_member_order_detail_current_request_uri')) {
    function bv_member_order_detail_current_request_uri(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/member/order_detail.php');
        if ($uri === '' || strpos($uri, '://') !== false || strpos($uri, "\n") !== false || strpos($uri, "\r") !== false) {
            return '/member/order_detail.php';
        }
        return $uri[0] === '/' ? $uri : '/' . ltrim($uri, '/');
    }
}

if (!function_exists('bv_member_order_detail_login_url')) {
    function bv_member_order_detail_login_url(): string
    {
        $redirect = bv_member_order_detail_current_request_uri();
        $candidates = [
            '/login.php',
            '/member/login.php',
            'login.php',
            'member/login.php',
        ];

        foreach ($candidates as $candidate) {
            $full = $candidate[0] === '/' ? dirname(__DIR__) . $candidate : dirname(__DIR__) . '/' . $candidate;
            if (is_file($full)) {
                return $candidate . '?redirect=' . rawurlencode($redirect);
            }
        }

        return '/login.php?redirect=' . rawurlencode($redirect);
    }
}

if (!function_exists('bv_member_order_detail_redirect')) {
    function bv_member_order_detail_redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}

if (!function_exists('bv_member_order_detail_table_exists')) {
    function bv_member_order_detail_table_exists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $stmt->execute([$table]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('bv_member_order_detail_columns')) {
    function bv_member_order_detail_columns(PDO $pdo, string $table): array
    {
        static $cache = [];

        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $columns = [];
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($row['Field'])) {
                    $columns[(string) $row['Field']] = true;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }

        $cache[$table] = $columns;
        return $columns;
    }
}

if (!function_exists('bv_member_order_detail_has_col')) {
    function bv_member_order_detail_has_col(PDO $pdo, string $table, string $column): bool
    {
        $cols = bv_member_order_detail_columns($pdo, $table);
        return isset($cols[$column]);
    }
}

if (!function_exists('bv_member_order_detail_money')) {
    function bv_member_order_detail_money($amount, ?string $currency = null): string
    {
        $currency = strtoupper(trim((string) ($currency ?: 'USD')));
        if ($currency === '') {
            $currency = 'USD';
        }

        if ($amount === null || $amount === '') {
            return $currency . ' 0.00';
        }

        if (function_exists('money') && is_numeric($amount)) {
            return (string) money((float) $amount, $currency);
        }

        return $currency . ' ' . number_format((float) $amount, 2);
    }
}

if (!function_exists('bv_member_order_detail_status_badge')) {
    function bv_member_order_detail_status_badge(string $status): array
    {
        $status = strtolower(trim($status));

        $map = [
            'pending' => ['Pending', '#9a6700', '#fff8c5'],
            'pending_payment' => ['Pending Payment', '#9a6700', '#fff8c5'],
            'reserved' => ['Reserved', '#7c3aed', '#ede9fe'],
            'paid' => ['Paid', '#166534', '#dcfce7'],
            'paid-awaiting-verify' => ['Awaiting Verify', '#0f766e', '#ccfbf1'],
            'processing' => ['Processing', '#1d4ed8', '#dbeafe'],
            'confirmed' => ['Confirmed', '#0369a1', '#e0f2fe'],
            'packing' => ['Packing', '#4338ca', '#e0e7ff'],
            'shipped' => ['Shipped', '#334155', '#e2e8f0'],
            'completed' => ['Completed', '#065f46', '#d1fae5'],
            'cancelled' => ['Cancelled', '#991b1b', '#fee2e2'],
            'refunded' => ['Refunded', '#be123c', '#ffe4e6'],
        ];

        if (isset($map[$status])) {
            return $map[$status];
        }

        return [ucfirst($status !== '' ? str_replace('_', ' ', $status) : 'Unknown'), '#374151', '#e5e7eb'];
    }
}

if (!function_exists('bv_member_order_detail_payment_badge')) {
    function bv_member_order_detail_payment_badge(string $status): array
    {
        $status = strtolower(trim($status));

        $map = [
            'paid' => ['Paid', '#166534', '#dcfce7'],
            'pending' => ['Pending', '#9a6700', '#fff8c5'],
            'unpaid' => ['Unpaid', '#b45309', '#ffedd5'],
            'authorized' => ['Authorized', '#0369a1', '#e0f2fe'],
            'failed' => ['Failed', '#991b1b', '#fee2e2'],
            'refunded' => ['Refunded', '#be123c', '#ffe4e6'],
            'partially_refunded' => ['Partial Refund', '#9d174d', '#fce7f3'],
        ];

        if (isset($map[$status])) {
            return $map[$status];
        }

        return [ucfirst($status !== '' ? str_replace('_', ' ', $status) : 'Unknown'), '#374151', '#e5e7eb'];
    }
}

if (!function_exists('bv_member_order_detail_order_code')) {
    function bv_member_order_detail_order_code(array $row): string
    {
        foreach (['order_code', 'code', 'reference'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '#' . (int) ($row['id'] ?? 0);
    }
}

if (!function_exists('bv_member_order_detail_order_total')) {
    function bv_member_order_detail_order_total(array $row): float
    {
        foreach (['total', 'grand_total', 'amount_total'] as $field) {
            if (isset($row[$field]) && $row[$field] !== '' && $row[$field] !== null && is_numeric($row[$field])) {
                return round((float) $row[$field], 2);
            }
        }

        $subtotal = isset($row['subtotal']) && is_numeric($row['subtotal']) ? (float) $row['subtotal'] : 0.0;
        $shipping = isset($row['shipping_amount']) && is_numeric($row['shipping_amount']) ? (float) $row['shipping_amount'] : 0.0;
        $discount = isset($row['discount_amount']) && is_numeric($row['discount_amount']) ? (float) $row['discount_amount'] : 0.0;

        return round(($subtotal - $discount) + $shipping, 2);
    }
}

if (!function_exists('bv_member_order_detail_dashboard_url')) {
    function bv_member_order_detail_dashboard_url(): string
    {
        $candidates = [
            '/member/index.php',
            '/member/dashboard.php',
            '/member/home.php',
            '/index.php',
        ];

        foreach ($candidates as $candidate) {
            $full = dirname(__DIR__) . $candidate;
            if (is_file($full)) {
                return $candidate;
            }
        }

        return '/member/index.php';
    }
}

if (!function_exists('bv_member_order_detail_orders_url')) {
    function bv_member_order_detail_orders_url(): string
    {
        $candidates = [
            '/member/order_view.php',
            '/member/orders.php',
            '/member/order-list.php',
        ];

        foreach ($candidates as $candidate) {
            $full = dirname(__DIR__) . $candidate;
            if (is_file($full)) {
                return $candidate;
            }
        }

        return '/member/order_view.php';
    }
}

if (!function_exists('bv_member_order_detail_item_listing_id')) {
    function bv_member_order_detail_item_listing_id(array $item): int
    {
        foreach (['listing_id', 'product_id', 'item_id'] as $field) {
            if (isset($item[$field]) && is_numeric($item[$field]) && (int) $item[$field] > 0) {
                return (int) $item[$field];
            }
        }

        return 0;
    }
}

if (!function_exists('bv_member_order_detail_item_qty')) {
    function bv_member_order_detail_item_qty(array $item): int
    {
        foreach (['quantity', 'qty'] as $field) {
            if (isset($item[$field]) && is_numeric($item[$field]) && (int) $item[$field] > 0) {
                return (int) $item[$field];
            }
        }

        return 1;
    }
}

if (!function_exists('bv_member_order_detail_item_price')) {
    function bv_member_order_detail_item_price(array $item): float
    {
        foreach (['unit_price', 'price', 'item_price'] as $field) {
            if (isset($item[$field]) && $item[$field] !== '' && $item[$field] !== null && is_numeric($item[$field])) {
                return round((float) $item[$field], 2);
            }
        }

        return 0.0;
    }
}

if (!function_exists('bv_member_order_detail_item_total')) {
    function bv_member_order_detail_item_total(array $item): float
    {
        foreach (['line_total', 'total', 'amount_total'] as $field) {
            if (isset($item[$field]) && $item[$field] !== '' && $item[$field] !== null && is_numeric($item[$field])) {
                return round((float) $item[$field], 2);
            }
        }

        return round(bv_member_order_detail_item_price($item) * bv_member_order_detail_item_qty($item), 2);
    }
}

if (!function_exists('bv_member_order_detail_item_title')) {
    function bv_member_order_detail_item_title(array $item): string
    {
        foreach (['listing_title_snapshot', 'title', 'item_name', 'name', 'product_name'] as $field) {
            $value = trim((string) ($item[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return 'Item #' . (int) ($item['id'] ?? 0);
    }
}

if (!function_exists('bv_member_order_detail_item_image')) {
    function bv_member_order_detail_item_image(array $item): string
    {
        foreach (['cover_image_snapshot', 'image', 'image_path', 'image_url', 'cover_image'] as $field) {
            $value = trim((string) ($item[$field] ?? ''));
            if ($value !== '') {
                if (preg_match('~^https?://~i', $value)) {
                    return $value;
                }
                return '/' . ltrim(str_replace('\\', '/', $value), '/');
            }
        }

        return '/images/placeholder-fish.jpg';
    }
}

if (!function_exists('bv_member_order_detail_listing_url')) {
    function bv_member_order_detail_listing_url(array $item): string
    {
        $slug = trim((string) ($item['listing_slug_snapshot'] ?? $item['slug'] ?? ''));
        $listingId = bv_member_order_detail_item_listing_id($item);

        if ($slug !== '') {
            return '/listing.php?slug=' . rawurlencode($slug);
        }

        if ($listingId > 0) {
            return '/listing.php?id=' . $listingId;
        }

        return '#';
    }
}

if (!function_exists('bv_member_order_detail_cancel_info')) {
    function bv_member_order_detail_cancel_info(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        if (function_exists('bv_order_cancel_get_by_order_id')) {
            try {
                return bv_order_cancel_get_by_order_id($orderId);
            } catch (Throwable $e) {
                return null;
            }
        }

        return null;
    }
}

if (!function_exists('bv_member_order_detail_is_paid_state')) {
    function bv_member_order_detail_is_paid_state(array $order): bool
    {
        $status = strtolower(trim((string) ($order['status'] ?? '')));
        $paymentStatus = strtolower(trim((string) (($order['payment_status'] ?? '') ?: ($order['payment_state'] ?? ''))));

        if (in_array($paymentStatus, ['paid', 'authorized', 'partially_refunded', 'refunded'], true)) {
            return true;
        }

        return in_array($status, ['paid', 'paid-awaiting-verify', 'processing', 'confirmed', 'packing', 'shipped', 'completed', 'refunded'], true);
    }
}

if (!function_exists('bv_member_order_detail_request_mode')) {
    function bv_member_order_detail_request_mode(array $order): string
    {
        $status = strtolower(trim((string) ($order['status'] ?? '')));
        $paymentStatus = strtolower(trim((string) (($order['payment_status'] ?? '') ?: ($order['payment_state'] ?? ''))));
        $isPaidState = bv_member_order_detail_is_paid_state($order);

        if (in_array($status, ['cancelled', 'refunded'], true)) {
            return 'none';
        }

        if ($isPaidState) {
            return 'refund';
        }

        if (function_exists('bv_order_cancel_is_allowed')) {
            try {
                if (!bv_order_cancel_is_allowed(
                    $order,
                    bv_member_order_detail_current_user_id(),
                    'user',
                    'buyer'
                )) {
                    return 'none';
                }
            } catch (Throwable $e) {
                return 'none';
            }
        }

        if (in_array($status, ['pending', 'pending_payment', 'reserved', 'unpaid'], true) || in_array($paymentStatus, ['', 'pending', 'unpaid', 'failed'], true)) {
            return 'cancel';
        }

        return 'none';
    }
}

if (!function_exists('bv_member_order_detail_is_terminal_order_state')) {
    function bv_member_order_detail_is_terminal_order_state(array $order): bool
    {
        $status = strtolower(trim((string) ($order['status'] ?? '')));
        $paymentStatus = strtolower(trim((string) (($order['payment_status'] ?? '') ?: ($order['payment_state'] ?? ''))));
        return in_array($status, ['cancelled', 'refunded'], true) || in_array($paymentStatus, ['refunded'], true);
    }
}

if (!function_exists('bv_member_order_detail_can_cancel')) {
    function bv_member_order_detail_can_cancel(array $order): bool
    {
        return bv_member_order_detail_request_mode($order) === 'cancel';
    }
}
if (!function_exists('bv_member_order_detail_can_refund')) {
    function bv_member_order_detail_can_refund(array $order): bool
    {
        return bv_member_order_detail_request_mode($order) === 'refund';
    }
}

if (!function_exists('bv_member_order_detail_item_field')) {
    function bv_member_order_detail_item_field(array $item, array $keys, $default = null)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $item)) {
                return $item[$key];
            }
        }

        return $default;
    }
}

if (!function_exists('bv_member_order_detail_item_qty')) {
    function bv_member_order_detail_item_qty(array $item): int
    {
        $qty = bv_member_order_detail_item_field($item, ['quantity', 'qty', 'item_qty'], 1);
        if (!is_numeric($qty)) {
            return 1;
        }

        $value = (int) $qty;
        return $value > 0 ? $value : 1;
    }
}

if (!function_exists('bv_member_order_detail_item_is_selectable')) {
    function bv_member_order_detail_item_is_selectable(array $item, string $requestType): bool
    {
        $itemStatus = strtolower(trim((string) bv_member_order_detail_item_field($item, ['status', 'item_status', 'order_item_status'], '')));
        $cancelStatus = strtolower(trim((string) bv_member_order_detail_item_field($item, ['cancel_status', 'cancellation_status'], '')));
        $refundStatus = strtolower(trim((string) bv_member_order_detail_item_field($item, ['refund_status'], '')));
        $isCancelled = (int) bv_member_order_detail_item_field($item, ['is_cancelled', 'cancelled'], 0) > 0;
        $isRefunded = (int) bv_member_order_detail_item_field($item, ['is_refunded', 'refunded'], 0) > 0;
        $isClosed = (int) bv_member_order_detail_item_field($item, ['is_closed', 'closed'], 0) > 0;

        if ($isCancelled || $isRefunded || $isClosed) {
            return false;
        }

        $blocked = ['cancelled', 'canceled', 'refunded', 'closed', 'voided'];
        if (in_array($itemStatus, $blocked, true) || in_array($cancelStatus, $blocked, true)) {
            return false;
        }

        if ($requestType === 'refund') {
            if (in_array($refundStatus, ['refunded', 'completed', 'closed', 'approved'], true)) {
                return false;
            }
        } else {
            if (in_array($cancelStatus, ['approved', 'completed'], true)) {
                return false;
            }
        }

        $qty = bv_member_order_detail_item_qty($item);
        return $qty > 0;
    }
}

if (!function_exists('bv_member_order_detail_existing_request_type')) {
    function bv_member_order_detail_existing_request_type(array $order, ?array $cancelRequest): string
    {
        if (!is_array($cancelRequest) || empty($cancelRequest)) {
            return bv_member_order_detail_request_mode($order);
        }

        $paymentSnapshot = strtolower(trim((string) (($cancelRequest['payment_state_snapshot'] ?? '') ?: ($cancelRequest['payment_status_snapshot'] ?? ''))));
        $refundStatus = strtolower(trim((string) ($cancelRequest['refund_status'] ?? '')));

        if (in_array($paymentSnapshot, ['paid', 'authorized', 'partially_refunded', 'refunded'], true)) {
            return 'refund';
        }

        if ($refundStatus !== '' && !in_array($refundStatus, ['not_required', 'none'], true)) {
            return 'refund';
        }

        return bv_member_order_detail_is_paid_state($order) ? 'refund' : 'cancel';
    }
}

if (!function_exists('bv_member_order_detail_existing_request_meta')) {
    function bv_member_order_detail_existing_request_meta(array $order, ?array $cancelRequest): array
    {
        if (!is_array($cancelRequest) || empty($cancelRequest)) {
            return [
                'has_request' => false,
                'can_retry' => false,
                'request_type' => bv_member_order_detail_request_mode($order),
                'label' => '',
                'status' => '',
            ];
        }

        $status = strtolower(trim((string) ($cancelRequest['status'] ?? 'requested')));
        $refundStatus = strtolower(trim((string) ($cancelRequest['refund_status'] ?? '')));
        $requestType = bv_member_order_detail_existing_request_type($order, $cancelRequest);

        $closedStatuses = ['rejected', 'declined', 'cancelled', 'canceled', 'voided', 'failed'];
        $activeStatuses = ['requested', 'pending', 'in_review', 'review', 'approved', 'processing'];
        $doneStatuses = ['completed', 'refunded', 'success', 'done'];

        $hasRequest = true;
        $canRetry = in_array($status, $closedStatuses, true) || in_array($refundStatus, $closedStatuses, true);

        $isDone = in_array($status, $doneStatuses, true) || in_array($refundStatus, $doneStatuses, true);
        $isApproved = in_array($status, ['approved'], true) || in_array($refundStatus, ['approved'], true);
        $isActive = in_array($status, $activeStatuses, true) || in_array($refundStatus, $activeStatuses, true);

        if ($requestType === 'refund') {
            if ($isDone) {
                $label = 'Refunded';
            } elseif ($isApproved) {
                $label = 'Refund Approved';
            } elseif ($isActive) {
                $label = 'Refund Requested';
            } else {
                $label = 'Refund Requested';
            }
        } else {
            if ($isDone || in_array($status, ['approved'], true)) {
                $label = 'Cancelled';
            } elseif ($isActive) {
                $label = 'Cancel Requested';
            } else {
                $label = 'Cancel Requested';
            }
        }

        return [
            'has_request' => $hasRequest,
            'can_retry' => $canRetry,
            'request_type' => $requestType,
            'label' => $label,
            'status' => $status !== '' ? $status : ($refundStatus !== '' ? $refundStatus : 'requested'),
        ];
    }
}

if (!function_exists('bv_member_order_detail_debug_log')) {
    function bv_member_order_detail_debug_log(array $ctx): void
    {
        $line = json_encode([
            'at' => gmdate('c'),
            'order_id' => (int) ($ctx['order_id'] ?? 0),
            'user_id' => (int) ($ctx['user_id'] ?? 0),
            'order_status' => (string) ($ctx['order_status'] ?? ''),
            'payment_status' => (string) ($ctx['payment_status'] ?? ''),
            'detected_request_mode' => (string) ($ctx['detected_request_mode'] ?? ''),
            'existing_request_found' => !empty($ctx['existing_request_found']) ? 1 : 0,
        ], JSON_UNESCAPED_SLASHES);

        if (!is_string($line) || $line === '') {
            return;
        }

        $targets = [
            dirname(__DIR__, 2) . '/private_html/member_order_detail_debug.log',
            dirname(__DIR__) . '/member_order_detail_debug.log',
        ];

        foreach ($targets as $file) {
            $dir = dirname($file);
            if (!is_dir($dir) || !is_writable($dir)) {
                continue;
            }
            @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
            return;
        }
    }
}

if (!function_exists('bv_member_order_detail_csrf_token')) {
    function bv_member_order_detail_csrf_token(string $scope = 'order_cancel_request'): string
    {
        if (empty($_SESSION['_csrf_order_cancel'][$scope]) || !is_string($_SESSION['_csrf_order_cancel'][$scope])) {
            $_SESSION['_csrf_order_cancel'][$scope] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf_order_cancel'][$scope];
    }
}

if (!function_exists('bv_member_order_detail_form_started_at')) {
    function bv_member_order_detail_form_started_at(string $scope = 'order_cancel_request'): int
    {
        $value = time();
        $_SESSION['_order_cancel_started_at'][$scope] = $value;
        return $value;
    }
}

if (!bv_member_order_detail_is_logged_in()) {
    bv_member_order_detail_redirect(bv_member_order_detail_login_url());
}

try {
    $pdo = bv_member_order_detail_db();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Database connection not available.';
    exit;
}

if (!bv_member_order_detail_table_exists($pdo, 'orders')) {
    http_response_code(500);
    echo 'Orders table not found.';
    exit;
}

$orderId = isset($_GET['id']) && is_numeric($_GET['id']) ? (int) $_GET['id'] : 0;
if ($orderId <= 0 && isset($_GET['order_id']) && is_numeric($_GET['order_id'])) {
    $orderId = (int) $_GET['order_id'];
}

if ($orderId <= 0) {
    http_response_code(404);
    echo 'Order not found.';
    exit;
}

$currentUserId = bv_member_order_detail_current_user_id();
$currentUserName = bv_member_order_detail_current_user_name();

$orderStmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
$orderStmt->execute([$orderId, $currentUserId]);
$order = $orderStmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    http_response_code(404);
    echo 'Order not found or access denied.';
    exit;
}

$orderItems = [];
if (bv_member_order_detail_table_exists($pdo, 'order_items')) {
    $itemsStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC");
    $itemsStmt->execute([$orderId]);
    $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
$cancelRequest = bv_member_order_detail_cancel_info($orderId);
$requestMode = bv_member_order_detail_request_mode($order);
$requestMeta = bv_member_order_detail_existing_request_meta($order, $cancelRequest);
$existingRequestType = (string) ($requestMeta['request_type'] ?? bv_member_order_detail_existing_request_type($order, $cancelRequest));
$terminalState = bv_member_order_detail_is_terminal_order_state($order);
$hasExistingRequest = !empty($requestMeta['has_request']);
$canRetryRequest = !$terminalState && !empty($requestMeta['can_retry']) && in_array($requestMode, ['cancel', 'refund'], true);
$canCancel = $requestMode === 'cancel' && (!$hasExistingRequest || $canRetryRequest) && !$terminalState;
$canRefund = $requestMode === 'refund' && (!$hasExistingRequest || $canRetryRequest) && !$terminalState;
$canRequestAction = ($canCancel || $canRefund);
$requestDisplayType = bv_member_order_detail_is_paid_state($order) ? 'refund' : 'cancel';
$requestType = $canRefund ? 'refund' : ($canCancel ? 'cancel' : $requestDisplayType);
$requestTitle = $requestType === 'refund' ? 'Request Refund' : 'Request Cancel';
$requestButtonLabel = $requestType === 'refund' ? 'Request Refund' : 'Request Cancel';
$requestReasonLabel = $requestType === 'refund' ? 'Reason for refund' : 'Reason';
$requestExplainPlaceholder = $requestType === 'refund'
    ? 'Please explain the reason for your refund request.'
    : 'Please explain the reason for your cancellation request.';
$requestHelperNote = $requestType === 'refund'
    ? 'Your request will be recorded first. The seller or admin will review it before refund approval, payment reversal, and stock handling move forward.'
    : 'Your cancellation request will be recorded first. Refund and stock reversal are handled in the next approval step, not instantly.';

$requestUnavailableTitle = $requestMode === 'refund'
    ? 'This order cannot be refunded from this page right now'
    : 'This order cannot be cancelled right now';

$requestUnavailableNote = $requestMode === 'refund'
    ? 'Refund requests are available only after payment and before the order progresses too far into fulfillment.'
    : 'Cancellation is only available in early order stages. Once processing or shipping begins, this option will no longer appear.';

bv_member_order_detail_debug_log([
    'order_id' => $orderId,
    'user_id' => $currentUserId,
    'order_status' => (string) ($order['status'] ?? ''),
    'payment_status' => (string) (($order['payment_status'] ?? '') ?: ($order['payment_state'] ?? '')),
    'detected_request_mode' => $requestMode,
    'existing_request_found' => $hasExistingRequest ? 1 : 0,
]);
	
$orderCode = bv_member_order_detail_order_code($order);
$orderTotal = bv_member_order_detail_order_total($order);
$currency = strtoupper(trim((string) ($order['currency'] ?? 'USD')));
$status = strtolower(trim((string) ($order['status'] ?? '')));
$paymentStatus = strtolower(trim((string) (($order['payment_status'] ?? '') ?: ($order['payment_state'] ?? ''))));
$orderSource = strtolower(trim((string) ($order['order_source'] ?? 'shop')));

if ($terminalState) {
    $requestUnavailableTitle = $status === 'refunded' ? 'Refunded' : 'Cancelled';
    $requestUnavailableNote = 'This order is already finalized, so no new cancel/refund request can be submitted.';
}

[$statusLabel, $statusColor, $statusBg] = bv_member_order_detail_status_badge($status);
[$paymentLabel, $paymentColor, $paymentBg] = bv_member_order_detail_payment_badge($paymentStatus !== '' ? $paymentStatus : 'unpaid');

$dashboardUrl = bv_member_order_detail_dashboard_url();
$ordersUrl = bv_member_order_detail_orders_url();

$flash = $_SESSION['order_cancel_flash'] ?? null;
unset($_SESSION['order_cancel_flash']);

$flashStatus = is_array($flash) ? (string) ($flash['status'] ?? '') : '';
$flashMessage = is_array($flash) ? (string) ($flash['message'] ?? '') : '';
$flashErrors = is_array($flash) && isset($flash['errors']) && is_array($flash['errors']) ? $flash['errors'] : [];
$flashOld = is_array($flash) && isset($flash['old']) && is_array($flash['old']) ? $flash['old'] : [];

$cancelReasonCodeOld = (string) ($flashOld['reason_code'] ?? 'other');
$cancelReasonTextOld = (string) ($flashOld['reason_text'] ?? '');
$selectedItemIdsOld = [];
if (isset($flashOld['selected_item_ids']) && is_array($flashOld['selected_item_ids'])) {
    foreach ($flashOld['selected_item_ids'] as $oldItemId) {
        if (is_numeric($oldItemId) && (int) $oldItemId > 0) {
            $selectedItemIdsOld[(int) $oldItemId] = true;
        }
    }
}
$selectedQtyOld = isset($flashOld['selected_qty']) && is_array($flashOld['selected_qty']) ? $flashOld['selected_qty'] : [];
$requestSelectableItems = [];
foreach ($orderItems as $orderItem) {
    $itemId = isset($orderItem['id']) && is_numeric($orderItem['id']) ? (int) $orderItem['id'] : 0;
    if ($itemId <= 0) {
        continue;
    }
    if (!bv_member_order_detail_item_is_selectable($orderItem, $requestType)) {
        continue;
    }
    $requestSelectableItems[] = $orderItem;
}
$hasSelectableRequestItems = !empty($requestSelectableItems);
$cancelCsrfToken = bv_member_order_detail_csrf_token('order_cancel_request');
$cancelFormStartedAt = bv_member_order_detail_form_started_at('order_cancel_request');

$pageTitle = $orderCode . ' | Order Detail | Bettavaro';

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= bv_member_order_detail_h($pageTitle); ?></title>
    <meta name="description" content="Secure customer order detail page.">
    <meta name="robots" content="noindex,nofollow">
    <style>
        :root{
            --bg:#07130e;
            --bg-2:#0b1b14;
            --panel:#ffffff;
            --ink:#0f172a;
            --muted:#64748b;
            --line:#dbe2ea;
            --gold:#d4b06a;
            --green:#166534;
            --green-soft:#dcfce7;
            --amber:#9a6700;
            --amber-soft:#fff8c5;
            --red:#991b1b;
            --red-soft:#fee2e2;
            --blue:#1d4ed8;
            --blue-soft:#dbeafe;
            --shadow:0 24px 70px rgba(0,0,0,.24);
            --radius:22px;
            --radius-sm:14px;
            --max:1240px;
        }
        *{box-sizing:border-box}
        html,body{margin:0;padding:0}
        body{
            font-family:Inter,"Segoe UI",Arial,sans-serif;
            color:#eef4ef;
            background:
                radial-gradient(circle at top, #123021 0%, #08140f 42%, #040b08 100%);
            min-height:100vh;
        }
        a{text-decoration:none;color:inherit}
        .wrap{max-width:var(--max);margin:0 auto;padding:28px 16px 80px}
        .topbar{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:14px;
            flex-wrap:wrap;
            margin-bottom:18px;
        }
        .crumbs{
            color:#d5dfd7;
            font-size:14px;
        }
        .crumbs a{color:var(--gold)}
        .actions{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }
        .btn,
        .btn-outline,
        .btn-soft,
        .btn-danger{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:44px;
            padding:0 16px;
            border-radius:999px;
            font-weight:800;
            font-size:14px;
            border:1px solid transparent;
            transition:.18s ease;
            cursor:pointer;
        }
        .btn{
            color:#0b140f;
            background:linear-gradient(180deg,#f1dab0 0%, #d4b06a 100%);
            box-shadow:0 10px 24px rgba(212,176,106,.22);
        }
        .btn:hover{transform:translateY(-1px)}
.btn-outline{
    color:#0f172a;
    border-color:#cbd5e1;
    background:#ffffff;
    box-shadow:0 4px 12px rgba(15,23,42,.08);
}
.btn-outline:hover{
    background:#f8fafc;
    border-color:#94a3b8;
}

.btn-soft{
    color:#ffffff;
    background:#0f172a;
    border-color:#0f172a;
    box-shadow:0 8px 18px rgba(15,23,42,.18);
}
.btn-soft:hover{
    background:#1e293b;
    border-color:#1e293b;
}
        .btn-danger{
            color:#fff;
            background:linear-gradient(180deg,#dc2626 0%, #b91c1c 100%);
            box-shadow:0 12px 28px rgba(185,28,28,.22);
        }
        .hero{
            background:linear-gradient(135deg, rgba(255,255,255,.10), rgba(255,255,255,.05));
            border:1px solid rgba(255,255,255,.12);
            border-radius:28px;
            padding:26px;
            box-shadow:var(--shadow);
            backdrop-filter:blur(10px);
            margin-bottom:20px;
        }
        .eyebrow{
            display:inline-flex;
            align-items:center;
            gap:8px;
            color:#efd59c;
            text-transform:uppercase;
            letter-spacing:.12em;
            font-size:12px;
            font-weight:900;
            margin-bottom:10px;
        }
        .eyebrow:before{
            content:"";
            width:18px;
            height:2px;
            border-radius:999px;
            background:linear-gradient(90deg, transparent, #efd59c);
            display:inline-block;
        }
        .hero h1{
            margin:0 0 8px;
            font-size:clamp(34px,5vw,54px);
            line-height:1.04;
            letter-spacing:-.03em;
        }
        .hero p{
            margin:0;
            max-width:780px;
            color:#d3ddd6;
            line-height:1.75;
            font-size:15px;
        }
        .meta-strip{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            margin-top:16px;
        }
        .badge{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:32px;
            padding:0 12px;
            border-radius:999px;
            font-size:12px;
            font-weight:900;
            letter-spacing:.02em;
        }
        .layout{
            display:grid;
            grid-template-columns:minmax(0,1.25fr) minmax(320px,.75fr);
            gap:20px;
            align-items:start;
        }
        .panel{
            background:rgba(255,255,255,.96);
            color:var(--ink);
            border-radius:24px;
            box-shadow:var(--shadow);
            border:1px solid rgba(255,255,255,.35);
            overflow:hidden;
        }
        .panel-head{
            padding:18px 20px 0;
        }
        .panel-head h2{
            margin:0;
            font-size:24px;
            line-height:1.1;
            letter-spacing:-.02em;
        }
        .panel-body{padding:20px}
        .alert{
            margin:0 0 18px;
            padding:14px 16px;
            border-radius:16px;
            font-size:14px;
            font-weight:700;
        }
        .alert.success{
            background:#ecfdf3;
            color:#166534;
            border:1px solid #bbf7d0;
        }
        .alert.error{
            background:#fef2f2;
            color:#b91c1c;
            border:1px solid #fecaca;
        }
        .grid-2{
            display:grid;
            grid-template-columns:repeat(2,minmax(0,1fr));
            gap:14px;
        }
        .meta-box{
            background:#f8fafc;
            border:1px solid #e2e8f0;
            border-radius:16px;
            padding:14px;
        }
        .meta-box .label{
            font-size:11px;
            text-transform:uppercase;
            letter-spacing:.10em;
            color:#64748b;
            font-weight:900;
            margin-bottom:7px;
        }
        .meta-box .value{
            font-size:15px;
            font-weight:800;
            color:#0f172a;
            word-break:break-word;
            line-height:1.6;
        }
        .items{
            display:grid;
            gap:14px;
        }
        .item-card{
            display:grid;
            grid-template-columns:94px minmax(0,1fr);
            gap:14px;
            align-items:start;
            padding:14px;
            border-radius:18px;
            background:#fff;
            border:1px solid #e7edf4;
        }
        .item-image{
            width:94px;
            height:94px;
            border-radius:16px;
            object-fit:cover;
            display:block;
            background:#f1f5f9;
        }
        .item-title{
            margin:0 0 6px;
            font-size:18px;
            line-height:1.2;
            color:#0f172a;
            font-weight:900;
        }
        .item-sub{
            color:#64748b;
            font-size:13px;
            margin-bottom:10px;
            line-height:1.6;
        }
        .item-pricing{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            color:#0f172a;
            font-size:14px;
            font-weight:800;
        }
        .side-stack{
            display:grid;
            gap:20px;
        }
        .total-box{
            display:grid;
            gap:12px;
        }
        .total-row{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            font-size:14px;
            color:#334155;
        }
        .total-row strong{
            color:#0f172a;
        }
        .total-row.grand{
            padding-top:12px;
            border-top:1px solid #e7edf4;
            font-size:18px;
            font-weight:900;
            color:#0f172a;
        }
        .cancel-box{
            background:#fff7ed;
            border:1px solid #fed7aa;
            border-radius:18px;
            padding:16px;
            margin-bottom:14px;
        }
        .cancel-box strong{
            color:#9a3412;
        }
        .field{
            margin-bottom:14px;
        }
        .field label{
            display:block;
            margin-bottom:6px;
            font-size:13px;
            font-weight:800;
            color:#334155;
        }
        .field input,
        .field select,
        .field textarea{
            width:100%;
            border:1px solid #dbe2ea;
            border-radius:14px;
            padding:12px 14px;
            font-size:14px;
            color:#0f172a;
            background:#fff;
            font-family:inherit;
        }
        .field textarea{
            min-height:120px;
            resize:vertical;
        }
        .error-text{
            margin-top:6px;
            color:#b91c1c;
            font-size:13px;
            font-weight:700;
        }
        .note{
            color:#64748b;
            font-size:13px;
            line-height:1.7;
        }
        .empty{
            padding:20px;
            text-align:center;
            color:#64748b;
        }
        @media (max-width: 1024px){
            .layout{grid-template-columns:1fr}
        }
        @media (max-width: 640px){
            .grid-2{grid-template-columns:1fr}
            .item-card{grid-template-columns:1fr}
            .item-image{width:100%;height:220px}
            .hero{padding:22px}
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="topbar">
            <div class="crumbs">
                <a href="<?= bv_member_order_detail_h($dashboardUrl); ?>">Dashboard</a>
                <span> / </span>
                <a href="<?= bv_member_order_detail_h($ordersUrl); ?>">My Orders</a>
                <span> / </span>
                <span><?= bv_member_order_detail_h($orderCode); ?></span>
            </div>
            <div class="actions">
                <a class="btn-outline" href="<?= bv_member_order_detail_h($ordersUrl); ?>">Back to My Orders</a>
            </div>
        </div>

        <section class="hero">
            <div class="eyebrow">Customer Order</div>
            <h1><?= bv_member_order_detail_h($orderCode); ?></h1>
            <p>Hello, <?= bv_member_order_detail_h($currentUserName); ?>. This page shows only your order details. Other customers stay politely invisible, as they should.</p>
            <div class="meta-strip">
                <span class="badge" style="color:<?= bv_member_order_detail_h($statusColor); ?>;background:<?= bv_member_order_detail_h($statusBg); ?>;"><?= bv_member_order_detail_h($statusLabel); ?></span>
                <span class="badge" style="color:<?= bv_member_order_detail_h($paymentColor); ?>;background:<?= bv_member_order_detail_h($paymentBg); ?>;"><?= bv_member_order_detail_h($paymentLabel); ?></span>
                <span class="badge" style="color:#334155;background:#e2e8f0;"><?= bv_member_order_detail_h(strtoupper($orderSource !== '' ? $orderSource : 'shop')); ?></span>
                <span class="badge" style="color:#0f172a;background:#f8fafc;"><?= bv_member_order_detail_h(bv_member_order_detail_money($orderTotal, $currency)); ?></span>
                <?php if (!empty($cancelRequest)): ?>
                    <?php
                    $cancelStatus = strtolower(trim((string) ($requestMeta['status'] ?? ($cancelRequest['status'] ?? 'requested'))));
                    [$cancelLabel, $cancelColor, $cancelBg] = bv_member_order_detail_status_badge($cancelStatus === 'approved' ? 'processing' : ($cancelStatus === 'requested' ? 'pending' : $cancelStatus));
                    ?>
                    <span class="badge" style="color:<?= bv_member_order_detail_h($cancelColor); ?>;background:<?= bv_member_order_detail_h($cancelBg); ?>;"><?= bv_member_order_detail_h((string) ($requestMeta['label'] ?? ($existingRequestType === 'refund' ? 'Refund Requested' : 'Cancel Requested'))); ?></span>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($flashMessage !== ''): ?>
            <div class="alert <?= in_array($flashStatus, ['requested', 'success'], true) ? 'success' : 'error'; ?>">
                <?= bv_member_order_detail_h($flashMessage); ?>
            </div>
        <?php endif; ?>

        <section class="layout">
            <div class="panel">
                <div class="panel-head">
                    <h2>Order Information</h2>
                </div>
                <div class="panel-body">
                    <div class="grid-2">
                        <div class="meta-box">
                            <div class="label">Order Code</div>
                            <div class="value"><?= bv_member_order_detail_h($orderCode); ?></div>
                        </div>
                        <div class="meta-box">
                            <div class="label">Order Source</div>
                            <div class="value"><?= bv_member_order_detail_h(strtoupper($orderSource !== '' ? $orderSource : 'SHOP')); ?></div>
                        </div>
                        <div class="meta-box">
                            <div class="label">Created At</div>
                            <div class="value"><?= bv_member_order_detail_h((string) (($order['created_at'] ?? '') ?: '-')); ?></div>
                        </div>
                        <div class="meta-box">
                            <div class="label">Paid At</div>
                            <div class="value"><?= bv_member_order_detail_h((string) (($order['paid_at'] ?? '') ?: '-')); ?></div>
                        </div>
                        <div class="meta-box">
                            <div class="label">Payment Method</div>
                            <div class="value"><?= bv_member_order_detail_h((string) (($order['payment_method'] ?? '') ?: '-')); ?></div>
                        </div>
                        <div class="meta-box">
                            <div class="label">Payment Reference</div>
                            <div class="value"><?= bv_member_order_detail_h((string) (($order['payment_reference'] ?? '') ?: '-')); ?></div>
                        </div>
                    </div>

                    <div style="height:20px;"></div>

                    <div class="grid-2">
                        <div class="meta-box">
                            <div class="label">Buyer Name</div>
                            <div class="value"><?= bv_member_order_detail_h((string) (($order['buyer_name'] ?? '') ?: '-')); ?></div>
                        </div>
                        <div class="meta-box">
                            <div class="label">Buyer Email</div>
                            <div class="value"><?= bv_member_order_detail_h((string) (($order['buyer_email'] ?? '') ?: '-')); ?></div>
                        </div>
                        <div class="meta-box">
                            <div class="label">Buyer Phone</div>
                            <div class="value"><?= bv_member_order_detail_h((string) (($order['buyer_phone'] ?? '') ?: '-')); ?></div>
                        </div>
                        <div class="meta-box">
                            <div class="label">Country</div>
                            <div class="value"><?= bv_member_order_detail_h((string) (($order['country'] ?? '') ?: '-')); ?></div>
                        </div>
                    </div>

                    <div style="height:20px;"></div>

                    <div class="grid-2">
                        <div class="meta-box">
<?php
$shipToText = trim((string) ($order['ship_name'] ?? ''));
$shipAddressText = trim((string) ($order['ship_address'] ?? ''));
$shipToDisplay = trim($shipToText . "\n" . $shipAddressText);
if ($shipToDisplay === '') {
    $shipToDisplay = '-';
}
?>

                        <div class="meta-box">
                            <div class="label">Shipping Contact</div>
                            <div class="value">
                                <?= bv_member_order_detail_h((string) (($order['ship_phone'] ?? '') ?: '-')); ?><br>
                                <?= bv_member_order_detail_h((string) (($order['ship_email'] ?? '') ?: '-')); ?>
                            </div>
                        </div>
                    </div>

                    <div style="height:20px;"></div>

                    <h2 style="margin:0 0 14px;font-size:24px;line-height:1.1;letter-spacing:-.02em;">Order Items</h2>

                    <?php if (!$orderItems): ?>
                        <div class="empty">No order items found for this order.</div>
                    <?php else: ?>
                        <div class="items">
                            <?php foreach ($orderItems as $item): ?>
                                <?php
                                $itemTitle = bv_member_order_detail_item_title($item);
                                $itemQty = bv_member_order_detail_item_qty($item);
                                $itemPrice = bv_member_order_detail_item_price($item);
                                $itemTotal = bv_member_order_detail_item_total($item);
                                $itemImage = bv_member_order_detail_item_image($item);
                                $itemUrl = bv_member_order_detail_listing_url($item);
                                ?>
                                <article class="item-card">
                                    <div>
                                        <?php if ($itemUrl !== '#'): ?>
                                            <a href="<?= bv_member_order_detail_h($itemUrl); ?>">
                                                <img class="item-image" src="<?= bv_member_order_detail_h($itemImage); ?>" alt="<?= bv_member_order_detail_h($itemTitle); ?>">
                                            </a>
                                        <?php else: ?>
                                            <img class="item-image" src="<?= bv_member_order_detail_h($itemImage); ?>" alt="<?= bv_member_order_detail_h($itemTitle); ?>">
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <h3 class="item-title">
                                            <?php if ($itemUrl !== '#'): ?>
                                                <a href="<?= bv_member_order_detail_h($itemUrl); ?>"><?= bv_member_order_detail_h($itemTitle); ?></a>
                                            <?php else: ?>
                                                <?= bv_member_order_detail_h($itemTitle); ?>
                                            <?php endif; ?>
                                        </h3>
                                        <div class="item-sub">
                                            <?= bv_member_order_detail_h((string) (($item['species_snapshot'] ?? '') ?: ($item['species'] ?? ''))); ?>
                                            <?php if (!empty($item['strain_snapshot']) || !empty($item['strain'])): ?>
                                                • <?= bv_member_order_detail_h((string) (($item['strain_snapshot'] ?? '') ?: ($item['strain'] ?? ''))); ?>
                                            <?php endif; ?>
                                            <?php if (!empty($item['grade_snapshot']) || !empty($item['grade'])): ?>
                                                • <?= bv_member_order_detail_h((string) (($item['grade_snapshot'] ?? '') ?: ($item['grade'] ?? ''))); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="item-pricing">
                                            <span>Qty: <?= (int) $itemQty; ?></span>
                                            <span>Unit: <?= bv_member_order_detail_h(bv_member_order_detail_money($itemPrice, $currency)); ?></span>
                                            <span>Total: <?= bv_member_order_detail_h(bv_member_order_detail_money($itemTotal, $currency)); ?></span>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="side-stack">
                <div class="panel">
                    <div class="panel-head">
                        <h2>Order Summary</h2>
                    </div>
                    <div class="panel-body">
                        <div class="total-box">
                            <div class="total-row">
                                <span>Subtotal</span>
                                <strong><?= bv_member_order_detail_h(bv_member_order_detail_money((float) (($order['subtotal'] ?? 0) ?: 0), $currency)); ?></strong>
                            </div>
                            <div class="total-row">
                                <span>Discount</span>
                                <strong><?= bv_member_order_detail_h(bv_member_order_detail_money((float) (($order['discount_amount'] ?? 0) ?: 0), $currency)); ?></strong>
                            </div>
                            <div class="total-row">
                                <span>Shipping</span>
                                <strong><?= bv_member_order_detail_h(bv_member_order_detail_money((float) (($order['shipping_amount'] ?? 0) ?: 0), $currency)); ?></strong>
                            </div>
                            <?php if (isset($order['seller_discount_total']) && $order['seller_discount_total'] !== '' && $order['seller_discount_total'] !== null): ?>
                                <div class="total-row">
                                    <span>Seller Discount</span>
                                    <strong><?= bv_member_order_detail_h(bv_member_order_detail_money((float) $order['seller_discount_total'], $currency)); ?></strong>
                                </div>
                            <?php endif; ?>
                            <div class="total-row grand">
                                <span>Grand Total</span>
                                <strong><?= bv_member_order_detail_h(bv_member_order_detail_money($orderTotal, $currency)); ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="panel" id="cancel-order">
                    <div class="panel-head">
                        <h2><?= bv_member_order_detail_h($requestTitle); ?></h2>
                    </div>
                    <div class="panel-body">
                        <?php if (!empty($cancelRequest)): ?>
                            <div class="cancel-box">
                                <div style="font-size:16px;font-weight:900;margin-bottom:8px;"><?= bv_member_order_detail_h((string) ($requestMeta['label'] ?? ($existingRequestType === 'refund' ? 'Refund Requested' : 'Cancel Requested'))); ?></div>
                                <div class="note">
                                    Request type: <strong><?= bv_member_order_detail_h($existingRequestType === 'refund' ? 'Refund' : 'Cancel'); ?></strong><br>
                                    Status: <strong><?= bv_member_order_detail_h((string) ($requestMeta['status'] ?? ($cancelRequest['status'] ?? 'requested'))); ?></strong><br>
                                    Reason code: <?= bv_member_order_detail_h((string) ($cancelRequest['cancel_reason_code'] ?? '-')); ?><br>
                                    Requested at: <?= bv_member_order_detail_h((string) (($cancelRequest['requested_at'] ?? '') ?: '-')); ?><br>
                                    Refund status: <?= bv_member_order_detail_h((string) (($cancelRequest['refund_status'] ?? '') ?: '-')); ?><br>
                                    Amount: <?= bv_member_order_detail_h(isset($cancelRequest['refund_amount']) && is_numeric($cancelRequest['refund_amount']) ? bv_member_order_detail_money((float) $cancelRequest['refund_amount'], $currency) : '-'); ?>
                                </div>
                            </div>
                        <?php elseif (!$canRequestAction): ?>
                            <div class="cancel-box" style="background:#f8fafc;border-color:#e2e8f0;">
                                <div style="font-size:16px;font-weight:900;margin-bottom:8px;color:#0f172a;"><?= bv_member_order_detail_h($requestUnavailableTitle); ?></div>
                                <div class="note">
                                    <?= bv_member_order_detail_h($requestUnavailableNote); ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <form method="post" action="/order_cancel_request.php" novalidate>
                                <input type="hidden" name="order_id" value="<?= (int) $orderId; ?>">
								<input type="hidden" name="request_kind" value="<?= bv_member_order_detail_h($requestType); ?>">
                                <input type="hidden" name="csrf_token" value="<?= bv_member_order_detail_h($cancelCsrfToken); ?>">
                                <input type="hidden" name="form_started_at" value="<?= (int) $cancelFormStartedAt; ?>">
                                <input type="hidden" name="return_url" value="<?= bv_member_order_detail_h('/member/order_detail.php?id=' . $orderId); ?>">

                                <div style="position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
                                    <label for="cancel-website">Website</label>
                                    <input type="text" name="website" id="cancel-website" value="" tabindex="-1" autocomplete="off">
                                </div>

                                <div class="field">
                                    <label for="cancel_reason_code"><?= bv_member_order_detail_h($requestReasonLabel); ?></label>
                                    <select name="reason_code" id="cancel_reason_code">
                                        <option value="changed_mind" <?= $cancelReasonCodeOld === 'changed_mind' ? 'selected' : ''; ?>>Changed my mind</option>
                                        <option value="wrong_item" <?= $cancelReasonCodeOld === 'wrong_item' ? 'selected' : ''; ?>>Wrong item</option>
                                        <option value="duplicate_order" <?= $cancelReasonCodeOld === 'duplicate_order' ? 'selected' : ''; ?>>Duplicate order</option>
                                        <option value="payment_issue" <?= $cancelReasonCodeOld === 'payment_issue' ? 'selected' : ''; ?>>Payment issue</option>
                                        <option value="shipping_issue" <?= $cancelReasonCodeOld === 'shipping_issue' ? 'selected' : ''; ?>>Shipping issue</option>
                                        <option value="seller_delay" <?= $cancelReasonCodeOld === 'seller_delay' ? 'selected' : ''; ?>>Seller delay</option>
                                        <option value="mistake_order" <?= $cancelReasonCodeOld === 'mistake_order' ? 'selected' : ''; ?>>Mistake in order</option>
                                        <option value="other" <?= $cancelReasonCodeOld === 'other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>

                                 <div class="field">
                                    <label for="cancel_reason_text">Tell us why</label>
                                    <textarea name="reason_text" id="cancel_reason_text" placeholder="<?= bv_member_order_detail_h($requestExplainPlaceholder); ?>"><?= bv_member_order_detail_h($cancelReasonTextOld); ?></textarea>
<?php if (!empty($flashErrors['reason_text'])): ?>
    <div class="error-text"><?= bv_member_order_detail_h((string) $flashErrors['reason_text']); ?></div>
<?php endif; ?>
                                </div>

                                <div class="field">
                                    <label>Select item(s)</label>
                                    <?php if ($hasSelectableRequestItems): ?>
                                        <div style="display:flex;flex-direction:column;gap:10px;border:1px solid #e2e8f0;border-radius:12px;padding:10px;background:#f8fafc;">
                                            <?php foreach ($requestSelectableItems as $reqItem): ?>
                                                <?php
                                                $reqItemId = isset($reqItem['id']) && is_numeric($reqItem['id']) ? (int) $reqItem['id'] : 0;
                                                if ($reqItemId <= 0) {
                                                    continue;
                                                }
                                                $reqQty = bv_member_order_detail_item_qty($reqItem);
                                                $reqTitle = (string) (($reqItem['listing_title_snapshot'] ?? '') ?: ($reqItem['title'] ?? ($reqItem['item_name'] ?? ('Item #' . $reqItemId))));
                                                $reqChecked = isset($selectedItemIdsOld[$reqItemId]) || empty($selectedItemIdsOld);
                                                $oldQtyValue = isset($selectedQtyOld[$reqItemId]) && is_numeric($selectedQtyOld[$reqItemId]) ? (int) $selectedQtyOld[$reqItemId] : $reqQty;
                                                if ($oldQtyValue <= 0) {
                                                    $oldQtyValue = 1;
                                                } elseif ($oldQtyValue > $reqQty) {
                                                    $oldQtyValue = $reqQty;
                                                }
                                                ?>
                                                <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;">
                                                    <label style="display:flex;align-items:center;gap:8px;margin:0;">
                                                        <input type="checkbox" name="selected_item_ids[]" value="<?= (int) $reqItemId; ?>" <?= $reqChecked ? 'checked' : ''; ?>>
                                                        <span><?= bv_member_order_detail_h($reqTitle); ?> (Qty: <?= (int) $reqQty; ?>)</span>
                                                    </label>
                                                    <?php if ($reqQty > 1): ?>
                                                        <div style="display:flex;align-items:center;gap:6px;">
                                                            <span style="font-size:12px;color:#475569;">Request qty</span>
                                                            <input type="number" name="selected_qty[<?= (int) $reqItemId; ?>]" min="1" max="<?= (int) $reqQty; ?>" value="<?= (int) $oldQtyValue; ?>" style="width:80px;padding:8px;border:1px solid #cbd5e1;border-radius:8px;">
                                                        </div>
                                                    <?php else: ?>
                                                        <input type="hidden" name="selected_qty[<?= (int) $reqItemId; ?>]" value="1">
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="note">No eligible items are available for this request.</div>
                                    <?php endif; ?>
<?php if (!empty($flashErrors['selected_items'])): ?>
    <div class="error-text"><?= bv_member_order_detail_h((string) $flashErrors['selected_items']); ?></div>
<?php endif; ?>
                                </div>

                                <?php if (!empty($flashErrors['csrf'])): ?>
                                    <div class="error-text" style="margin-bottom:10px;"><?= bv_member_order_detail_h((string) $flashErrors['csrf']); ?></div>
                                <?php endif; ?>

                                <?php if (!empty($flashErrors['form'])): ?>
                                    <div class="error-text" style="margin-bottom:10px;"><?= bv_member_order_detail_h((string) $flashErrors['form']); ?></div>
                                <?php endif; ?>
								<?php if (!empty($flashErrors['order_id'])): ?>
								<div class="error-text" style="margin-bottom:10px;"><?= bv_member_order_detail_h((string) $flashErrors['order_id']); ?></div>
								<?php endif; ?>

   <div class="note" style="margin-bottom:14px;">
                                    <?= bv_member_order_detail_h($requestHelperNote); ?>
                                </div>

                                <input type="hidden" name="request_type" value="<?= bv_member_order_detail_h($requestType); ?>">
                                <button class="btn-danger" type="submit" <?= $hasSelectableRequestItems ? '' : 'disabled'; ?>><?= bv_member_order_detail_h($requestButtonLabel); ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-head">
                        <h2>Quick Links</h2>
                    </div>
                    <div class="panel-body" style="display:flex;gap:10px;flex-wrap:wrap;">
                        <a class="btn-outline" href="<?= bv_member_order_detail_h($ordersUrl); ?>">Back to My Orders</a>
                        <a class="btn-soft" href="<?= bv_member_order_detail_h($dashboardUrl); ?>">Dashboard</a>
                    </div>
                </div>
            </div>
        </section>
    </div>
</body>
</html>