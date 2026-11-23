<?php
/**
 * Nexus Agency — Standalone React Admin PHP Persistence Bridge
 * Optional JSON API endpoint when deployed on Apache / Nginx / cPanel PHP hosting.
 * Stores data in ./data/hpay-db.json with hashed passwords and session auth.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
);

session_name('nexus_admin_sid');
session_set_cookie_params([
    'lifetime' => 86400 * 7,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => $isHttps ? 'None' : 'Lax',
]);
session_start();

$dataDir = __DIR__ . '/data';
$dbFile = $dataDir . '/hpay-db.json';
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0750, true);
}
$htaccessData = $dataDir . '/.htaccess';
if (!file_exists($htaccessData)) {
    @file_put_contents($htaccessData, "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
}

function default_db(): array {
    return [
        'config' => [
            'platformName' => 'Nexus Agency',
            'countryCode' => 'EG',
            'currencyCode' => 'EGP',
            'usdRate' => 49.85,
            'defaultDepositNumber' => '',
            'depositMethodNumbers' => [
                'vodafone' => '',
                'etisalat' => '',
                'orange' => '',
                'binance' => '',
            ],
            'adminPasswordHash' => null,
        ],
        'users' => [],
        'onlinePresence' => [],
        'deposits' => [],
        'withdrawals' => [],
        'messages' => [],
    ];
}

function load_db(string $file): array {
    if (!file_exists($file)) {
        $seed = default_db();
        @file_put_contents($file, json_encode($seed, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $seed;
    }
    $raw = @file_get_contents($file);
    $parsed = $raw ? json_decode($raw, true) : null;
    if (!is_array($parsed) || !isset($parsed['config'])) {
        return default_db();
    }
    $parsed['config']['platformName'] = 'Nexus Agency';
    return $parsed;
}

function save_db(string $file, array $db): void {
    $db['config']['platformName'] = 'Nexus Agency';
    unset($db['config']['adminPassword']);
    @file_put_contents($file, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function sanitize_user(array $u): array {
    $hasPass = !empty($u['passwordHash']) || !empty($u['password']);
    unset($u['passwordHash'], $u['password']);
    $u['hasPassword'] = $hasPass;
    return $u;
}

function public_state(array $db): array {
    $safeConfig = $db['config'] ?? [];
    unset($safeConfig['adminPassword'], $safeConfig['adminPasswordHash']);
    $safeUsers = array_map('sanitize_user', $db['users'] ?? []);
    return [
        'config' => $safeConfig,
        'users' => $safeUsers,
        'onlinePresence' => $db['onlinePresence'] ?? [],
        'onlineCount' => count($db['onlinePresence'] ?? []),
        'deposits' => $db['deposits'] ?? [],
        'withdrawals' => $db['withdrawals'] ?? [],
        'messages' => $db['messages'] ?? [],
    ];
}

function generate_player_id(array $users): string {
    $taken = [];
    foreach ($users as $u) {
        if (!empty($u['playerId'])) $taken[$u['playerId']] = true;
    }
    for ($i = 0; $i < 25; $i++) {
        $cand = 'PL-' . random_int(10000000, 99999999);
        if (!isset($taken[$cand])) return $cand;
    }
    return 'PL-' . substr((string)time(), -8);
}

function get_env_admin_password(): string {
    $val = getenv('ADMIN_PASSWORD');
    return is_string($val) ? trim($val) : '';
}

function needs_admin_setup(array $db): bool {
    if (get_env_admin_password() !== '') return false;
    return empty($db['config']['adminPasswordHash']);
}

function verify_admin_secret(string $candidate, array $db): bool {
    $envPass = get_env_admin_password();
    if ($envPass !== '') {
        return hash_equals($envPass, $candidate);
    }
    $hash = (string)($db['config']['adminPasswordHash'] ?? '');
    if ($hash !== '') {
        return password_verify($candidate, $hash);
    }
    return false;
}

function get_bearer_token(): string {
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (stripos($auth, 'Bearer ') === 0) {
        return trim(substr($auth, 7));
    }
    return '';
}

function is_admin_authenticated(): bool {
    if (empty($_SESSION['admin_authed']) || empty($_SESSION['admin_token'])) {
        return false;
    }
    $bearer = get_bearer_token();
    if ($bearer !== '') {
        return hash_equals((string)$_SESSION['admin_token'], $bearer);
    }
    return true;
}

function issue_admin_session(): string {
    session_regenerate_id(true);
    $token = bin2hex(random_bytes(24));
    $_SESSION['admin_authed'] = true;
    $_SESSION['admin_token'] = $token;
    return $token;
}

function require_mutation_header(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        if ($xrw === '') {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Missing request verification header'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

$db = load_db($dbFile);
$action = (string)($_GET['action'] ?? $_GET['api'] ?? 'status');
$rawBody = file_get_contents('php://input');
$body = $rawBody ? (json_decode($rawBody, true) ?: []) : [];

if ($action === 'status') {
    $authed = is_admin_authenticated();
    echo json_encode([
        'ok' => true,
        'needsSetup' => needs_admin_setup($db),
        'authenticated' => $authed,
        'state' => $authed ? public_state($db) : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'setup') {
    require_mutation_header();
    if (!needs_admin_setup($db)) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'تم إعداد كلمة مرور الإدارة بالفعل'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pass = trim((string)($body['password'] ?? ''));
    if (strlen($pass) < 6) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'يجب أن لا تقل كلمة المرور عن 6 أحرف'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $db['config']['adminPasswordHash'] = password_hash($pass, PASSWORD_DEFAULT);
    save_db($dbFile, $db);
    $token = issue_admin_session();
    echo json_encode(['ok' => true, 'token' => $token, 'state' => public_state($db)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'auth') {
    require_mutation_header();
    if (needs_admin_setup($db)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'يرجى تعيين كلمة مرور الإدارة أولاً'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pass = trim((string)($body['password'] ?? ''));
    if (verify_admin_secret($pass, $db)) {
        $token = issue_admin_session();
        echo json_encode(['ok' => true, 'token' => $token, 'state' => public_state($db)], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'كلمة مرور الأدمن مش صح'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($action === 'logout') {
    require_mutation_header();
    $_SESSION = [];
    session_destroy();
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'login') {
    require_mutation_header();
    $email = strtolower(trim((string)($body['email'] ?? '')));
    $pass = trim((string)($body['password'] ?? ''));
    foreach ($db['users'] as $idx => $u) {
        $uEmail = strtolower(trim((string)($u['email'] ?? '')));
        $hash = (string)($u['passwordHash'] ?? '');
        $legacyPass = (string)($u['password'] ?? '');
        $ok = ($hash !== '' && password_verify($pass, $hash)) || ($legacyPass !== '' && hash_equals($legacyPass, $pass));
        if ($uEmail === $email && $ok) {
            if (empty($u['active'])) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'هذا الحساب غير مفعّل من لوحة الإدارة — برجاء التواصل مع الإدارة لتفعيل الحساب'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            if ($hash === '' && $legacyPass !== '') {
                $db['users'][$idx]['passwordHash'] = password_hash($pass, PASSWORD_DEFAULT);
                unset($db['users'][$idx]['password']);
                save_db($dbFile, $db);
                $u = $db['users'][$idx];
            }
            $uid = $u['id'];
            $safeConfig = $db['config'];
            unset($safeConfig['adminPassword'], $safeConfig['adminPasswordHash']);
            $slice = [
                'config' => $safeConfig,
                'deposits' => array_values(array_filter($db['deposits'] ?? [], fn($d) => ($d['userId'] ?? '') === $uid)),
                'withdrawals' => array_values(array_filter($db['withdrawals'] ?? [], fn($w) => ($w['userId'] ?? '') === $uid)),
                'messages' => array_values(array_filter($db['messages'] ?? [], fn($m) => ($m['userId'] ?? '') === $uid)),
            ];
            echo json_encode(['ok' => true, 'userId' => $uid, 'user' => sanitize_user($u), 'slice' => $slice], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة'], JSON_UNESCAPED_UNICODE);
    exit;
}

// All remaining routes require an authenticated admin session
if (!is_admin_authenticated()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_mutation_header();
}

switch ($action) {
    case 'state':
        echo json_encode(['ok' => true, 'state' => public_state($db)], JSON_UNESCAPED_UNICODE);
        break;

    case 'create_user':
        $email = trim((string)($body['email'] ?? ''));
        $pass = trim((string)($body['password'] ?? ''));
        if (!$email || !$pass) {
            echo json_encode(['ok' => false, 'error' => 'البريد وكلمة المرور مطلوبين'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        foreach ($db['users'] as $u) {
            if (strtolower(trim((string)($u['email'] ?? ''))) === strtolower($email)) {
                echo json_encode(['ok' => false, 'error' => 'البريد الإلكتروني مسجل من قبل'], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', explode('@', $email)[0]) ?: 'agent';
        $pid = !empty($body['playerId']) ? trim((string)$body['playerId']) : generate_player_id($db['users']);
        $fixedDepNum = trim((string)($body['fixedDepositNumber'] ?? ''));
        $fixedDepMethod = $fixedDepNum !== '' ? (trim((string)($body['fixedDepositMethod'] ?? '')) ?: 'فودافون كاش') : '';
        $newUser = [
            'id' => !empty($body['id']) ? trim((string)$body['id']) : ('usr_' . (int)(microtime(true) * 1000)),
            'playerId' => $pid,
            'uuid' => !empty($body['uuid']) ? trim((string)$body['uuid']) : sprintf('%08x-%04x-4533-a6f9-%012x', random_int(1, 0xffffffff), random_int(1, 0xffff), random_int(1, 0xffffffffffff)),
            'username' => trim((string)($body['username'] ?? ($prefix . '_egp'))),
            'displayName' => trim((string)($body['displayName'] ?? $prefix)),
            'email' => $email,
            'emailLower' => strtolower($email),
            'passwordHash' => password_hash($pass, PASSWORD_DEFAULT),
            'telegramHandle' => '@' . $prefix,
            'balanceEgp' => 0,
            'frozenEgp' => 0,
            'insuranceEgp' => null,
            'canTrade' => true,
            'active' => ($body['active'] ?? true) !== false,
            'depositFeePct' => 3.0,
            'withdrawalFeePct' => 1.8,
            'referralCode' => 'EG-' . str_replace('PL-', '', $pid),
            'referralRatePct' => 0.5,
            'twoFactorEnabled' => true,
            'trc20Address' => 'TY9xK8mPq7VwRz4nLp3JdXs2kF9aBc7eW1',
            'fixedDepositNumber' => $fixedDepNum,
            'fixedDepositMethod' => $fixedDepMethod,
            'createdAt' => gmdate('c'),
        ];
        array_unshift($db['users'], $newUser);
        save_db($dbFile, $db);
        echo json_encode(['ok' => true, 'user' => sanitize_user($newUser), 'state' => public_state($db)], JSON_UNESCAPED_UNICODE);
        break;

    case 'update_user':
        $uid = (string)($body['userId'] ?? '');
        foreach ($db['users'] as &$u) {
            if ($u['id'] === $uid) {
                $patch = is_array($body['patch'] ?? null) ? $body['patch'] : [];
                if (isset($patch['email'])) {
                    $u['email'] = trim((string)$patch['email']);
                    $u['emailLower'] = strtolower($u['email']);
                }
                if (!empty($patch['password'])) {
                    $u['passwordHash'] = password_hash(trim((string)$patch['password']), PASSWORD_DEFAULT);
                    unset($u['password']);
                }
                if (isset($patch['displayName'])) $u['displayName'] = trim((string)$patch['displayName']);
                if (isset($patch['balanceEgp'])) $u['balanceEgp'] = max(0, (float)$patch['balanceEgp']);
                if (isset($patch['active'])) $u['active'] = (bool)$patch['active'];
                if (isset($patch['canTrade'])) $u['canTrade'] = (bool)$patch['canTrade'];
                if (isset($patch['fixedDepositNumber'])) $u['fixedDepositNumber'] = trim((string)$patch['fixedDepositNumber']);
                if (isset($patch['fixedDepositMethod'])) $u['fixedDepositMethod'] = trim((string)$patch['fixedDepositMethod']);
                save_db($dbFile, $db);
                echo json_encode(['ok' => true, 'user' => sanitize_user($u), 'state' => public_state($db)], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
        echo json_encode(['ok' => false, 'error' => 'اللاعب غير موجود'], JSON_UNESCAPED_UNICODE);
        break;

    case 'set_deposit_number':
        $targetId = trim((string)($body['userId'] ?? ''));
        $ident = strtolower(trim((string)($body['identifier'] ?? '')));
        $identNoAt = ltrim($ident, '@');
        $depNum = trim((string)($body['depositNumber'] ?? ''));
        $depMethod = trim((string)($body['depositMethod'] ?? ''));
        if ($depNum === '') {
            echo json_encode(['ok' => false, 'error' => 'اكتب رقم الإيداع الثابت أولاً'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $foundIdx = -1;
        foreach ($db['users'] as $i => $u) {
            $uEmail = strtolower(trim((string)($u['email'] ?? '')));
            $uUser = ltrim(strtolower(trim((string)($u['username'] ?? ''))), '@');
            $uPid = strtolower(trim((string)($u['playerId'] ?? '')));
            if (($targetId && $u['id'] === $targetId) || ($ident && ($uEmail === $ident || $uUser === $identNoAt || $uPid === $ident))) {
                $foundIdx = $i;
                break;
            }
        }
        if ($foundIdx < 0) {
            echo json_encode(['ok' => false, 'error' => 'لم يتم العثور على الحساب بالبريد أو ID اللاعب'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $db['users'][$foundIdx]['fixedDepositNumber'] = $depNum;
        if ($depMethod !== '') {
            $db['users'][$foundIdx]['fixedDepositMethod'] = $depMethod;
        }
        save_db($dbFile, $db);
        echo json_encode(['ok' => true, 'user' => sanitize_user($db['users'][$foundIdx]), 'state' => public_state($db)], JSON_UNESCAPED_UNICODE);
        break;

    case 'delete_user':
        $uid = (string)($body['userId'] ?? '');
        $db['users'] = array_values(array_filter($db['users'], fn($u) => ($u['id'] ?? '') !== $uid));
        save_db($dbFile, $db);
        echo json_encode(['ok' => true, 'state' => public_state($db)], JSON_UNESCAPED_UNICODE);
        break;

    case 'send_withdrawal':
        $uid = (string)($body['userId'] ?? '');
        $target = null;
        foreach ($db['users'] as $u) {
            if ($u['id'] === $uid) { $target = $u; break; }
        }
        if (!$target) {
            echo json_encode(['ok' => false, 'error' => 'اختر مستخدم صحيح أولاً'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $amount = max(1, (float)($body['amountEgp'] ?? 0));
        $rate = (float)($db['config']['usdRate'] ?? 49.85);
        $wdId = 'wd_' . (int)(microtime(true) * 1000);
        $orderId = 'WD-' . random_int(400000, 999999);
        $now = gmdate('c');
        $method = trim((string)($body['destinationMethod'] ?? 'فودافون كاش'));
        $reqNum = trim((string)($body['requisiteNumber'] ?? '01000000000'));
        $recName = trim((string)($body['recipientName'] ?? $target['displayName']));
        $wd = [
            'id' => $wdId,
            'userId' => $uid,
            'orderId' => $orderId,
            'accountId' => 'acc_' . $uid,
            'amountLocal' => $amount,
            'amountUsdt' => round($amount / $rate, 2),
            'incomeUsdt' => 0,
            'rate' => $rate,
            'destinationMethod' => $method,
            'requisiteNumber' => $reqNum,
            'recipientName' => $recName,
            'status' => 'awaiting_confirmation',
            'createdAt' => $now,
            'expiresAt' => gmdate('c', time() + 1800),
            'adminCreated' => true,
            'receiptFiles' => [],
        ];
        array_unshift($db['withdrawals'], $wd);
        $note = trim((string)($body['note'] ?? ''));
        $msg = [
            'id' => 'msg_' . (int)(microtime(true) * 1000),
            'userId' => $uid,
            'deviceId' => 'admin_desk',
            'deviceName' => 'إدارة المنصة',
            'sender' => 'طلب سحب من الإدارة',
            'body' => $note ?: "عندك طلب سحب بمبلغ " . number_format($amount) . " ج.م إلى {$method} ({$reqNum}). أكّد عشان يتخصم من رصيدك أو ارفض لو مش موافق.",
            'parsedAmount' => $amount,
            'currency' => 'EGP',
            'matchedDepositId' => null,
            'matchedOrderId' => $orderId,
            'withdrawalId' => $wdId,
            'withdrawalAmountEgp' => $amount,
            'withdrawalDestination' => "{$method} · {$reqNum}",
            'withdrawalRecipient' => $recName,
            'withdrawalActionStatus' => 'pending_confirmation',
            'status' => 'unmatched',
            'receivedAt' => $now,
        ];
        array_unshift($db['messages'], $msg);
        save_db($dbFile, $db);
        echo json_encode(['ok' => true, 'state' => public_state($db)], JSON_UNESCAPED_UNICODE);
        break;

    case 'send_message':
        $msg = [
            'id' => 'msg_' . (int)(microtime(true) * 1000),
            'userId' => (string)($body['userId'] ?? ''),
            'deviceId' => 'admin_desk',
            'deviceName' => 'لوحة الإدارة',
            'sender' => trim((string)($body['sender'] ?? 'إدارة المنصة')),
            'body' => trim((string)($body['body'] ?? '')),
            'parsedAmount' => null,
            'currency' => 'EGP',
            'matchedDepositId' => null,
            'matchedOrderId' => null,
            'status' => 'unmatched',
            'receivedAt' => gmdate('c'),
        ];
        array_unshift($db['messages'], $msg);
        save_db($dbFile, $db);
        echo json_encode(['ok' => true, 'state' => public_state($db)], JSON_UNESCAPED_UNICODE);
        break;

    case 'create_deposit':
        $targetId = trim((string)($body['userId'] ?? ''));
        $ident = strtolower(trim((string)($body['identifier'] ?? '')));
        $identNoAt = ltrim($ident, '@');
        $foundIndex = -1;
        foreach ($db['users'] as $i => $u) {
            $uEmail = strtolower(trim((string)($u['email'] ?? '')));
            $uUser = ltrim(strtolower(trim((string)($u['username'] ?? ''))), '@');
            $uPid = strtolower(trim((string)($u['playerId'] ?? '')));
            if (($targetId && $u['id'] === $targetId) || ($ident && ($uEmail === $ident || $uUser === $identNoAt || $uPid === $ident))) {
                $foundIndex = $i;
                break;
            }
        }
        if ($foundIndex < 0) {
            echo json_encode(['ok' => false, 'error' => 'لم يتم العثور على العميل بالبريد أو اليوزرنيم ده'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $amount = max(1, (float)($body['amountEgp'] ?? 0));
        $rate = (float)($db['config']['usdRate'] ?? 49.85);
        $autoCredit = ($body['autoCredit'] ?? true) !== false;
        $now = gmdate('c');
        if ($autoCredit) {
            $db['users'][$foundIndex]['balanceEgp'] = round((float)($db['users'][$foundIndex]['balanceEgp'] ?? 0) + $amount, 2);
        }
        $targetUser = $db['users'][$foundIndex];
        $depId = 'dep_' . (int)(microtime(true) * 1000);
        $orderId = 'EG-' . random_int(884200, 984199);
        $dep = [
            'id' => $depId,
            'userId' => $targetUser['id'],
            'orderId' => $orderId,
            'accountId' => 'acc_' . $targetUser['id'],
            'amountLocal' => $amount,
            'amountUsdt' => round($amount / $rate, 2),
            'incomeUsdt' => 0,
            'rate' => $rate,
            'requisiteId' => 'req_admin',
            'bankName' => trim((string)($body['bankName'] ?? '')) ?: 'إيداع فوري من الإدارة',
            'ownerName' => $targetUser['displayName'],
            'requisiteNumber' => $targetUser['username'],
            'status' => $autoCredit ? 'completed' : 'pending',
            'createdAt' => $now,
            'expiresAt' => gmdate('c', time() + 900),
            'confirmedAt' => $autoCredit ? $now : null,
            'customerNote' => trim((string)($body['note'] ?? '')),
        ];
        array_unshift($db['deposits'], $dep);
        save_db($dbFile, $db);
        echo json_encode(['ok' => true, 'user' => sanitize_user($targetUser), 'state' => public_state($db)], JSON_UNESCAPED_UNICODE);
        break;

    case 'deposit_action':
        $depId = (string)($body['id'] ?? '');
        $act = (string)($body['action'] ?? '');
        foreach ($db['deposits'] as &$d) {
            if ($d['id'] === $depId && $d['status'] === 'pending') {
                if ($act === 'confirm') {
                    $d['status'] = 'completed';
                    $d['confirmedAt'] = gmdate('c');
                    foreach ($db['users'] as &$u) {
                        if ($u['id'] === ($d['userId'] ?? '')) {
                            $u['balanceEgp'] = round((float)$u['balanceEgp'] + (float)$d['amountLocal'], 2);
                        }
                    }
                } elseif ($act === 'cancel') {
                    $d['status'] = 'cancelled';
                }
            }
        }
        save_db($dbFile, $db);
        echo json_encode(['ok' => true, 'state' => public_state($db)], JSON_UNESCAPED_UNICODE);
        break;

    case 'withdrawal_action':
        $wdId = (string)($body['id'] ?? '');
        $act = (string)($body['action'] ?? '');
        foreach ($db['withdrawals'] as &$w) {
            if (($w['id'] ?? '') === $wdId && ($w['status'] ?? '') === 'awaiting_confirmation') {
                if ($act === 'confirm') {
                    $w['status'] = 'completed';
                    $w['acceptedAt'] = gmdate('c');
                } elseif ($act === 'reject') {
                    $w['status'] = 'rejected';
                }
            }
        }
        save_db($dbFile, $db);
        echo json_encode(['ok' => true, 'state' => public_state($db)], JSON_UNESCAPED_UNICODE);
        break;

    case 'update_config':
        $db['config']['platformName'] = 'Nexus Agency';
        if (!empty($body['usdRate']) && (float)$body['usdRate'] > 0) {
            $db['config']['usdRate'] = (float)$body['usdRate'];
        }
        if (isset($body['defaultDepositNumber'])) {
            $db['config']['defaultDepositNumber'] = trim((string)$body['defaultDepositNumber']);
        }
        if (isset($body['depositMethodNumbers']) && is_array($body['depositMethodNumbers'])) {
            $m = $body['depositMethodNumbers'];
            $db['config']['depositMethodNumbers'] = [
                'vodafone' => trim((string)($m['vodafone'] ?? '')),
                'etisalat' => trim((string)($m['etisalat'] ?? '')),
                'orange' => trim((string)($m['orange'] ?? '')),
                'binance' => trim((string)($m['binance'] ?? '')),
            ];
        }
        if (!empty($body['adminPassword'])) {
            $newPass = trim((string)$body['adminPassword']);
            if (strlen($newPass) < 6) {
                echo json_encode(['ok' => false, 'error' => 'يجب أن لا تقل كلمة مرور الأدمن عن 6 أحرف'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $db['config']['adminPasswordHash'] = password_hash($newPass, PASSWORD_DEFAULT);
        }
        save_db($dbFile, $db);
        echo json_encode(['ok' => true, 'state' => public_state($db)], JSON_UNESCAPED_UNICODE);
        break;

    default:
        echo json_encode(['ok' => true, 'state' => public_state($db)], JSON_UNESCAPED_UNICODE);
        break;
}
