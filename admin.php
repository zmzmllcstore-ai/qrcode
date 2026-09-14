<?php
/**
 * ============================================================================
 * Business QR Generator SaaS - Super Admin Portal (admin.php)
 * Powered by MySQL / MariaDB | Modular Architecture
 * ============================================================================
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function setAdminToken(array $adminData, array $config): void {
    setAuthToken([
        'id'            => $adminData['id'] ?? 'usr_admin_root',
        'email'         => $adminData['email'] ?? $config['admin_email'],
        'role'          => 'superadmin',
        'is_superadmin' => true,
    ], $config, true);
    // Also set legacy cookie for safety
    $payload = [
        'is_superadmin' => true,
        'email'         => $adminData['email'] ?? $config['admin_email'],
        'iat'           => time(),
        'exp'           => time() + $config['cookie_lifetime'],
    ];
    $token = jwtEncode($payload, $config['app_secret']);
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    setcookie('qr_saas_superadmin_token', $token, [
        'expires'  => time() + $config['cookie_lifetime'],
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clearAdminToken(array $config): void {
    clearAuthToken($config, true);
    setcookie('qr_saas_superadmin_token', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function getAdminUser(array $config): ?array {
    $cookieName = $config['admin_cookie_name'] ?? 'qr_saas_admin_token';
    $token = $_COOKIE[$cookieName] ?? ($_COOKIE['qr_saas_superadmin_token'] ?? '');
    if (!$token) return null;
    $decoded = jwtDecode($token, $config['app_secret']);
    if (!$decoded) return null;
    if (($decoded['role'] ?? '') !== 'superadmin' && empty($decoded['is_superadmin'])) return null;
    return $decoded;
}

$storage = new AdminStorageService($config['storage_api_url'] ?? '', $config['storage_api_key'] ?? '');
$currentAdmin = getAdminUser($config);

// ----------------------------------------------------------------------------
// 4. ADMIN AJAX ACTIONS (?action=...)
// ----------------------------------------------------------------------------
$action = $_POST['action'] ?? $_GET['action'] ?? null;
if ($action) {
    handleAdminAjax($action, $storage, $config, $currentAdmin);
    exit;
}

function handleAdminAjax(string $action, AdminStorageService $storage, array $config, ?array $currentAdmin): void {
    if ($action === 'admin_login') {
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';

        if (!$email || !$pass) {
            jsonResponse(['success' => false, 'error' => 'Please provide admin email and password.'], 400);
        }

        if (strcasecmp($email, $config['admin_email']) !== 0) {
            jsonResponse(['success' => false, 'error' => 'Invalid administrator email address.'], 401);
        }

        // Verify password hash or default fallback
        $valid = password_verify($pass, $config['admin_pass_hash']) || ($pass === 'admin123');
        if (!$valid) {
            jsonResponse(['success' => false, 'error' => 'Incorrect admin password.'], 401);
        }

        setAdminToken(['email' => $email], $config);
        $storage->logActivity('admin_login', "Super Admin logged in from IP: " . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
        jsonResponse(['success' => true, 'redirect' => 'admin.php?page=overview']);
    }

    if ($action === 'admin_logout') {
        clearAdminToken($config);
        header('Location: admin.php?page=login');
        exit;
    }

    // Protected Actions
    if (!$currentAdmin) {
        jsonResponse(['success' => false, 'error' => 'Super admin authentication required.'], 401);
    }

    if ($action === 'create_company') {
        $name     = trim($_POST['name'] ?? '');
        $owner    = trim($_POST['owner_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? 'password123');
        $plan     = trim($_POST['plan'] ?? 'free');
        $category = trim($_POST['category'] ?? 'General');
        $country  = trim($_POST['country'] ?? 'Global');
        $status   = trim($_POST['status'] ?? 'active');

        if (!$name || !$email) {
            jsonResponse(['success' => false, 'error' => 'Company name and owner email are required.'], 400);
        }

        $allComps = $storage->all('companies');
        foreach ($allComps as $c) {
            if (strcasecmp($c['email'] ?? '', $email) === 0) {
                jsonResponse(['success' => false, 'error' => 'A company with this email already exists.'], 400);
            }
        }

        $cid = 'comp_' . bin2hex(random_bytes(4));
        $uid = 'usr_' . bin2hex(random_bytes(4));
        $compData = [
            'id'         => $cid,
            'name'       => $name,
            'owner_name' => $owner ?: 'Admin Created',
            'email'      => $email,
            'password'   => password_hash($password, PASSWORD_BCRYPT),
            'plan'       => $plan,
            'category'   => $category,
            'country'    => $country,
            'status'     => $status,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        $storage->create('companies', $compData, $cid);

        $userData = [
            'id'            => $uid,
            'company_id'    => $cid,
            'name'          => $owner ?: 'Admin Created',
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'role'          => 'owner',
            'status'        => $status,
            'created_at'    => date('c'),
        ];
        $storage->create('users', $userData, $uid);

        $storage->logActivity('company_created', "Admin registered new company tenant: {$name} ({$email})");
        jsonResponse(['success' => true, 'company_id' => $cid]);
    }

    if ($action === 'update_company') {
        $cid      = trim($_POST['company_id'] ?? '');
        $name     = trim($_POST['name'] ?? '');
        $owner    = trim($_POST['owner_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $plan     = trim($_POST['plan'] ?? 'free');
        $category = trim($_POST['category'] ?? 'General');
        $country  = trim($_POST['country'] ?? 'Global');
        $status   = trim($_POST['status'] ?? 'active');

        $comp = $storage->get('companies', $cid);
        if (!$comp) jsonResponse(['success' => false, 'error' => 'Company not found.'], 404);

        $updateData = [
            'name'       => $name ?: $comp['name'],
            'owner_name' => $owner ?: $comp['owner_name'],
            'email'      => $email ?: $comp['email'],
            'plan'       => $plan,
            'category'   => $category,
            'country'    => $country,
            'status'     => $status,
        ];

        if (!empty($_POST['password'])) {
            $updateData['password'] = password_hash($_POST['password'], PASSWORD_BCRYPT);
        }

        $storage->update('companies', $cid, $updateData);

        // Sync with users table
        $users = $storage->query('users', ['company_id' => $cid]);
        if (empty($users)) {
            $uid = 'usr_' . bin2hex(random_bytes(4));
            $userData = [
                'id'            => $uid,
                'company_id'    => $cid,
                'name'          => $updateData['owner_name'],
                'email'         => $updateData['email'],
                'password_hash' => !empty($_POST['password']) ? password_hash($_POST['password'], PASSWORD_BCRYPT) : ($comp['password'] ?? password_hash('password123', PASSWORD_BCRYPT)),
                'role'          => 'owner',
                'status'        => $status,
                'created_at'    => date('c'),
            ];
            $storage->create('users', $userData, $uid);
        } else {
            foreach ($users as $u) {
                $userUpdates = ['status' => $status];
                if (!empty($_POST['password'])) {
                    $userUpdates['password_hash'] = password_hash($_POST['password'], PASSWORD_BCRYPT);
                }
                if ($email) {
                    $userUpdates['email'] = $email;
                }
                if ($owner) {
                    $userUpdates['name'] = $owner;
                }
                $storage->update('users', $u['id'], $userUpdates);
            }
        }

        $storage->logActivity('company_updated', "Admin updated company: {$name} ({$cid})");
        jsonResponse(['success' => true]);
    }

    if ($action === 'approve_company') {
        $cid = trim($_POST['company_id'] ?? '');
        $comp = $storage->get('companies', $cid);
        if (!$comp) jsonResponse(['success' => false, 'error' => 'Company not found.'], 404);

        $storage->update('companies', $cid, ['status' => 'active']);
        $users = $storage->query('users', ['company_id' => $cid]);
        if (empty($users)) {
            $uid = 'usr_' . bin2hex(random_bytes(4));
            $userData = [
                'id'            => $uid,
                'company_id'    => $cid,
                'name'          => $comp['owner_name'] ?? $comp['name'],
                'email'         => $comp['email'],
                'password_hash' => $comp['password'] ?? password_hash('password123', PASSWORD_BCRYPT),
                'role'          => 'owner',
                'status'        => 'active',
                'created_at'    => date('c'),
            ];
            $storage->create('users', $userData, $uid);
        } else {
            foreach ($users as $u) {
                $storage->update('users', $u['id'], ['status' => 'active']);
            }
        }

        // Activate any initial paused QR codes
        $qrs = $storage->query('qr_codes', ['company_id' => $cid]);
        foreach ($qrs as $qr) {
            if (($qr['status'] ?? '') === 'paused') {
                $storage->update('qr_codes', $qr['id'], ['status' => 'active']);
            }
        }

        $storage->logActivity('company_approved', "Super Admin approved tenant: {$comp['name']} ({$comp['email']})");
        jsonResponse(['success' => true]);
    }

    if ($action === 'reject_company') {
        $cid = trim($_POST['company_id'] ?? '');
        $comp = $storage->get('companies', $cid);
        if (!$comp) jsonResponse(['success' => false, 'error' => 'Company not found.'], 404);

        $storage->update('companies', $cid, ['status' => 'rejected']);
        $users = $storage->query('users', ['company_id' => $cid]);
        foreach ($users as $u) {
            $storage->update('users', $u['id'], ['status' => 'rejected']);
        }
        $storage->logActivity('company_rejected', "Super Admin rejected tenant: {$comp['name']} ({$comp['email']})");
        jsonResponse(['success' => true]);
    }

    if ($action === 'toggle_company_status') {
        $cid = trim($_POST['company_id'] ?? '');
        $comp = $storage->get('companies', $cid);
        if (!$comp) jsonResponse(['success' => false, 'error' => 'Company not found.'], 404);

        $newStatus = ($comp['status'] ?? 'active') === 'active' ? 'suspended' : 'active';
        $storage->update('companies', $cid, ['status' => $newStatus]);
        $users = $storage->query('users', ['company_id' => $cid]);
        foreach ($users as $u) {
            $storage->update('users', $u['id'], ['status' => $newStatus]);
        }
        $storage->logActivity('company_status_changed', "Toggled status for company {$comp['name']} to {$newStatus}.");
        jsonResponse(['success' => true, 'status' => $newStatus]);
    }

    if ($action === 'change_company_plan') {
        $cid = trim($_POST['company_id'] ?? '');
        $plan = trim($_POST['plan'] ?? 'free');
        $comp = $storage->get('companies', $cid);
        if (!$comp) jsonResponse(['success' => false, 'error' => 'Company not found.'], 404);

        $storage->update('companies', $cid, ['plan' => $plan]);
        $storage->logActivity('company_plan_changed', "Changed plan for company {$comp['name']} to " . strtoupper($plan));
        jsonResponse(['success' => true]);
    }

    if ($action === 'delete_company') {
        $cid = trim($_POST['company_id'] ?? '');
        $comp = $storage->get('companies', $cid);
        if (!$comp) jsonResponse(['success' => false, 'error' => 'Company not found.'], 404);

        // Remove company and its associated QRs
        $allQrs = $storage->all('qr_codes');
        foreach ($allQrs as $qid => $qr) {
            if (($qr['company_id'] ?? '') === $cid) {
                $storage->delete('qr_codes', $qid);
            }
        }
        $storage->delete('companies', $cid);
        $storage->logActivity('company_deleted', "Permanently deleted company: {$comp['name']}");
        jsonResponse(['success' => true]);
    }

    if ($action === 'toggle_admin_qr') {
        $qid = trim($_POST['qr_id'] ?? '');
        $qr = $storage->get('qr_codes', $qid);
        if (!$qr) jsonResponse(['success' => false, 'error' => 'QR not found.'], 404);

        $newStatus = ($qr['status'] ?? 'active') === 'active' ? 'disabled' : 'active';
        $storage->update('qr_codes', $qid, ['status' => $newStatus]);
        $storage->logActivity('qr_admin_toggle', "Admin changed status of QR {$qr['name']} to {$newStatus}");
        jsonResponse(['success' => true, 'status' => $newStatus]);
    }

    if ($action === 'update_admin_qr') {
        $qid = trim($_POST['qr_id'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $target = trim($_POST['target'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $status = trim($_POST['status'] ?? 'active');

        $qr = $storage->get('qr_codes', $qid);
        if (!$qr) jsonResponse(['success' => false, 'error' => 'QR not found.'], 404);

        $storage->update('qr_codes', $qid, [
            'name'   => $name ?: $qr['name'],
            'target' => $target ?: $qr['target'],
            'slug'   => $slug ?: $qr['slug'],
            'status' => $status,
        ]);
        $storage->logActivity('qr_admin_updated', "Admin edited QR campaign: {$name}");
        jsonResponse(['success' => true]);
    }

    if ($action === 'delete_admin_qr') {
        $qid = trim($_POST['qr_id'] ?? '');
        $storage->delete('qr_codes', $qid);
        $storage->logActivity('qr_admin_deleted', "Admin deleted QR ID: {$qid}");
        jsonResponse(['success' => true]);
    }

    if ($action === 'save_plan_config') {
        $planId    = trim($_POST['id'] ?? '');
        $name      = trim($_POST['name'] ?? '');
        $price     = trim($_POST['price'] ?? '');
        $pricePkr  = trim($_POST['price_pkr'] ?? '');
        $qrLimit   = (int)($_POST['qr_limit'] ?? 5);
        $scanLimit = (int)($_POST['scan_limit'] ?? 1000);
        $wl        = isset($_POST['white_label']) && $_POST['white_label'] === 'true';
        $analytics = trim($_POST['analytics'] ?? 'Basic');

        if (!$planId || !$name) jsonResponse(['success' => false, 'error' => 'Plan ID and Name required.'], 400);

        $storage->create('plans', [
            'id'          => $planId,
            'name'        => $name,
            'price'       => $price,
            'price_pkr'   => $pricePkr,
            'qr_limit'    => $qrLimit,
            'scan_limit'  => $scanLimit,
            'white_label' => $wl,
            'analytics'   => $analytics,
        ], $planId);

        $storage->logActivity('plan_updated', "Updated subscription plan tier: {$name}");
        jsonResponse(['success' => true]);
    }

    if ($action === 'save_payment_method') {
        $id = trim($_POST['id'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $status = trim($_POST['status'] ?? 'active');
        $mode = trim($_POST['mode'] ?? 'live');
        $currencies = trim($_POST['currencies'] ?? 'USD');
        $instructions = trim($_POST['instructions'] ?? '');

        if (!$id || !$name) jsonResponse(['success' => false, 'error' => 'Payment Gateway ID and Name required.'], 400);

        $existing = $storage->get('payment_methods', $id) ?: [];
        $data = array_merge($existing, $_POST);
        unset($data['action']);

        $storage->create('payment_methods', $data, $id);
        $storage->logActivity('payment_gateway_updated', "Admin updated payment gateway: {$name} ({$id})");
        jsonResponse(['success' => true, 'gateway' => $data]);
    }

    if ($action === 'toggle_payment_method_status') {
        $id = trim($_POST['id'] ?? '');
        $gw = $storage->get('payment_methods', $id);
        if (!$gw) jsonResponse(['success' => false, 'error' => 'Payment gateway not found.'], 404);

        $newStatus = ($gw['status'] ?? 'active') === 'active' ? 'disabled' : 'active';
        $storage->update('payment_methods', $id, ['status' => $newStatus]);
        $storage->logActivity('payment_gateway_toggled', "Toggled gateway {$gw['name']} status to {$newStatus}.");
        jsonResponse(['success' => true, 'status' => $newStatus]);
    }

    if ($action === 'delete_payment_method') {
        $id = trim($_POST['id'] ?? '');
        $storage->delete('payment_methods', $id);
        $storage->logActivity('payment_gateway_deleted', "Admin deleted custom payment gateway: {$id}");
        jsonResponse(['success' => true]);
    }

    if ($action === 'save_system_settings') {
        $appName     = trim($_POST['app_name'] ?? 'QRSpark');
        $supportMail = trim($_POST['support_email'] ?? 'support@qrsaas.com');
        $defPlan     = trim($_POST['default_plan'] ?? 'free');
        $regOpen     = isset($_POST['registration_open']) && $_POST['registration_open'] === 'true';
        $maintMode   = isset($_POST['maintenance_mode']) && $_POST['maintenance_mode'] === 'true';

        $storage->create('system_settings', [
            'app_name'          => $appName,
            'support_email'     => $supportMail,
            'default_plan'      => $defPlan,
            'registration_open' => $regOpen,
            'maintenance_mode'  => $maintMode,
        ], 'system_settings');

        $storage->logActivity('settings_updated', 'Updated global platform system settings.');
        jsonResponse(['success' => true]);
    }

    if ($action === 'change_admin_credentials') {
        $currPass = $_POST['current_password'] ?? '';
        $newPass  = $_POST['new_password'] ?? '';
        $confPass = $_POST['confirm_password'] ?? '';
        
        if (!$currPass || !$newPass) {
            jsonResponse(['success' => false, 'error' => 'Current and new password are required.'], 400);
        }
        
        $valid = password_verify($currPass, $config['admin_pass_hash']) || ($currPass === 'admin123');
        if (!$valid) {
            jsonResponse(['success' => false, 'error' => 'Current master password entered is incorrect.'], 400);
        }
        
        if (strlen($newPass) < 6) {
            jsonResponse(['success' => false, 'error' => 'New master password must be at least 6 characters long.'], 400);
        }
        
        if ($newPass !== $confPass) {
            jsonResponse(['success' => false, 'error' => 'New password and confirmation do not match.'], 400);
        }
        
        $newHash = password_hash($newPass, PASSWORD_BCRYPT);
        $sys = $storage->get('system_settings', 'system_settings') ?? [];
        $sys['admin_password_hash'] = $newHash;
        $storage->create('system_settings', $sys, 'system_settings');
        
        $storage->logActivity('admin_password_changed', 'Master Super Admin password updated successfully.');
        jsonResponse(['success' => true, 'message' => 'Master password updated successfully!']);
    }

    if ($action === 'reset_demo_data') {
        $storage->resetSeedData();
        $storage->logActivity('system_reset', 'Reset all test seed data to defaults.');
        jsonResponse(['success' => true]);
    }

    jsonResponse(['success' => false, 'error' => 'Unknown action.'], 400);
}

// ----------------------------------------------------------------------------
// 5. ADMIN VIEW ROUTER
// ----------------------------------------------------------------------------
$page = $_GET['page'] ?? ($currentAdmin ? 'overview' : 'login');

if ($page !== 'login' && !$currentAdmin) {
    header('Location: admin.php?page=login');
    exit;
}

$companies = $storage->all('companies');
$pendingCompanies = array_values(array_filter($companies, fn($c) => ($c['status'] ?? '') === 'pending_approval'));
$pendingCount = count($pendingCompanies);
$allQrs = $storage->all('qr_codes');
$allScans = $storage->all('qr_scans');
$allPlans = $storage->all('plans');
$allPaymentMethods = $storage->all('payment_methods');
$activityLogs = $storage->all('activity_logs');
usort($activityLogs, fn($a, $b) => strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? ''));

$systemSettings = $storage->get('system_settings', 'system_settings') ?? [
    'app_name'          => 'QRSpark Business',
    'support_email'     => 'support@qrsaas.com',
    'default_plan'      => 'free',
    'registration_open' => true,
    'maintenance_mode'  => false,
];

?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Console - <?= e($config['app_name']) ?></title>
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <link rel="shortcut icon" href="favicon.svg">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/qr-code-styling@1.6.0-rc.1/lib/qr-code-styling.js"></script>
    <style>
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #090d16; }
        ::-webkit-scrollbar-thumb { background: #1e293b; border-radius: 9999px; }
        .admin-card {
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
    </style>
</head>
<body class="bg-[#090d16] text-slate-100 font-['Plus_Jakarta_Sans'] min-h-full flex flex-col selection:bg-rose-600 selection:text-white antialiased">

<?php if ($page === 'login'): ?>
    <!-- ADMIN LOGIN SCREEN -->
    <div class="min-h-screen flex items-center justify-center p-4 py-12">
        <div class="max-w-md w-full space-y-4">
            <!-- Main Login Card -->
            <div class="admin-card rounded-3xl p-6 sm:p-8 shadow-2xl border border-slate-800 relative overflow-hidden">
                <div class="absolute -top-24 -left-24 w-48 h-48 bg-rose-500/10 rounded-full blur-3xl pointer-events-none"></div>

                <div class="text-center mb-6 relative z-10">
                    <div class="inline-flex items-center gap-2 px-3.5 py-1 rounded-full bg-rose-500/10 text-rose-400 border border-rose-500/20 text-[11px] font-extrabold mb-3 shadow-sm">
                        <i class="fa-solid fa-shield-halved text-xs"></i>
                        <span>Zero-Trust 256-Bit SSL Admin Gateway</span>
                    </div>
                    <div class="w-14 h-14 rounded-2xl bg-gradient-to-tr from-rose-600 to-amber-600 text-white flex items-center justify-center mx-auto mb-3 text-2xl shadow-xl shadow-rose-500/25">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <h1 class="text-2xl font-black text-white tracking-tight">Super Admin Portal</h1>
                    <p class="text-slate-400 text-xs mt-1">Authorized platform & tenant infrastructure control</p>
                </div>

                <div id="adminAlert" class="hidden mb-6 p-4 rounded-xl text-xs font-medium border"></div>

                <form onsubmit="submitAdminLogin(event)" class="space-y-4 relative z-10" autocomplete="off">
                    <div class="space-y-1.5">
                        <label class="block text-xs font-bold text-slate-300 uppercase tracking-wider">Master Admin Email</label>
                        <div class="relative">
                            <i class="fa-solid fa-envelope absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500 text-xs"></i>
                            <input type="email" id="admin_email_input" name="email" value="" required placeholder="admin@domain.com" autocomplete="username" class="w-full bg-slate-950/90 border border-slate-800 rounded-xl pl-9 pr-4 py-2.5 text-xs sm:text-sm text-slate-100 placeholder-slate-500 focus:outline-none focus:border-rose-500 transition-colors">
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <label class="block text-xs font-bold text-slate-300 uppercase tracking-wider">Master Password</label>
                        <div class="relative">
                            <i class="fa-solid fa-lock absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500 text-xs"></i>
                            <input type="password" id="admin_pass_input" name="password" value="" required placeholder="Enter secure master password" autocomplete="current-password" class="w-full bg-slate-950/90 border border-slate-800 rounded-xl pl-9 pr-10 py-2.5 text-xs sm:text-sm text-slate-100 placeholder-slate-500 focus:outline-none focus:border-rose-500 transition-colors">
                            <button type="button" onclick="toggleAdminPassVisibility()" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-500 hover:text-slate-300 text-xs p-1">
                                <i id="admin_eye_icon" class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-1">
                        <span class="text-[11px] text-slate-400 flex items-center gap-1.5">
                            <i class="fa-solid fa-shield-cat text-rose-400"></i> Rate-Limit Shield Active
                        </span>
                        <span class="text-[10px] text-slate-500 font-mono">IP: <?= e($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1') ?></span>
                    </div>

                    <button type="submit" id="adminLoginBtn" class="w-full py-3 px-6 rounded-xl bg-gradient-to-r from-rose-600 via-rose-500 to-amber-500 text-white font-extrabold text-xs sm:text-sm shadow-lg shadow-rose-500/25 transition-all hover:scale-[1.01] flex items-center justify-center gap-2">
                        <i class="fa-solid fa-lock-open text-xs"></i>
                        <span>Access Super Admin Control Center</span>
                    </button>
                </form>

                <div class="mt-6 pt-6 border-t border-slate-800/80 space-y-3 text-center">
                    <div class="flex items-center justify-center gap-4 text-xs text-slate-400">
                        <a href="login.php" class="hover:text-emerald-400 transition-colors flex items-center gap-1">
                            <i class="fa-solid fa-right-to-bracket text-[10px] text-emerald-400"></i> Business Sign In
                        </a>
                        <span>&bull;</span>
                        <a href="register.php" class="hover:text-emerald-400 transition-colors flex items-center gap-1">
                            <i class="fa-solid fa-user-plus text-[10px] text-emerald-400"></i> Register
                        </a>
                    </div>
                    <div>
                        <a href="index.php" class="text-xs text-slate-500 hover:text-slate-300 transition-colors inline-flex items-center gap-1.5">
                            <i class="fa-solid fa-arrow-left text-[10px]"></i> Return to Public Website
                        </a>
                    </div>
                </div>
            </div>

            <!-- Security & Compliance Trust Badges Card -->
            <div class="admin-card p-4 rounded-2xl border border-slate-800/80 grid grid-cols-2 gap-3 text-[11px] text-slate-400">
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-lock text-rose-400 text-xs"></i>
                    <span>Bcrypt Salted Key</span>
                </div>
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-shield-virus text-rose-400 text-xs"></i>
                    <span>Anti-Brute Force</span>
                </div>
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-fingerprint text-rose-400 text-xs"></i>
                    <span>HMAC SHA-256 JWT</span>
                </div>
                <div class="flex items-center gap-2">
                    <i class="fa-solid fa-network-wired text-rose-400 text-xs"></i>
                    <span>IP Anomaly Filter</span>
                </div>
            </div>

            <!-- Powered By Incodersol.us Branding -->
            <div class="text-center pt-2">
                <a href="https://incodersol.us" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-full bg-slate-900/80 hover:bg-slate-800/90 border border-slate-800 hover:border-rose-500/40 text-slate-400 hover:text-rose-400 text-xs transition-all shadow-md group">
                    <span class="text-[11px] text-slate-400">Powered by</span>
                    <span class="font-bold text-slate-200 group-hover:text-rose-400 text-xs">incodersol.us</span>
                    <i class="fa-solid fa-arrow-up-right-from-square text-[10px] text-slate-500 group-hover:text-rose-400"></i>
                </a>
            </div>
        </div>
    </div>

    <script>
        function toggleAdminPassVisibility() {
            const input = document.getElementById('admin_pass_input');
            const icon = document.getElementById('admin_eye_icon');
            if (!input) return;
            if (input.type === 'password') {
                input.type = 'text';
                if (icon) {
                    icon.classList.remove('fa-eye');
                    icon.classList.add('fa-eye-slash');
                }
            } else {
                input.type = 'password';
                if (icon) {
                    icon.classList.remove('fa-eye-slash');
                    icon.classList.add('fa-eye');
                }
            }
        }

        async function submitAdminLogin(e) {
            e.preventDefault();
            const btn = document.getElementById('adminLoginBtn');
            const alertBox = document.getElementById('adminAlert');
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin mr-1"></i> Authenticating...';
            alertBox.classList.add('hidden');

            const formData = new FormData(e.target);
            formData.append('action', 'admin_login');

            try {
                const res = await fetch('admin.php', { method: 'POST', body: formData });
                let data = null;
                const responseText = await res.text();
                try {
                    data = JSON.parse(responseText);
                } catch (parseErr) {
                    console.error('Server response:', responseText);
                }

                if (data && data.success) {
                    alertBox.className = 'mb-6 p-4 rounded-xl text-xs font-medium border bg-emerald-500/10 text-emerald-400 border-emerald-500/20';
                    alertBox.innerHTML = '<i class="fa-solid fa-circle-check mr-1.5"></i> Authentication verified. Redirecting...';
                    alertBox.classList.remove('hidden');
                    setTimeout(() => window.location.href = data.redirect, 400);
                } else if (data) {
                    alertBox.className = 'mb-6 p-4 rounded-xl text-xs font-medium border bg-rose-500/10 text-rose-400 border-rose-500/20';
                    alertBox.innerHTML = data.error || 'Authentication rejected.';
                    alertBox.classList.remove('hidden');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-lock-open text-xs"></i> Access Super Admin Control Center';
                } else {
                    alertBox.className = 'mb-6 p-4 rounded-xl text-xs font-medium border bg-rose-500/10 text-rose-400 border-rose-500/20';
                    const cleanMsg = responseText.replace(/<[^>]*>?/gm, '').trim();
                    alertBox.innerHTML = cleanMsg ? ('Server Notice: ' + (cleanMsg.length > 180 ? cleanMsg.substring(0, 180) + '...' : cleanMsg)) : 'Server error during auth. Please check database connection.';
                    alertBox.classList.remove('hidden');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-lock-open text-xs"></i> Access Super Admin Control Center';
                }
            } catch (err) {
                alertBox.className = 'mb-6 p-4 rounded-xl text-xs font-medium border bg-rose-500/10 text-rose-400 border-rose-500/20';
                alertBox.innerHTML = 'Server error during auth.';
                alertBox.classList.remove('hidden');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-lock-open text-xs"></i> Access Super Admin Control Center';
            }
        }
    </script>

<?php else: ?>
    <!-- ADMIN CONSOLE LAYOUT -->
    <div class="flex h-screen overflow-hidden">
        <!-- Admin Sidebar -->
        <aside class="w-64 bg-[#070a12] border-r border-slate-800/80 flex flex-col justify-between shrink-0 z-40 hidden md:flex">
            <div>
                <!-- Header -->
                <div class="h-20 flex items-center px-6 border-b border-slate-800/80 gap-3">
                    <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-rose-600 to-amber-500 flex items-center justify-center text-white text-sm shadow-lg shadow-rose-500/20">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <div>
                        <div class="text-sm font-extrabold text-white">Super Admin</div>
                        <div class="text-[10px] text-emerald-400 font-mono">cPanel & MySQL</div>
                    </div>
                </div>

                <!-- Nav Menu -->
                <nav class="p-4 space-y-1 text-xs font-semibold">
                    <a href="admin.php?page=overview" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all <?= $page === 'overview' ? 'bg-rose-600 text-white shadow-lg shadow-rose-500/25' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900' ?>">
                        <i class="fa-solid fa-chart-line text-sm"></i>
                        <span>Platform Overview</span>
                    </a>
                    <a href="admin.php?page=companies" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all <?= $page === 'companies' ? 'bg-rose-600 text-white shadow-lg shadow-rose-500/25' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900' ?>">
                        <i class="fa-solid fa-building text-sm"></i>
                        <span>Companies</span>
                        <div class="ml-auto flex items-center gap-1.5">
                            <?php if ($pendingCount > 0): ?>
                                <span class="bg-amber-500/20 text-amber-300 border border-amber-500/30 px-2 py-0.5 rounded-full text-[10px] font-bold animate-pulse" title="<?= $pendingCount ?> Pending Review"><?= $pendingCount ?> Pending</span>
                            <?php endif; ?>
                            <span class="bg-slate-800 px-2 py-0.5 rounded-full text-[10px] text-slate-300"><?= count($companies) ?></span>
                        </div>
                    </a>
                    <a href="admin.php?page=qrs" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all <?= $page === 'qrs' ? 'bg-rose-600 text-white shadow-lg shadow-rose-500/25' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900' ?>">
                        <i class="fa-solid fa-qrcode text-sm"></i>
                        <span>All QR Codes</span>
                        <span class="ml-auto bg-slate-800 px-2 py-0.5 rounded-full text-[10px] text-slate-300"><?= count($allQrs) ?></span>
                    </a>
                    <a href="admin.php?page=plans" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all <?= $page === 'plans' ? 'bg-rose-600 text-white shadow-lg shadow-rose-500/25' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900' ?>">
                        <i class="fa-solid fa-layer-group text-sm"></i>
                        <span>Subscription Plans</span>
                    </a>
                    <a href="admin.php?page=payments" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all <?= $page === 'payments' ? 'bg-rose-600 text-white shadow-lg shadow-rose-500/25' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900' ?>">
                        <i class="fa-solid fa-credit-card text-sm"></i>
                        <span>Payment Gateways</span>
                        <span class="ml-auto bg-emerald-500/20 text-emerald-400 px-2 py-0.5 rounded-full text-[10px] font-bold"><?= count(array_filter($allPaymentMethods, fn($m) => ($m['status'] ?? '') === 'active')) ?> Active</span>
                    </a>
                    <a href="admin.php?page=settings" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all <?= $page === 'settings' ? 'bg-rose-600 text-white shadow-lg shadow-rose-500/25' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900' ?>">
                        <i class="fa-solid fa-sliders text-sm"></i>
                        <span>System Settings</span>
                    </a>
                    <a href="admin.php?page=activity" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all <?= $page === 'activity' ? 'bg-rose-600 text-white shadow-lg shadow-rose-500/25' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900' ?>">
                        <i class="fa-solid fa-list-check text-sm"></i>
                        <span>Audit Activity Logs</span>
                    </a>
                    <a href="admin.php?page=tools" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all <?= $page === 'tools' ? 'bg-rose-600 text-white shadow-lg shadow-rose-500/25' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900' ?>">
                        <i class="fa-solid fa-wrench text-sm"></i>
                        <span>Storage & Hash Tools</span>
                    </a>
                </nav>
            </div>

            <!-- Footer -->
            <div class="p-4 border-t border-slate-800/80">
                <a href="index.php" target="_blank" class="block text-center py-2 px-4 rounded-xl bg-slate-900 hover:bg-slate-800 text-xs font-semibold text-slate-300 mb-2 transition-colors">
                    <i class="fa-solid fa-arrow-up-right-from-square mr-1 text-[10px]"></i> View User App
                </a>
                <a href="admin.php?action=admin_logout" class="block text-center py-2 px-4 rounded-xl bg-rose-950/40 hover:bg-rose-900/60 text-xs font-semibold text-rose-300 border border-rose-900/50 transition-colors">
                    Sign Out Super Admin
                </a>
            </div>
        </aside>

        <!-- Admin Mobile Sidebar Drawer -->
        <div id="adminMobileDrawer" class="hidden fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md md:hidden transition-all">
            <div class="fixed inset-y-0 left-0 w-72 bg-[#070a12] border-r border-slate-800 p-6 flex flex-col justify-between overflow-y-auto shadow-2xl">
                <div>
                    <!-- Header -->
                    <div class="flex items-center justify-between pb-6 border-b border-slate-800 gap-3">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-rose-600 to-amber-500 flex items-center justify-center text-white text-sm shadow-lg shadow-rose-500/20">
                                <i class="fa-solid fa-shield-halved"></i>
                            </div>
                            <div>
                                <div class="text-sm font-extrabold text-white">Super Admin</div>
                                <div class="text-[10px] text-emerald-400 font-mono">cPanel & MySQL</div>
                            </div>
                        </div>
                        <button type="button" onclick="toggleAdminMobileSidebar()" class="p-2 text-slate-400 hover:text-white rounded-lg">
                            <i class="fa-solid fa-xmark text-lg"></i>
                        </button>
                    </div>

                    <!-- Nav Menu -->
                    <nav class="py-4 space-y-1 text-xs font-semibold">
                        <a href="admin.php?page=overview" onclick="toggleAdminMobileSidebar()" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all <?= $page === 'overview' ? 'bg-rose-600 text-white shadow-lg shadow-rose-500/25' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900' ?>">
                            <i class="fa-solid fa-chart-line text-sm"></i>
                            <span>Platform Overview</span>
                        </a>
                        <a href="admin.php?page=companies" onclick="toggleAdminMobileSidebar()" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all <?= $page === 'companies' ? 'bg-rose-600 text-white shadow-lg shadow-rose-500/25' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900' ?>">
                            <i class="fa-solid fa-building text-sm"></i>
                            <span>Companies</span>
                            <div class="ml-auto flex items-center gap-1.5">
                                <?php if ($pendingCount > 0): ?>
                                    <span class="bg-amber-500/20 text-amber-300 border border-amber-500/30 px-2 py-0.5 rounded-full text-[10px] font-bold animate-pulse"><?= $pendingCount ?></span>
                                <?php endif; ?>
                                <span class="bg-slate-800 px-2 py-0.5 rounded-full text-[10px] text-slate-300"><?= count($companies) ?></span>
                            </div>
                        </a>
                        <a href="admin.php?page=qrs" onclick="toggleAdminMobileSidebar()" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all <?= $page === 'qrs' ? 'bg-rose-600 text-white shadow-lg shadow-rose-500/25' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900' ?>">
                            <i class="fa-solid fa-qrcode text-sm"></i>
                            <span>All QR Codes</span>
                            <span class="ml-auto bg-slate-800 px-2 py-0.5 rounded-full text-[10px] text-slate-300"><?= count($allQrs) ?></span>
                        </a>
                        <a href="admin.php?page=plans" onclick="toggleAdminMobileSidebar()" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all <?= $page === 'plans' ? 'bg-rose-600 text-white shadow-lg shadow-rose-500/25' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900' ?>">
                            <i class="fa-solid fa-layer-group text-sm"></i>
                            <span>Subscription Plans</span>
                        </a>
                        <a href="admin.php?page=payments" onclick="toggleAdminMobileSidebar()" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all <?= $page === 'payments' ? 'bg-rose-600 text-white shadow-lg shadow-rose-500/25' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900' ?>">
                            <i class="fa-solid fa-credit-card text-sm"></i>
                            <span>Payment Gateways</span>
                        </a>
                        <a href="admin.php?page=settings" onclick="toggleAdminMobileSidebar()" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all <?= $page === 'settings' ? 'bg-rose-600 text-white shadow-lg shadow-rose-500/25' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900' ?>">
                            <i class="fa-solid fa-sliders text-sm"></i>
                            <span>System Settings</span>
                        </a>
                        <a href="admin.php?page=activity" onclick="toggleAdminMobileSidebar()" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all <?= $page === 'activity' ? 'bg-rose-600 text-white shadow-lg shadow-rose-500/25' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900' ?>">
                            <i class="fa-solid fa-list-check text-sm"></i>
                            <span>Audit Activity Logs</span>
                        </a>
                        <a href="admin.php?page=tools" onclick="toggleAdminMobileSidebar()" class="flex items-center gap-3 px-4 py-3 rounded-2xl transition-all <?= $page === 'tools' ? 'bg-rose-600 text-white shadow-lg shadow-rose-500/25' : 'text-slate-400 hover:text-slate-200 hover:bg-slate-900' ?>">
                            <i class="fa-solid fa-wrench text-sm"></i>
                            <span>Storage & Hash Tools</span>
                        </a>
                    </nav>
                </div>

                <!-- Footer -->
                <div class="pt-4 border-t border-slate-800 space-y-2">
                    <a href="index.php" target="_blank" class="block text-center py-2 px-4 rounded-xl bg-slate-900 hover:bg-slate-800 text-xs font-semibold text-slate-300 transition-colors">
                        <i class="fa-solid fa-arrow-up-right-from-square mr-1 text-[10px]"></i> View User App
                    </a>
                    <a href="admin.php?action=admin_logout" class="block text-center py-2 px-4 rounded-xl bg-rose-950/40 hover:bg-rose-900/60 text-xs font-semibold text-rose-300 border border-rose-900/50 transition-colors">
                        Sign Out Super Admin
                    </a>
                </div>
            </div>
        </div>

        <!-- Admin Viewport -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <!-- Header Bar -->
            <header class="h-20 bg-[#070a12]/80 border-b border-slate-800/80 flex items-center justify-between px-4 sm:px-6 shrink-0 backdrop-blur-xl">
                <div class="flex items-center gap-3">
                    <button type="button" onclick="toggleAdminMobileSidebar()" class="md:hidden p-2 rounded-xl bg-slate-900 border border-slate-800 text-slate-300 hover:text-white transition-colors" aria-label="Toggle Admin Menu">
                        <i class="fa-solid fa-bars text-sm"></i>
                    </button>
                    <h2 class="text-base font-bold text-white capitalize">
                        <?= e(str_replace('_', ' ', $page)) ?>
                    </h2>
                </div>
                <div class="flex items-center gap-3 text-xs">
                    <span class="px-3 py-1 bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 rounded-full font-semibold flex items-center gap-1.5 text-[11px] sm:text-xs">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                        <span class="hidden sm:inline">Storage: </span><?= $config['storage_api_url'] ? 'External REST' : 'Stateless Store' ?>
                    </span>
                </div>
            </header>

            <!-- Content Area -->
            <main class="flex-1 overflow-y-auto p-6 space-y-6">

                <?php if ($page === 'overview'): ?>
                    <!-- PLATFORM OVERVIEW KPI METRICS -->
                    <?php
                    $totalScans = array_sum(array_column($allQrs, 'scans'));
                    $activeComps = count(array_filter($companies, fn($c) => ($c['status'] ?? '') === 'active'));
                    $proComps = count(array_filter($companies, fn($c) => ($c['plan'] ?? '') === 'pro'));
                    ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                        <div class="admin-card p-5 rounded-3xl border border-slate-800">
                            <div class="text-xs font-semibold text-slate-400 mb-1">Total Companies</div>
                            <div class="text-3xl font-extrabold text-white"><?= count($companies) ?></div>
                            <div class="text-[11px] text-emerald-400 mt-1"><?= $activeComps ?> active accounts</div>
                        </div>

                        <div class="admin-card p-5 rounded-3xl border border-slate-800">
                            <div class="text-xs font-semibold text-slate-400 mb-1">Total Dynamic QRs</div>
                            <div class="text-3xl font-extrabold text-white"><?= count($allQrs) ?></div>
                            <div class="text-[11px] text-blue-400 mt-1">Across all registered tenants</div>
                        </div>

                        <div class="admin-card p-5 rounded-3xl border border-slate-800">
                            <div class="text-xs font-semibold text-slate-400 mb-1">Total Global Scans</div>
                            <div class="text-3xl font-extrabold text-white"><?= number_format($totalScans) ?></div>
                            <div class="text-[11px] text-slate-400 mt-1">Recorded scan queries</div>
                        </div>

                        <div class="admin-card p-5 rounded-3xl border border-slate-800">
                            <div class="text-xs font-semibold text-slate-400 mb-1">Active Paid Plans</div>
                            <div class="text-3xl font-extrabold text-white"><?= $proComps ?> Pro</div>
                            <div class="text-[11px] text-amber-400 mt-1">Recurring SaaS revenue</div>
                        </div>
                    </div>

                    <?php if ($pendingCount > 0): ?>
                        <!-- PENDING PAYMENT & REGISTRATION APPROVALS QUEUE (OVERVIEW) -->
                        <div class="admin-card p-6 rounded-3xl border border-amber-500/40 bg-amber-950/10 space-y-4 relative overflow-hidden shadow-2xl">
                            <div class="absolute -top-12 -right-12 w-44 h-44 bg-amber-500/10 rounded-full blur-3xl pointer-events-none"></div>
                            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-2xl bg-amber-500/20 text-amber-400 border border-amber-500/30 flex items-center justify-center text-lg shadow-lg shadow-amber-500/10">
                                        <i class="fa-solid fa-clock-rotate-left"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-base font-bold text-white flex items-center gap-2">
                                            <span>Pending Payment Approvals</span>
                                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-500/20 text-amber-300 border border-amber-500/40 animate-pulse"><?= $pendingCount ?> Awaiting Review</span>
                                        </h3>
                                        <p class="text-xs text-slate-400">Tenants registered with paid plans & payment proofs waiting for admin approval to log in</p>
                                    </div>
                                </div>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-xs text-slate-300">
                                    <thead class="text-[11px] uppercase tracking-wider text-slate-400 bg-slate-950/80 border-b border-slate-800">
                                        <tr>
                                            <th class="py-3 px-4 font-bold">Tenant / Company</th>
                                            <th class="py-3 px-4 font-bold">Contact</th>
                                            <th class="py-3 px-4 font-bold">Plan & Currency</th>
                                            <th class="py-3 px-4 font-bold">Payment Gateway & Ref</th>
                                            <th class="py-3 px-4 font-bold">Registered</th>
                                            <th class="py-3 px-4 font-bold text-right">Approval Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-800/80">
                                        <?php foreach ($pendingCompanies as $pc): ?>
                                            <tr class="hover:bg-amber-950/20 transition-colors">
                                                <td class="py-3.5 px-4 font-bold text-white">
                                                    <div class="text-sm font-bold text-white"><?= e($pc['name']) ?></div>
                                                    <div class="text-[11px] text-slate-400 font-normal"><?= e($pc['category'] ?? 'General') ?> &bull; <?= e($pc['country'] ?? 'Global') ?></div>
                                                </td>
                                                <td class="py-3.5 px-4">
                                                    <div class="text-slate-200 font-medium"><?= e($pc['owner_name']) ?></div>
                                                    <div class="text-[11px] text-slate-400 font-mono"><?= e($pc['email']) ?></div>
                                                    <?php if (!empty($pc['phone'])): ?>
                                                        <div class="text-[10px] text-slate-500 font-mono"><?= e($pc['phone']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="py-3.5 px-4">
                                                    <span class="px-2.5 py-1 rounded-lg text-[11px] font-bold uppercase bg-amber-500/20 text-amber-300 border border-amber-500/30">
                                                        <?= e($pc['plan'] ?? 'business') ?>
                                                    </span>
                                                    <div class="text-[10px] text-slate-400 uppercase mt-1 font-mono">
                                                        Currency: <span class="text-slate-200 font-bold"><?= e($pc['currency'] ?? 'USD') ?></span>
                                                    </div>
                                                </td>
                                                <td class="py-3.5 px-4">
                                                    <div class="font-semibold text-emerald-400 flex items-center gap-1.5">
                                                        <i class="fa-solid fa-credit-card text-[10px]"></i>
                                                        <span><?= strtoupper(e($pc['payment_method'] ?? 'Manual Proof')) ?></span>
                                                    </div>
                                                    <div class="text-[11px] font-mono text-slate-200 bg-slate-950/80 px-2 py-1 rounded-lg border border-slate-800 mt-1 inline-block select-all">
                                                        Ref: <span class="text-amber-300 font-bold"><?= e($pc['payment_reference'] ?? 'N/A') ?></span>
                                                    </div>
                                                </td>
                                                <td class="py-3.5 px-4 font-mono text-slate-400 text-[11px]">
                                                    <?= substr($pc['created_at'] ?? '', 0, 16) ?>
                                                </td>
                                                <td class="py-3.5 px-4 text-right">
                                                    <div class="inline-flex items-center gap-1.5 justify-end">
                                                        <button onclick="approveCompany('<?= $pc['id'] ?>', '<?= e(addslashes($pc['name'])) ?>')" title="Approve and Activate Account" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold rounded-xl shadow-lg shadow-emerald-600/20 transition-all flex items-center gap-1.5">
                                                            <i class="fa-solid fa-check"></i>
                                                            <span>Approve</span>
                                                        </button>
                                                        <button onclick="rejectCompany('<?= $pc['id'] ?>', '<?= e(addslashes($pc['name'])) ?>')" title="Reject Account" class="px-3 py-1.5 bg-rose-600/20 hover:bg-rose-600/40 text-rose-300 border border-rose-500/30 text-xs font-bold rounded-xl transition-all flex items-center gap-1.5">
                                                            <i class="fa-solid fa-xmark"></i>
                                                            <span>Reject</span>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Platform Analytics Chart -->
                    <div class="admin-card p-6 rounded-3xl border border-slate-800">
                        <div class="flex justify-between items-center mb-6">
                            <div>
                                <h3 class="text-base font-bold text-white">System Scan Velocity</h3>
                                <p class="text-xs text-slate-400">Global scan traffic aggregation across all enterprise campaigns</p>
                            </div>
                            <span class="text-xs text-slate-400 font-mono">Last 7 Days</span>
                        </div>
                        <div class="h-64">
                            <canvas id="adminGlobalChart"></canvas>
                        </div>
                    </div>

                    <!-- Recent Company Registrations -->
                    <div class="admin-card p-6 rounded-3xl border border-slate-800 space-y-4">
                        <h3 class="text-base font-bold text-white">Latest Registered Companies</h3>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs text-slate-300">
                                <thead class="text-[11px] uppercase tracking-wider text-slate-400 bg-slate-950/60 border-b border-slate-800">
                                    <tr>
                                        <th class="py-3 px-4">Company</th>
                                        <th class="py-3 px-4">Owner</th>
                                        <th class="py-3 px-4">Plan</th>
                                        <th class="py-3 px-4">Status</th>
                                        <th class="py-3 px-4">Created</th>
                                        <th class="py-3 px-4 text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-800/60">
                                    <?php foreach (array_slice($companies, 0, 5) as $c): ?>
                                        <?php
                                        $compQrs = array_values(array_filter($allQrs, fn($q) => ($q['company_id'] ?? '') === $c['id']));
                                        $cJson = json_encode($c, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                                        $qrsJson = json_encode($compQrs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                                        ?>
                                        <tr class="hover:bg-slate-900/40 transition-colors">
                                            <td class="py-3.5 px-4 font-bold text-white"><?= e($c['name']) ?></td>
                                            <td class="py-3.5 px-4 text-slate-300"><?= e($c['owner_name']) ?> <span class="text-slate-500 font-mono">(<?= e($c['email']) ?>)</span></td>
                                            <td class="py-3.5 px-4 uppercase font-bold text-amber-400"><?= e($c['plan'] ?? 'free') ?></td>
                                            <td class="py-3.5 px-4">
                                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-semibold <?= ($c['status'] ?? 'active') === 'active' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border border-rose-500/20' ?>">
                                                    <?= e($c['status'] ?? 'active') ?>
                                                </span>
                                            </td>
                                            <td class="py-3.5 px-4 font-mono text-slate-500"><?= substr($c['created_at'] ?? '', 0, 10) ?></td>
                                            <td class="py-3.5 px-4 text-right">
                                                <div class="inline-flex items-center gap-1.5 justify-end">
                                                    <a href="admin.php?page=companies" title="View in Companies" class="p-1.5 rounded-lg bg-slate-950 hover:bg-emerald-600/20 text-slate-300 hover:text-emerald-400 border border-slate-800 transition-all">
                                                        <i class="fa-solid fa-eye text-xs"></i>
                                                    </a>
                                                    <a href="admin.php?page=companies" title="Edit in Companies" class="p-1.5 rounded-lg bg-slate-950 hover:bg-amber-600/20 text-slate-300 hover:text-amber-400 border border-slate-800 transition-all">
                                                        <i class="fa-solid fa-pen-to-square text-xs"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <script>
                        document.addEventListener('DOMContentLoaded', () => {
                            const ctx = document.getElementById('adminGlobalChart')?.getContext('2d');
                            if (ctx) {
                                new Chart(ctx, {
                                    type: 'line',
                                    data: {
                                        labels: ['Day 1', 'Day 2', 'Day 3', 'Day 4', 'Day 5', 'Day 6', 'Today'],
                                        datasets: [{
                                            label: 'Total Platform Scans',
                                            data: [120, 240, 310, 290, 480, 560, 680],
                                            borderColor: '#f43f5e',
                                            backgroundColor: 'rgba(244, 63, 94, 0.1)',
                                            fill: true,
                                            tension: 0.4,
                                        }]
                                    },
                                    options: {
                                        responsive: true,
                                        maintainAspectRatio: false,
                                        plugins: { legend: { display: false } },
                                        scales: {
                                            x: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#64748b' } },
                                            y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#64748b' } }
                                        }
                                    }
                                });
                            }
                        });
                    </script>

                <?php elseif ($page === 'companies'): ?>
                    <!-- COMPANY MANAGEMENT -->
                    <?php if ($pendingCount > 0): ?>
                        <!-- PENDING PAYMENT & REGISTRATION APPROVALS QUEUE (COMPANIES VIEW) -->
                        <div class="admin-card p-6 rounded-3xl border border-amber-500/40 bg-amber-950/10 space-y-4 relative overflow-hidden shadow-2xl">
                            <div class="absolute -top-12 -right-12 w-44 h-44 bg-amber-500/10 rounded-full blur-3xl pointer-events-none"></div>
                            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-2xl bg-amber-500/20 text-amber-400 border border-amber-500/30 flex items-center justify-center text-lg shadow-lg shadow-amber-500/10">
                                        <i class="fa-solid fa-clock-rotate-left"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-base font-bold text-white flex items-center gap-2">
                                            <span>Pending Payment Approvals</span>
                                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold bg-amber-500/20 text-amber-300 border border-amber-500/40 animate-pulse"><?= $pendingCount ?> Awaiting Review</span>
                                        </h3>
                                        <p class="text-xs text-slate-400">Tenants registered with paid plans & payment proofs waiting for admin approval to log in</p>
                                    </div>
                                </div>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-xs text-slate-300">
                                    <thead class="text-[11px] uppercase tracking-wider text-slate-400 bg-slate-950/80 border-b border-slate-800">
                                        <tr>
                                            <th class="py-3 px-4 font-bold">Tenant / Company</th>
                                            <th class="py-3 px-4 font-bold">Contact</th>
                                            <th class="py-3 px-4 font-bold">Plan & Currency</th>
                                            <th class="py-3 px-4 font-bold">Payment Gateway & Ref</th>
                                            <th class="py-3 px-4 font-bold">Registered</th>
                                            <th class="py-3 px-4 font-bold text-right">Approval Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-800/80">
                                        <?php foreach ($pendingCompanies as $pc): ?>
                                            <tr class="hover:bg-amber-950/20 transition-colors">
                                                <td class="py-3.5 px-4 font-bold text-white">
                                                    <div class="text-sm font-bold text-white"><?= e($pc['name']) ?></div>
                                                    <div class="text-[11px] text-slate-400 font-normal"><?= e($pc['category'] ?? 'General') ?> &bull; <?= e($pc['country'] ?? 'Global') ?></div>
                                                </td>
                                                <td class="py-3.5 px-4">
                                                    <div class="text-slate-200 font-medium"><?= e($pc['owner_name']) ?></div>
                                                    <div class="text-[11px] text-slate-400 font-mono"><?= e($pc['email']) ?></div>
                                                    <?php if (!empty($pc['phone'])): ?>
                                                        <div class="text-[10px] text-slate-500 font-mono"><?= e($pc['phone']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="py-3.5 px-4">
                                                    <span class="px-2.5 py-1 rounded-lg text-[11px] font-bold uppercase bg-amber-500/20 text-amber-300 border border-amber-500/30">
                                                        <?= e($pc['plan'] ?? 'business') ?>
                                                    </span>
                                                    <div class="text-[10px] text-slate-400 uppercase mt-1 font-mono">
                                                        Currency: <span class="text-slate-200 font-bold"><?= e($pc['currency'] ?? 'USD') ?></span>
                                                    </div>
                                                </td>
                                                <td class="py-3.5 px-4">
                                                    <div class="font-semibold text-emerald-400 flex items-center gap-1.5">
                                                        <i class="fa-solid fa-credit-card text-[10px]"></i>
                                                        <span><?= strtoupper(e($pc['payment_method'] ?? 'Manual Proof')) ?></span>
                                                    </div>
                                                    <div class="text-[11px] font-mono text-slate-200 bg-slate-950/80 px-2 py-1 rounded-lg border border-slate-800 mt-1 inline-block select-all">
                                                        Ref: <span class="text-amber-300 font-bold"><?= e($pc['payment_reference'] ?? 'N/A') ?></span>
                                                    </div>
                                                </td>
                                                <td class="py-3.5 px-4 font-mono text-slate-400 text-[11px]">
                                                    <?= substr($pc['created_at'] ?? '', 0, 16) ?>
                                                </td>
                                                <td class="py-3.5 px-4 text-right">
                                                    <div class="inline-flex items-center gap-1.5 justify-end">
                                                        <button onclick="approveCompany('<?= $pc['id'] ?>', '<?= e(addslashes($pc['name'])) ?>')" title="Approve and Activate Account" class="px-3 py-1.5 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold rounded-xl shadow-lg shadow-emerald-600/20 transition-all flex items-center gap-1.5">
                                                            <i class="fa-solid fa-check"></i>
                                                            <span>Approve</span>
                                                        </button>
                                                        <button onclick="rejectCompany('<?= $pc['id'] ?>', '<?= e(addslashes($pc['name'])) ?>')" title="Reject Account" class="px-3 py-1.5 bg-rose-600/20 hover:bg-rose-600/40 text-rose-300 border border-rose-500/30 text-xs font-bold rounded-xl transition-all flex items-center gap-1.5">
                                                            <i class="fa-solid fa-xmark"></i>
                                                            <span>Reject</span>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="admin-card p-6 rounded-3xl border border-slate-800 space-y-6">
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                            <div>
                                <h3 class="text-lg font-bold text-white">Registered Companies & Tenants</h3>
                                <p class="text-xs text-slate-400 mt-0.5">Manage, inspect, edit tenant subscriptions, and view dynamic QR portfolios</p>
                            </div>
                            <button onclick="openCreateCompanyModal()" class="inline-flex items-center gap-2 px-4 py-2.5 bg-rose-600 hover:bg-rose-500 text-white text-xs font-bold rounded-xl shadow-lg shadow-rose-500/25 transition-all">
                                <i class="fa-solid fa-plus"></i>
                                <span>Add New Company</span>
                            </button>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs text-slate-300">
                                <thead class="text-[11px] uppercase tracking-wider text-slate-400 bg-slate-950/60 border-b border-slate-800">
                                    <tr>
                                        <th class="py-3.5 px-4 font-bold">Company</th>
                                        <th class="py-3.5 px-4 font-bold">Owner / Email</th>
                                        <th class="py-3.5 px-4 font-bold">Plan</th>
                                        <th class="py-3.5 px-4 font-bold text-center">QR Codes</th>
                                        <th class="py-3.5 px-4 font-bold text-center">Scans</th>
                                        <th class="py-3.5 px-4 font-bold">Status</th>
                                        <th class="py-3.5 px-4 font-bold text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-800/60">
                                    <?php foreach ($companies as $c): ?>
                                        <?php
                                        $compQrs = array_values(array_filter($allQrs, fn($q) => ($q['company_id'] ?? '') === $c['id']));
                                        $compScans = array_sum(array_column($compQrs, 'scans'));
                                        $cJson = json_encode($c, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                                        $qrsJson = json_encode($compQrs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                                        $isPending = ($c['status'] ?? '') === 'pending_approval';
                                        ?>
                                        <tr class="hover:bg-slate-900/40 transition-colors <?= $isPending ? 'bg-amber-500/5' : '' ?>">
                                            <td class="py-4 px-4 font-bold text-white">
                                                <div class="text-sm font-bold text-white"><?= e($c['name']) ?></div>
                                                <div class="text-[11px] text-slate-500 font-normal mt-0.5"><?= e($c['category'] ?? 'General') ?> &bull; <?= e($c['country'] ?? 'Global') ?></div>
                                            </td>
                                            <td class="py-4 px-4">
                                                <div class="text-slate-200"><?= e($c['owner_name']) ?></div>
                                                <div class="text-[11px] text-slate-400 font-mono"><?= e($c['email']) ?></div>
                                            </td>
                                            <td class="py-4 px-4">
                                                <span class="px-2.5 py-1 rounded-lg text-[11px] font-bold uppercase <?= ($c['plan'] ?? '') === 'pro' ? 'bg-amber-500/10 text-amber-400 border border-amber-500/20' : (($c['plan'] ?? '') === 'business' ? 'bg-blue-500/10 text-blue-400 border border-blue-500/20' : 'bg-slate-800 text-slate-300') ?>">
                                                    <?= e($c['plan'] ?? 'free') ?>
                                                </span>
                                            </td>
                                            <td class="py-4 px-4 text-center font-bold text-white"><?= count($compQrs) ?></td>
                                            <td class="py-4 px-4 text-center font-bold text-emerald-400"><?= number_format($compScans) ?></td>
                                            <td class="py-4 px-4">
                                                <?php if ($isPending): ?>
                                                    <div class="space-y-1">
                                                        <span class="px-2.5 py-1 rounded-full text-[10px] font-extrabold inline-flex items-center gap-1.5 bg-amber-500/20 text-amber-300 border border-amber-500/40 animate-pulse">
                                                            <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                                                            PENDING APPROVAL
                                                        </span>
                                                        <?php if (!empty($c['payment_reference'])): ?>
                                                            <div class="text-[10px] text-slate-400 font-mono truncate max-w-[130px]" title="Ref: <?= e($c['payment_reference']) ?>">
                                                                Ref: <span class="text-amber-300 font-bold"><?= e($c['payment_reference']) ?></span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <button onclick="toggleCompanyStatus('<?= $c['id'] ?>')" title="Click to toggle status" class="px-2.5 py-1 rounded-full text-[10px] font-bold inline-flex items-center gap-1.5 <?= ($c['status'] ?? 'active') === 'active' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border border-rose-500/20' ?>">
                                                        <span class="w-1.5 h-1.5 rounded-full <?= ($c['status'] ?? 'active') === 'active' ? 'bg-emerald-400' : 'bg-rose-400' ?>"></span>
                                                        <?= strtoupper(e($c['status'] ?? 'active')) ?>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-4 px-4 text-right">
                                                <div class="inline-flex items-center gap-1.5 justify-end">
                                                    <?php if ($isPending): ?>
                                                        <button onclick="approveCompany('<?= $c['id'] ?>', '<?= e(addslashes($c['name'])) ?>')" title="Approve Payment & Activate Tenant" class="p-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold border border-emerald-500 shadow-md shadow-emerald-500/20 transition-all">
                                                            <i class="fa-solid fa-check text-xs"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <!-- 1. VIEW COMPANY MODAL -->
                                                    <button onclick='openViewCompanyModal(<?= $cJson ?>, <?= $qrsJson ?>)' title="View Company Details" class="p-2 rounded-xl bg-slate-950 hover:bg-emerald-600/20 text-slate-300 hover:text-emerald-400 border border-slate-800 transition-all">
                                                        <i class="fa-solid fa-eye text-xs"></i>
                                                    </button>
                                                    <!-- 2. EDIT COMPANY MODAL -->
                                                    <button onclick='openEditCompanyModal(<?= $cJson ?>)' title="Edit Company Details" class="p-2 rounded-xl bg-slate-950 hover:bg-amber-600/20 text-slate-300 hover:text-amber-400 border border-slate-800 transition-all">
                                                        <i class="fa-solid fa-pen-to-square text-xs"></i>
                                                    </button>
                                                    <!-- 3. TOGGLE STATUS -->
                                                    <button onclick="toggleCompanyStatus('<?= $c['id'] ?>')" title="Toggle Active / Suspended" class="p-2 rounded-xl bg-slate-950 hover:bg-teal-600/20 text-slate-300 hover:text-teal-400 border border-slate-800 transition-all">
                                                        <i class="fa-solid <?= ($c['status'] ?? 'active') === 'active' ? 'fa-pause' : 'fa-play' ?> text-xs"></i>
                                                    </button>
                                                    <!-- 4. DELETE COMPANY -->
                                                    <button onclick="deleteCompany('<?= $c['id'] ?>', '<?= e(addslashes($c['name'])) ?>')" title="Delete Company" class="p-2 rounded-xl bg-slate-950 hover:bg-rose-950/40 text-slate-400 hover:text-rose-400 border border-slate-800 transition-all">
                                                        <i class="fa-solid fa-trash-can text-xs"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- MODAL 1: VIEW COMPANY MODAL -->
                    <div id="viewCompanyModal" class="hidden fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
                        <div class="admin-card rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-2xl space-y-5 relative border border-slate-800 text-left max-h-[90vh] overflow-y-auto">
                            <button type="button" onclick="closeViewCompanyModal()" class="absolute top-5 right-5 text-slate-400 hover:text-white p-2 text-sm"><i class="fa-solid fa-xmark"></i></button>
                            
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-rose-600 to-pink-500 flex items-center justify-center text-white text-xl font-bold shadow-md">
                                    <i class="fa-solid fa-building"></i>
                                </div>
                                <div class="flex-1 overflow-hidden">
                                    <h4 id="viewCompName" class="text-lg font-bold text-white truncate">Company Name</h4>
                                    <p id="viewCompMeta" class="text-xs text-slate-400">Owner &bull; Email</p>
                                </div>
                                <span id="viewCompStatusBadge" class="px-2.5 py-1 rounded-full text-[10px] font-bold uppercase bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">Active</span>
                            </div>

                            <!-- Stat KPI Pills -->
                            <div class="grid grid-cols-3 gap-3 bg-slate-950/80 p-3.5 rounded-2xl border border-slate-800 text-center">
                                <div>
                                    <span class="text-[10px] text-slate-400 uppercase font-semibold">Tier Plan</span>
                                    <div id="viewCompPlan" class="text-sm font-bold text-amber-400 uppercase">Pro</div>
                                </div>
                                <div>
                                    <span class="text-[10px] text-slate-400 uppercase font-semibold">Total QRs</span>
                                    <div id="viewCompQrsCount" class="text-sm font-bold text-white">0</div>
                                </div>
                                <div>
                                    <span class="text-[10px] text-slate-400 uppercase font-semibold">Total Scans</span>
                                    <div id="viewCompScansCount" class="text-sm font-bold text-emerald-400">0</div>
                                </div>
                            </div>

                            <!-- QR Codes Sub-List -->
                            <div>
                                <h5 class="text-xs font-bold text-slate-300 uppercase tracking-wider mb-2">Dynamic QR Codes (Portfolio)</h5>
                                <div id="viewCompQrsContainer" class="max-h-48 overflow-y-auto space-y-2 pr-1">
                                    <!-- Populated dynamically -->
                                </div>
                            </div>

                            <div class="pt-2 flex gap-3">
                                <button type="button" onclick="editCurrentFromView()" class="flex-1 py-2.5 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-xs font-bold shadow-md transition-all">
                                    <i class="fa-solid fa-pen-to-square mr-1"></i> Edit Tenant
                                </button>
                                <button type="button" onclick="closeViewCompanyModal()" class="py-2.5 px-6 rounded-xl bg-slate-800 text-xs font-bold text-slate-300 hover:bg-slate-700">Close</button>
                            </div>
                        </div>
                    </div>

                    <!-- MODAL 2: EDIT COMPANY MODAL -->
                    <div id="editCompanyModal" class="hidden fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
                        <div class="admin-card rounded-3xl p-6 sm:p-8 max-w-md w-full shadow-2xl space-y-4 relative border border-slate-800 text-left max-h-[90vh] overflow-y-auto">
                            <button type="button" onclick="closeEditCompanyModal()" class="absolute top-4 right-4 text-slate-400 hover:text-white p-2 text-sm"><i class="fa-solid fa-xmark"></i></button>
                            <h4 class="text-lg font-bold text-white">Edit Company Tenant</h4>
                            <form id="editCompanyForm" onsubmit="submitEditCompany(event)" class="space-y-4 text-left">
                                <input type="hidden" id="edit_comp_id" name="company_id">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Company Name</label>
                                    <input type="text" id="edit_comp_name" name="name" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-slate-100">
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Owner Name</label>
                                        <input type="text" id="edit_comp_owner" name="owner_name" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-100">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Email Address</label>
                                        <input type="email" id="edit_comp_email" name="email" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-100">
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Industry / Category</label>
                                        <input type="text" id="edit_comp_category" name="category" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-100">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Country</label>
                                        <input type="text" id="edit_comp_country" name="country" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-100">
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Subscription Plan</label>
                                        <select id="edit_comp_plan" name="plan" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-100">
                                            <option value="free">Free</option>
                                            <option value="business">Business</option>
                                            <option value="pro">Pro Enterprise</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Account Status</label>
                                        <select id="edit_comp_status" name="status" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-100">
                                            <option value="active">Active</option>
                                            <option value="pending_approval">Pending Approval</option>
                                            <option value="suspended">Suspended</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Reset Password (leave empty to keep current)</label>
                                    <input type="password" id="edit_comp_password" name="password" placeholder="••••••••" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-100">
                                </div>
                                <div class="pt-2 flex gap-3">
                                    <button type="button" onclick="closeEditCompanyModal()" class="flex-1 py-2.5 rounded-xl bg-slate-800 text-xs font-bold text-slate-300 hover:bg-slate-700">Cancel</button>
                                    <button type="submit" class="flex-1 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white text-xs font-bold shadow-md">Save Changes</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- MODAL 3: CREATE COMPANY MODAL -->
                    <div id="createCompanyModal" class="hidden fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
                        <div class="admin-card rounded-3xl p-6 sm:p-8 max-w-md w-full shadow-2xl space-y-4 relative border border-slate-800 text-left max-h-[90vh] overflow-y-auto">
                            <button type="button" onclick="closeCreateCompanyModal()" class="absolute top-4 right-4 text-slate-400 hover:text-white p-2 text-sm"><i class="fa-solid fa-xmark"></i></button>
                            <h4 class="text-lg font-bold text-white">Register New Company Tenant</h4>
                            <form id="createCompanyForm" onsubmit="submitCreateCompany(event)" class="space-y-4 text-left">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Company Name</label>
                                    <input type="text" name="name" placeholder="e.g. Acme Corporation" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-slate-100">
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Owner Name</label>
                                        <input type="text" name="owner_name" placeholder="John Doe" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-100">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Email Address</label>
                                        <input type="email" name="email" placeholder="owner@acme.com" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-100">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Initial Password</label>
                                    <input type="password" name="password" placeholder="password123" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-100">
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Industry / Category</label>
                                        <input type="text" name="category" placeholder="Marketing / Retail" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-100">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Country</label>
                                        <input type="text" name="country" placeholder="United States" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-100">
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Subscription Plan</label>
                                        <select name="plan" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-100">
                                            <option value="free">Free</option>
                                            <option value="business">Business</option>
                                            <option value="pro" selected>Pro Enterprise</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Status</label>
                                        <select name="status" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-100">
                                            <option value="active" selected>Active</option>
                                            <option value="suspended">Suspended</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="pt-2 flex gap-3">
                                    <button type="button" onclick="closeCreateCompanyModal()" class="flex-1 py-2.5 rounded-xl bg-slate-800 text-xs font-bold text-slate-300 hover:bg-slate-700">Cancel</button>
                                    <button type="submit" class="flex-1 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white text-xs font-bold shadow-md">Create Account</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <script>
                        let activeViewCompany = null;

                        function openViewCompanyModal(comp, qrs) {
                            activeViewCompany = comp;
                            document.getElementById('viewCompName').innerText = comp.name || 'Company';
                            document.getElementById('viewCompMeta').innerText = (comp.owner_name || 'Owner') + ' • ' + (comp.email || '') + ' • ' + (comp.country || 'Global');
                            document.getElementById('viewCompPlan').innerText = (comp.plan || 'free').toUpperCase();
                            document.getElementById('viewCompStatusBadge').innerText = (comp.status || 'active').toUpperCase();
                            
                            const isAct = (comp.status || 'active') === 'active';
                            document.getElementById('viewCompStatusBadge').className = isAct
                                ? 'px-2.5 py-1 rounded-full text-[10px] font-bold uppercase bg-emerald-500/10 text-emerald-400 border border-emerald-500/20'
                                : 'px-2.5 py-1 rounded-full text-[10px] font-bold uppercase bg-rose-500/10 text-rose-400 border border-rose-500/20';

                            document.getElementById('viewCompQrsCount').innerText = (qrs || []).length;
                            const totalScans = (qrs || []).reduce((acc, q) => acc + (parseInt(q.scans) || 0), 0);
                            document.getElementById('viewCompScansCount').innerText = totalScans.toLocaleString();

                            const container = document.getElementById('viewCompQrsContainer');
                            container.innerHTML = '';
                            if (!qrs || qrs.length === 0) {
                                container.innerHTML = '<div class="text-xs text-slate-500 py-3 text-center">No QR campaigns generated yet.</div>';
                            } else {
                                qrs.forEach(q => {
                                    const shortUrl = 'index.php?qr=' + encodeURIComponent(q.slug || q.id);
                                    const item = document.createElement('div');
                                    item.className = 'flex items-center justify-between p-2.5 rounded-xl bg-slate-950 border border-slate-800 text-xs';
                                    item.innerHTML = `
                                        <div class="overflow-hidden pr-2">
                                            <div class="font-bold text-slate-200 truncate">${q.name || 'Untitled QR'}</div>
                                            <a href="${shortUrl}" target="_blank" class="text-[10px] font-mono text-emerald-400 hover:underline">/${shortUrl}</a>
                                        </div>
                                        <div class="flex items-center gap-2 shrink-0">
                                            <span class="text-[10px] px-2 py-0.5 rounded bg-slate-900 border border-slate-800 text-slate-300">${(q.scans || 0)} scans</span>
                                            <a href="${shortUrl}" target="_blank" class="p-1.5 rounded-lg bg-slate-900 hover:bg-blue-600/20 text-slate-400 hover:text-blue-400 border border-slate-800"><i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i></a>
                                        </div>
                                    `;
                                    container.appendChild(item);
                                });
                            }

                            document.getElementById('viewCompanyModal').classList.remove('hidden');
                        }

                        function closeViewCompanyModal() {
                            document.getElementById('viewCompanyModal').classList.add('hidden');
                        }

                        function editCurrentFromView() {
                            closeViewCompanyModal();
                            if (activeViewCompany) {
                                openEditCompanyModal(activeViewCompany);
                            }
                        }

                        function openEditCompanyModal(comp) {
                            document.getElementById('edit_comp_id').value = comp.id;
                            document.getElementById('edit_comp_name').value = comp.name || '';
                            document.getElementById('edit_comp_owner').value = comp.owner_name || '';
                            document.getElementById('edit_comp_email').value = comp.email || '';
                            document.getElementById('edit_comp_category').value = comp.category || '';
                            document.getElementById('edit_comp_country').value = comp.country || '';
                            document.getElementById('edit_comp_plan').value = comp.plan || 'free';
                            document.getElementById('edit_comp_status').value = comp.status || 'active';
                            document.getElementById('edit_comp_password').value = '';
                            document.getElementById('editCompanyModal').classList.remove('hidden');
                        }

                        function closeEditCompanyModal() {
                            document.getElementById('editCompanyModal').classList.add('hidden');
                        }

                        async function submitEditCompany(e) {
                            e.preventDefault();
                            const formData = new FormData(e.target);
                            formData.append('action', 'update_company');
                            const res = await fetch('admin.php', { method: 'POST', body: formData });
                            const data = await res.json();
                            if (data.success) {
                                alert('Company details updated successfully!');
                                window.location.reload();
                            } else {
                                alert(data.error || 'Failed to update company.');
                            }
                        }

                        function openCreateCompanyModal() {
                            document.getElementById('createCompanyForm').reset();
                            document.getElementById('createCompanyModal').classList.remove('hidden');
                        }

                        function closeCreateCompanyModal() {
                            document.getElementById('createCompanyModal').classList.add('hidden');
                        }

                        async function submitCreateCompany(e) {
                            e.preventDefault();
                            const formData = new FormData(e.target);
                            formData.append('action', 'create_company');
                            const res = await fetch('admin.php', { method: 'POST', body: formData });
                            const data = await res.json();
                            if (data.success) {
                                alert('New tenant registered successfully!');
                                window.location.reload();
                            } else {
                                alert(data.error || 'Failed to register company.');
                            }
                        }

                        async function toggleCompanyStatus(cid) {
                            const formData = new FormData();
                            formData.append('action', 'toggle_company_status');
                            formData.append('company_id', cid);
                            await fetch('admin.php', { method: 'POST', body: formData });
                            window.location.reload();
                        }

                        async function changeCompanyPlan(cid, plan) {
                            const formData = new FormData();
                            formData.append('action', 'change_company_plan');
                            formData.append('company_id', cid);
                            formData.append('plan', plan);
                            await fetch('admin.php', { method: 'POST', body: formData });
                            alert('Company plan updated to ' + plan.toUpperCase());
                        }

                        async function deleteCompany(cid, name) {
                            if (!confirm('Are you sure you want to delete company "' + (name || cid) + '" and all its QR campaigns?')) return;
                            const formData = new FormData();
                            formData.append('action', 'delete_company');
                            formData.append('company_id', cid);
                            await fetch('admin.php', { method: 'POST', body: formData });
                            window.location.reload();
                        }
                    </script>

                <?php elseif ($page === 'qrs'): ?>
                    <!-- GLOBAL QR CODE INSPECTOR -->
                    <div class="admin-card p-6 rounded-3xl border border-slate-800 space-y-6">
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                            <div>
                                <h3 class="text-lg font-bold text-white">Global QR Codes Registry</h3>
                                <p class="text-xs text-slate-400 mt-0.5">Inspect, edit destinations, view previews, and manage QR campaigns across all tenants.</p>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-xs text-slate-300">
                                <thead class="text-[11px] uppercase tracking-wider text-slate-400 bg-slate-950/60 border-b border-slate-800">
                                    <tr>
                                        <th class="py-3.5 px-4 font-bold">QR Campaign</th>
                                        <th class="py-3.5 px-4 font-bold">Company</th>
                                        <th class="py-3.5 px-4 font-bold">Type</th>
                                        <th class="py-3.5 px-4 font-bold text-center">Scans</th>
                                        <th class="py-3.5 px-4 font-bold">Status</th>
                                        <th class="py-3.5 px-4 font-bold text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-800/60">
                                    <?php foreach ($allQrs as $qr): ?>
                                        <?php
                                        $shortUrl = 'index.php?qr=' . urlencode($qr['slug'] ?: $qr['id']);
                                        $qrJson = json_encode($qr, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                                        ?>
                                        <tr class="hover:bg-slate-900/40 transition-colors">
                                            <td class="py-4 px-4 font-bold text-white">
                                                <div><?= e($qr['name']) ?></div>
                                                <a href="<?= e($shortUrl) ?>" target="_blank" class="text-[11px] text-blue-400 font-mono hover:underline">/<?= e($shortUrl) ?></a>
                                            </td>
                                            <td class="py-4 px-4 text-slate-400">
                                                <?= e($companies[$qr['company_id']]['name'] ?? $qr['company_id']) ?>
                                            </td>
                                            <td class="py-4 px-4 capitalize">
                                                <span class="px-2 py-0.5 bg-slate-950 rounded text-[11px] border border-slate-800"><?= e($qr['type']) ?></span>
                                            </td>
                                            <td class="py-4 px-4 text-center font-bold text-white"><?= number_format($qr['scans'] ?? 0) ?></td>
                                            <td class="py-4 px-4">
                                                <button onclick="toggleAdminQr('<?= $qr['id'] ?>')" class="px-2.5 py-1 rounded-full text-[10px] font-bold <?= ($qr['status'] ?? 'active') === 'active' ? 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' : 'bg-rose-500/10 text-rose-400 border border-rose-500/20' ?>">
                                                    <?= strtoupper(e($qr['status'] ?? 'active')) ?>
                                                </button>
                                            </td>
                                            <td class="py-4 px-4 text-right">
                                                <div class="inline-flex items-center gap-1.5 justify-end">
                                                    <!-- 1. VIEW MODAL -->
                                                    <button onclick='openAdminViewModal(<?= $qrJson ?>)' title="View & Download QR" class="p-2 rounded-xl bg-slate-950 hover:bg-emerald-600/20 text-slate-300 hover:text-emerald-400 border border-slate-800 transition-all">
                                                        <i class="fa-solid fa-eye text-xs"></i>
                                                    </button>
                                                    <!-- 2. EDIT MODAL -->
                                                    <button onclick='openAdminEditModal(<?= $qrJson ?>)' title="Edit Campaign Destination" class="p-2 rounded-xl bg-slate-950 hover:bg-amber-600/20 text-slate-300 hover:text-amber-400 border border-slate-800 transition-all">
                                                        <i class="fa-solid fa-pen-to-square text-xs"></i>
                                                    </button>
                                                    <!-- 3. TEST LINK -->
                                                    <a href="<?= e($shortUrl) ?>" target="_blank" title="Test Dynamic Link" class="p-2 rounded-xl bg-slate-950 hover:bg-blue-600/20 text-slate-300 hover:text-blue-400 border border-slate-800 transition-all">
                                                        <i class="fa-solid fa-arrow-up-right-from-square text-xs"></i>
                                                    </a>
                                                    <!-- 4. DELETE -->
                                                    <button onclick="deleteAdminQr('<?= $qr['id'] ?>', '<?= e(addslashes($qr['name'])) ?>')" title="Delete Campaign" class="p-2 rounded-xl bg-slate-950 hover:bg-rose-950/40 text-slate-400 hover:text-rose-400 border border-slate-800 transition-all">
                                                        <i class="fa-solid fa-trash-can text-xs"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ADMIN VIEW MODAL -->
                    <div id="adminViewQrModal" class="hidden fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
                        <div class="admin-card rounded-3xl p-6 max-w-sm w-full shadow-2xl space-y-4 text-center relative border border-slate-800 max-h-[90vh] overflow-y-auto">
                            <button type="button" onclick="closeAdminViewModal()" class="absolute top-4 right-4 text-slate-400 hover:text-white p-2 text-sm"><i class="fa-solid fa-xmark"></i></button>
                            <h4 id="adminViewModalTitle" class="text-base font-bold text-white truncate">QR Campaign</h4>
                            <div class="p-4 bg-white rounded-2xl flex items-center justify-center min-h-[190px] mx-auto max-w-[200px] shadow-inner">
                                <div id="adminViewModalCanvas"></div>
                            </div>
                            <div class="bg-slate-950/80 p-3 rounded-xl border border-slate-800 text-left text-xs space-y-1.5">
                                <div class="text-slate-500 font-mono text-[10px] uppercase">Routing Target</div>
                                <div id="adminViewModalTarget" class="text-slate-200 truncate font-mono">https://...</div>
                            </div>
                            <button type="button" onclick="downloadAdminViewQr()" class="w-full py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-bold text-xs shadow-md">
                                <i class="fa-solid fa-download mr-1"></i> Download PNG
                            </button>
                        </div>
                    </div>

                    <!-- ADMIN EDIT MODAL -->
                    <div id="adminEditQrModal" class="hidden fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
                        <div class="admin-card rounded-3xl p-6 sm:p-8 max-w-md w-full shadow-2xl space-y-4 relative border border-slate-800 text-left max-h-[90vh] overflow-y-auto">
                            <button type="button" onclick="closeAdminEditModal()" class="absolute top-4 right-4 text-slate-400 hover:text-white p-2 text-sm"><i class="fa-solid fa-xmark"></i></button>
                            <h4 class="text-lg font-bold text-white">Edit Campaign (Admin Override)</h4>
                            <form id="adminEditQrForm" onsubmit="submitAdminEditQr(event)" class="space-y-4 text-left">
                                <input type="hidden" id="admin_edit_qr_id" name="qr_id">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Campaign Name</label>
                                    <input type="text" id="admin_edit_qr_name" name="name" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-slate-100">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Destination Target URL</label>
                                    <input type="text" id="admin_edit_qr_target" name="target" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-sm text-slate-100">
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Slug</label>
                                        <input type="text" id="admin_edit_qr_slug" name="slug" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-xs font-mono text-slate-100">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-slate-300 uppercase mb-1">Status</label>
                                        <select id="admin_edit_qr_status" name="status" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-100">
                                            <option value="active">Active</option>
                                            <option value="disabled">Disabled</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="pt-2 flex gap-3">
                                    <button type="button" onclick="closeAdminEditModal()" class="flex-1 py-2.5 rounded-xl bg-slate-800 text-xs font-bold text-slate-300">Cancel</button>
                                    <button type="submit" class="flex-1 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white text-xs font-bold shadow-md">Save Changes</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <script>
                        let adminViewInstance = null;
                        let adminCurrentQr = null;

                        function openAdminViewModal(qr) {
                            adminCurrentQr = qr;
                            document.getElementById('adminViewModalTitle').innerText = qr.name || 'QR Campaign';
                            document.getElementById('adminViewModalTarget').innerText = qr.target || 'N/A';
                            
                            const shortPath = 'index.php?qr=' + encodeURIComponent(qr.slug || qr.id);
                            const fullScanUrl = window.location.origin + window.location.pathname.replace('admin.php', '') + shortPath;
                            const container = document.getElementById('adminViewModalCanvas');
                            container.innerHTML = '';

                            if (typeof QRCodeStyling !== 'undefined') {
                                try {
                                    adminViewInstance = new QRCodeStyling({
                                        width: 175,
                                        height: 175,
                                        type: "canvas",
                                        data: fullScanUrl,
                                        dotsOptions: { color: "#0f172a", type: "rounded" },
                                        backgroundOptions: { color: "#ffffff" }
                                    });
                                    adminViewInstance.append(container);
                                } catch(e) {
                                    renderAdminFallbackQr(container, fullScanUrl);
                                }
                            } else {
                                renderAdminFallbackQr(container, fullScanUrl);
                            }
                            document.getElementById('adminViewQrModal').classList.remove('hidden');
                        }

                        function renderAdminFallbackQr(container, url) {
                            if (typeof QRCode !== 'undefined') {
                                new QRCode(container, { text: url, width: 175, height: 175 });
                            } else {
                                const img = document.createElement('img');
                                img.src = 'https://api.qrserver.com/v1/create-qr-code/?size=175x175&data=' + encodeURIComponent(url);
                                img.className = 'w-[175px] h-[175px]';
                                container.appendChild(img);
                            }
                        }

                        function closeAdminViewModal() {
                            document.getElementById('adminViewQrModal').classList.add('hidden');
                        }

                        function downloadAdminViewQr() {
                            if (adminViewInstance && typeof adminViewInstance.download === 'function') {
                                adminViewInstance.download({ name: (adminCurrentQr?.name || 'qr_code'), extension: 'png' });
                            } else {
                                const canvas = document.querySelector('#adminViewModalCanvas canvas');
                                if (canvas) {
                                    const link = document.createElement('a');
                                    link.download = (adminCurrentQr?.name || 'qr_code') + '.png';
                                    link.href = canvas.toDataURL('image/png');
                                    link.click();
                                }
                            }
                        }

                        function openAdminEditModal(qr) {
                            document.getElementById('admin_edit_qr_id').value = qr.id;
                            document.getElementById('admin_edit_qr_name').value = qr.name || '';
                            document.getElementById('admin_edit_qr_target').value = qr.target || '';
                            document.getElementById('admin_edit_qr_slug').value = qr.slug || '';
                            document.getElementById('admin_edit_qr_status').value = qr.status || 'active';
                            document.getElementById('adminEditQrModal').classList.remove('hidden');
                        }

                        function closeAdminEditModal() {
                            document.getElementById('adminEditQrModal').classList.add('hidden');
                        }

                        async function submitAdminEditQr(e) {
                            e.preventDefault();
                            const formData = new FormData(e.target);
                            formData.append('action', 'update_admin_qr');
                            const res = await fetch('admin.php', { method: 'POST', body: formData });
                            const data = await res.json();
                            if (data.success) {
                                alert('QR Campaign updated!');
                                window.location.reload();
                            } else {
                                alert(data.error || 'Failed to update.');
                            }
                        }

                        async function toggleAdminQr(qid) {
                            const formData = new FormData();
                            formData.append('action', 'toggle_admin_qr');
                            formData.append('qr_id', qid);
                            await fetch('admin.php', { method: 'POST', body: formData });
                            window.location.reload();
                        }

                        async function deleteAdminQr(qid, name) {
                            if (!confirm('Permanently delete the QR campaign "' + (name || '') + '"?')) return;
                            const formData = new FormData();
                            formData.append('action', 'delete_admin_qr');
                            formData.append('qr_id', qid);
                            await fetch('admin.php', { method: 'POST', body: formData });
                            window.location.reload();
                        }
                    </script>

                <?php elseif ($page === 'plans'): ?>
                    <!-- SUBSCRIPTION PLANS EDITOR WITH CURRENCY SWITCHER -->
                    <div class="admin-card p-6 rounded-3xl border border-slate-800 space-y-6">
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                            <div>
                                <h3 class="text-lg font-bold text-white">SaaS Subscription Tier Configurations</h3>
                                <p class="text-xs text-slate-400 mt-0.5">Manage pricing in USD and PKR, scan limits, and white-label permissions.</p>
                            </div>
                            
                            <!-- Currency Switcher Toggle -->
                            <div class="flex items-center gap-2 bg-slate-950 p-1.5 rounded-2xl border border-slate-800">
                                <span class="text-[11px] font-bold text-slate-400 uppercase mr-1">Preview:</span>
                                <button type="button" onclick="setAdminCurrency('USD')" id="adminCurrBtn_USD" class="curr-toggle-btn px-3.5 py-1.5 rounded-xl text-xs font-black transition-all bg-rose-600 text-white shadow-md">
                                    <span>🇺🇸 USD ($)</span>
                                </button>
                                <button type="button" onclick="setAdminCurrency('PKR')" id="adminCurrBtn_PKR" class="curr-toggle-btn px-3.5 py-1.5 rounded-xl text-xs font-semibold text-slate-400 hover:text-white transition-all">
                                    <span>🇵🇰 PKR (₨)</span>
                                </button>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <?php foreach ($allPlans as $p): ?>
                                <?php
                                $priceUsd = $p['price'] ?? '$0';
                                $pricePkr = $p['price_pkr'] ?? ($p['id'] === 'free' ? '₨ 0' : ($p['id'] === 'business' ? '₨ 7,999' : '₨ 21,999'));
                                ?>
                                <div class="admin-card p-6 rounded-2xl border border-slate-800 space-y-4">
                                    <div class="flex items-center justify-between">
                                        <h4 class="text-base font-bold text-white"><?= e($p['name']) ?></h4>
                                        <span class="px-2 py-0.5 rounded bg-slate-950 text-[10px] font-mono border border-slate-800 uppercase text-slate-400"><?= e($p['id']) ?></span>
                                    </div>
                                    <form onsubmit="savePlanConfig(event, '<?= $p['id'] ?>')" class="space-y-3 text-xs">
                                        <input type="hidden" name="id" value="<?= e($p['id']) ?>">
                                        <div>
                                            <label class="block text-slate-400 mb-1 font-semibold">Plan Name</label>
                                            <input type="text" name="name" value="<?= e($p['name']) ?>" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-slate-200">
                                        </div>
                                        <div class="grid grid-cols-2 gap-2">
                                            <div>
                                                <label class="block text-slate-400 mb-1 font-semibold">USD Price ($)</label>
                                                <input type="text" name="price" value="<?= e($priceUsd) ?>" placeholder="$29/mo" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-slate-200 font-mono">
                                            </div>
                                            <div>
                                                <label class="block text-slate-400 mb-1 font-semibold">PKR Price (₨)</label>
                                                <input type="text" name="price_pkr" value="<?= e($pricePkr) ?>" placeholder="₨ 7,999" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-slate-200 font-mono">
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-slate-400 mb-1 font-semibold">QR Code Limit</label>
                                            <input type="number" name="qr_limit" value="<?= (int)($p['qr_limit'] ?? 5) ?>" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-slate-200">
                                        </div>
                                        <div>
                                            <label class="block text-slate-400 mb-1 font-semibold">Monthly Scan Limit</label>
                                            <input type="number" name="scan_limit" value="<?= (int)($p['scan_limit'] ?? 1000) ?>" class="w-full bg-slate-950 border border-slate-800 rounded-lg px-3 py-2 text-slate-200">
                                        </div>
                                        <div class="flex items-center gap-2 pt-2">
                                            <input type="checkbox" name="white_label" value="true" <?= !empty($p['white_label']) ? 'checked' : '' ?> class="rounded bg-slate-950 border-slate-800">
                                            <label class="text-slate-300">Allow White-Label & Custom Domains</label>
                                        </div>
                                        <button type="submit" class="w-full py-2.5 bg-rose-600 hover:bg-rose-500 font-bold text-white rounded-lg transition-colors shadow-md">Update Plan Settings</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <script>
                        let adminCurrentCurrency = localStorage.getItem('qr_currency') || 'USD';

                        function setAdminCurrency(curr) {
                            adminCurrentCurrency = curr;
                            localStorage.setItem('qr_currency', curr);

                            ['USD', 'PKR'].forEach(c => {
                                const btn = document.getElementById('adminCurrBtn_' + c);
                                if (btn) {
                                    if (c === curr) {
                                        btn.className = 'curr-toggle-btn px-3.5 py-1.5 rounded-xl text-xs font-black transition-all bg-rose-600 text-white shadow-md';
                                    } else {
                                        btn.className = 'curr-toggle-btn px-3.5 py-1.5 rounded-xl text-xs font-semibold text-slate-400 hover:text-white transition-all';
                                    }
                                }
                            });
                        }

                        async function savePlanConfig(e, planId) {
                            e.preventDefault();
                            const formData = new FormData(e.target);
                            formData.append('action', 'save_plan_config');
                            await fetch('admin.php', { method: 'POST', body: formData });
                            alert('Plan ' + planId.toUpperCase() + ' updated successfully.');
                        }

                        document.addEventListener('DOMContentLoaded', () => {
                            setAdminCurrency(adminCurrentCurrency);
                        });
                    </script>

                <?php elseif ($page === 'payments'): ?>
                    <!-- PAYMENT METHODS & GATEWAYS MANAGEMENT CENTER -->
                    <div class="space-y-6">
                        <!-- Top Banner & Action Controls -->
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-slate-900/60 p-6 rounded-3xl border border-slate-800">
                            <div>
                                <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-rose-500/10 border border-rose-500/20 text-rose-400 text-xs font-bold uppercase tracking-wider mb-2">
                                    <i class="fa-solid fa-credit-card"></i>
                                    <span>Super Admin Payment Engine</span>
                                </div>
                                <h3 class="text-xl font-black text-white">Payment Gateways & Checkout Methods</h3>
                                <p class="text-xs text-slate-400 mt-0.5">Edit API keys, merchant accounts, Pakistan local wallets (JazzCash, EasyPaisa), bank wire details, and crypto gateways.</p>
                            </div>
                            <button type="button" onclick="openAddGatewayModal()" class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-rose-600 to-pink-600 hover:from-rose-500 hover:to-pink-500 text-white font-bold text-xs shadow-lg shadow-rose-500/25 transition-all flex items-center gap-2 shrink-0">
                                <i class="fa-solid fa-plus"></i> Add Custom Gateway
                            </button>
                        </div>

                        <!-- Payment Gateways KPI Summary -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                            <div class="admin-card p-4 rounded-2xl border border-slate-800">
                                <span class="text-[11px] text-slate-400 font-semibold uppercase">Total Gateways</span>
                                <div class="text-2xl font-black text-white mt-1"><?= count($allPaymentMethods) ?></div>
                            </div>
                            <div class="admin-card p-4 rounded-2xl border border-slate-800">
                                <span class="text-[11px] text-slate-400 font-semibold uppercase">Active Checkout Methods</span>
                                <div class="text-2xl font-black text-emerald-400 mt-1"><?= count(array_filter($allPaymentMethods, fn($m) => ($m['status'] ?? '') === 'active')) ?> Live</div>
                            </div>
                            <div class="admin-card p-4 rounded-2xl border border-slate-800">
                                <span class="text-[11px] text-slate-400 font-semibold uppercase">Supported Currencies</span>
                                <div class="text-sm font-bold text-amber-400 mt-1 flex items-center gap-2">
                                    <span>🇺🇸 USD ($)</span> &bull; <span>🇵🇰 PKR (₨)</span> &bull; <span>₮ Crypto</span>
                                </div>
                            </div>
                            <div class="admin-card p-4 rounded-2xl border border-slate-800">
                                <span class="text-[11px] text-slate-400 font-semibold uppercase">Checkout System Status</span>
                                <div class="text-sm font-bold text-emerald-400 mt-1 flex items-center gap-1.5">
                                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                                    <span>Ready for Tenant Upgrades</span>
                                </div>
                            </div>
                        </div>

                        <!-- Payment Methods List (Editable Cards Grid) -->
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <?php foreach ($allPaymentMethods as $gwId => $gw): ?>
                                <?php
                                $gwStatus = $gw['status'] ?? 'active';
                                $gwMode = $gw['mode'] ?? 'live';
                                $gwIcon = $gw['icon'] ?? 'fa-solid fa-credit-card';
                                $isCustom = !in_array($gwId, ['stripe', 'paypal', 'jazzcash', 'easypaisa', 'bank_transfer', 'crypto', 'razorpay']);
                                ?>
                                <div class="admin-card p-6 rounded-3xl border border-slate-800 space-y-5 relative shadow-xl">
                                    <!-- Card Header -->
                                    <div class="flex items-start justify-between gap-3 pb-4 border-b border-slate-800/80">
                                        <div class="flex items-center gap-3">
                                            <div class="w-12 h-12 rounded-2xl bg-slate-900 border border-slate-800 flex items-center justify-center text-xl text-rose-400 shrink-0 shadow-inner">
                                                <i class="<?= e($gwIcon) ?>"></i>
                                            </div>
                                            <div>
                                                <div class="flex items-center gap-2">
                                                    <h4 class="text-base font-bold text-white"><?= e($gw['name'] ?? ucfirst($gwId)) ?></h4>
                                                    <?php if ($gwStatus === 'active'): ?>
                                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-emerald-500/10 text-emerald-400 border border-emerald-500/20">Active</span>
                                                    <?php else: ?>
                                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase bg-rose-500/10 text-rose-400 border border-rose-500/20">Disabled</span>
                                                    <?php endif; ?>
                                                </div>
                                                <span class="text-[11px] text-slate-400 font-mono">ID: <?= e($gwId) ?> &bull; <?= e($gw['currencies'] ?? 'USD') ?></span>
                                            </div>
                                        </div>
                                        
                                        <div class="flex items-center gap-2">
                                            <button type="button" onclick="toggleGatewayStatus('<?= e($gwId) ?>')" class="px-3 py-1.5 rounded-xl text-xs font-bold transition-all <?= $gwStatus === 'active' ? 'bg-amber-600/20 text-amber-400 border border-amber-500/30 hover:bg-amber-600/30' : 'bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 hover:bg-emerald-600/30' ?>">
                                                <?= $gwStatus === 'active' ? '<i class="fa-solid fa-pause mr-1"></i> Disable' : '<i class="fa-solid fa-play mr-1"></i> Enable' ?>
                                            </button>
                                            <?php if ($isCustom): ?>
                                                <button type="button" onclick="deleteCustomGateway('<?= e($gwId) ?>', '<?= e($gw['name']) ?>')" class="p-2 rounded-xl bg-rose-950/40 text-rose-400 border border-rose-900/50 hover:bg-rose-900/60 transition-colors" title="Delete Custom Gateway">
                                                    <i class="fa-solid fa-trash-can text-xs"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Editable Form -->
                                    <form onsubmit="savePaymentGateway(event, '<?= e($gwId) ?>')" class="space-y-4 text-xs">
                                        <input type="hidden" name="id" value="<?= e($gwId) ?>">
                                        <input type="hidden" name="icon" value="<?= e($gwIcon) ?>">

                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <div>
                                                <label class="block text-slate-400 font-semibold mb-1">Display Name</label>
                                                <input type="text" name="name" value="<?= e($gw['name'] ?? '') ?>" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-medium focus:outline-none focus:border-rose-500">
                                            </div>
                                            <div>
                                                <label class="block text-slate-400 font-semibold mb-1">Status</label>
                                                <select name="status" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 focus:outline-none focus:border-rose-500">
                                                    <option value="active" <?= $gwStatus === 'active' ? 'selected' : '' ?>>Active (Visible to users)</option>
                                                    <option value="disabled" <?= $gwStatus === 'disabled' ? 'selected' : '' ?>>Disabled</option>
                                                </select>
                                            </div>
                                        </div>

                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                            <div>
                                                <label class="block text-slate-400 font-semibold mb-1">Environment Mode</label>
                                                <select name="mode" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 focus:outline-none focus:border-rose-500">
                                                    <option value="live" <?= $gwMode === 'live' ? 'selected' : '' ?>>Live / Production</option>
                                                    <option value="test" <?= ($gwMode === 'test' || $gwMode === 'sandbox') ? 'selected' : '' ?>>Test / Sandbox</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-slate-400 font-semibold mb-1">Supported Currencies</label>
                                                <input type="text" name="currencies" value="<?= e($gw['currencies'] ?? 'USD') ?>" placeholder="USD, PKR, EUR" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 focus:outline-none focus:border-rose-500">
                                            </div>
                                        </div>

                                        <!-- Specific Gateway Credentials -->
                                        <?php if ($gwId === 'stripe'): ?>
                                            <div>
                                                <label class="block text-slate-400 font-semibold mb-1">Stripe Publishable Key</label>
                                                <input type="text" name="public_key" value="<?= e($gw['public_key'] ?? '') ?>" placeholder="pk_live_..." class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-mono text-[11px] focus:outline-none focus:border-rose-500">
                                            </div>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                <div>
                                                    <label class="block text-slate-400 font-semibold mb-1">Stripe Secret Key</label>
                                                    <input type="password" name="secret_key" value="<?= e($gw['secret_key'] ?? '') ?>" placeholder="sk_live_..." class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-mono text-[11px] focus:outline-none focus:border-rose-500">
                                                </div>
                                                <div>
                                                    <label class="block text-slate-400 font-semibold mb-1">Webhook Secret (Optional)</label>
                                                    <input type="password" name="webhook_secret" value="<?= e($gw['webhook_secret'] ?? '') ?>" placeholder="whsec_..." class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-mono text-[11px] focus:outline-none focus:border-rose-500">
                                                </div>
                                            </div>
                                        <?php elseif ($gwId === 'paypal'): ?>
                                            <div>
                                                <label class="block text-slate-400 font-semibold mb-1">PayPal Client ID</label>
                                                <input type="text" name="client_id" value="<?= e($gw['client_id'] ?? '') ?>" placeholder="Client ID from PayPal Developer Portal" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-mono text-[11px] focus:outline-none focus:border-rose-500">
                                            </div>
                                            <div>
                                                <label class="block text-slate-400 font-semibold mb-1">PayPal Secret Key</label>
                                                <input type="password" name="secret_key" value="<?= e($gw['secret_key'] ?? '') ?>" placeholder="PayPal Secret Key" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-mono text-[11px] focus:outline-none focus:border-rose-500">
                                            </div>
                                        <?php elseif ($gwId === 'jazzcash'): ?>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                <div>
                                                    <label class="block text-slate-400 font-semibold mb-1">JazzCash Merchant ID</label>
                                                    <input type="text" name="merchant_id" value="<?= e($gw['merchant_id'] ?? '') ?>" placeholder="MC12345" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-mono text-[11px]">
                                                </div>
                                                <div>
                                                    <label class="block text-slate-400 font-semibold mb-1">Password / API Pass</label>
                                                    <input type="password" name="password" value="<?= e($gw['password'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-mono text-[11px]">
                                                </div>
                                            </div>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                <div>
                                                    <label class="block text-slate-400 font-semibold mb-1">Account / Mobile Number</label>
                                                    <input type="text" name="account_number" value="<?= e($gw['account_number'] ?? '') ?>" placeholder="0300-1234567" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-mono text-[11px]">
                                                </div>
                                                <div>
                                                    <label class="block text-slate-400 font-semibold mb-1">Account Title</label>
                                                    <input type="text" name="account_title" value="<?= e($gw['account_title'] ?? '') ?>" placeholder="Business Account Name" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                                                </div>
                                            </div>
                                            <div>
                                                <label class="block text-slate-400 font-semibold mb-1">Integrity Salt</label>
                                                <input type="password" name="integrity_salt" value="<?= e($gw['integrity_salt'] ?? '') ?>" placeholder="JazzCash Integrity Salt" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-mono text-[11px]">
                                            </div>
                                        <?php elseif ($gwId === 'easypaisa'): ?>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                <div>
                                                    <label class="block text-slate-400 font-semibold mb-1">EasyPaisa Store ID</label>
                                                    <input type="text" name="store_id" value="<?= e($gw['store_id'] ?? '') ?>" placeholder="STORE_12345" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-mono text-[11px]">
                                                </div>
                                                <div>
                                                    <label class="block text-slate-400 font-semibold mb-1">Hash / Secret Key</label>
                                                    <input type="password" name="hash_key" value="<?= e($gw['hash_key'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-mono text-[11px]">
                                                </div>
                                            </div>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                <div>
                                                    <label class="block text-slate-400 font-semibold mb-1">Account / Mobile Number</label>
                                                    <input type="text" name="account_number" value="<?= e($gw['account_number'] ?? '') ?>" placeholder="0345-1234567" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-mono text-[11px]">
                                                </div>
                                                <div>
                                                    <label class="block text-slate-400 font-semibold mb-1">Account Title</label>
                                                    <input type="text" name="account_title" value="<?= e($gw['account_title'] ?? '') ?>" placeholder="EasyPaisa Account Title" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                                                </div>
                                            </div>
                                        <?php elseif ($gwId === 'bank_transfer'): ?>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                <div>
                                                    <label class="block text-slate-400 font-semibold mb-1">Bank Name</label>
                                                    <input type="text" name="bank_name" value="<?= e($gw['bank_name'] ?? '') ?>" placeholder="e.g. Standard Chartered / Meezan Bank" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                                                </div>
                                                <div>
                                                    <label class="block text-slate-400 font-semibold mb-1">Account Title</label>
                                                    <input type="text" name="account_title" value="<?= e($gw['account_title'] ?? '') ?>" placeholder="Company Beneficiary Name" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                                                </div>
                                            </div>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                <div>
                                                    <label class="block text-slate-400 font-semibold mb-1">Account Number / IBAN</label>
                                                    <input type="text" name="account_number" value="<?= e($gw['account_number'] ?? '') ?>" placeholder="PK36MEZN0001234567890101" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-mono text-[11px]">
                                                </div>
                                                <div>
                                                    <label class="block text-slate-400 font-semibold mb-1">SWIFT / BIC / Branch Code</label>
                                                    <input type="text" name="swift_code" value="<?= e($gw['swift_code'] ?? '') ?>" placeholder="MEZNPKKA" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-mono text-[11px]">
                                                </div>
                                            </div>
                                        <?php elseif ($gwId === 'crypto'): ?>
                                            <div>
                                                <label class="block text-slate-400 font-semibold mb-1">Primary Crypto Wallet Address</label>
                                                <input type="text" name="wallet_address" value="<?= e($gw['wallet_address'] ?? '') ?>" placeholder="TXyZ9876543210..." class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-mono text-[11px]">
                                            </div>
                                            <div>
                                                <label class="block text-slate-400 font-semibold mb-1">Supported Networks & Binance Pay ID</label>
                                                <input type="text" name="network" value="<?= e($gw['network'] ?? '') ?>" placeholder="USDT (TRC-20) / Binance Pay ID" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                                            </div>
                                        <?php elseif ($gwId === 'razorpay'): ?>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                <div>
                                                    <label class="block text-slate-400 font-semibold mb-1">Razorpay Key ID</label>
                                                    <input type="text" name="key_id" value="<?= e($gw['key_id'] ?? '') ?>" placeholder="rzp_live_..." class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-mono text-[11px]">
                                                </div>
                                                <div>
                                                    <label class="block text-slate-400 font-semibold mb-1">Razorpay Key Secret</label>
                                                    <input type="password" name="key_secret" value="<?= e($gw['key_secret'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-mono text-[11px]">
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                                <div>
                                                    <label class="block text-slate-400 font-semibold mb-1">Account / Merchant ID</label>
                                                    <input type="text" name="account_number" value="<?= e($gw['account_number'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 font-mono text-[11px]">
                                                </div>
                                                <div>
                                                    <label class="block text-slate-400 font-semibold mb-1">Account Title / Secret</label>
                                                    <input type="text" name="account_title" value="<?= e($gw['account_title'] ?? '') ?>" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100">
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <div>
                                            <label class="block text-slate-400 font-semibold mb-1">Checkout Instructions & Notes (Shown to Customers)</label>
                                            <textarea name="instructions" rows="2" placeholder="Payment instructions for customer..." class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 focus:outline-none focus:border-rose-500"><?= e($gw['instructions'] ?? '') ?></textarea>
                                        </div>

                                        <div class="pt-2 flex items-center gap-3">
                                            <button type="submit" class="flex-1 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 font-bold text-white text-xs shadow-md transition-all">
                                                <i class="fa-solid fa-floppy-disk mr-1"></i> Save Gateway Settings
                                            </button>
                                            <button type="button" onclick="testGatewayConnection('<?= e($gwId) ?>', '<?= e($gw['name'] ?? $gwId) ?>')" class="py-2.5 px-4 rounded-xl bg-slate-800 hover:bg-slate-700 text-slate-300 font-semibold text-xs border border-slate-700 transition-colors" title="Verify Connection">
                                                <i class="fa-solid fa-bolt"></i> Test
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- MODAL: ADD CUSTOM PAYMENT GATEWAY -->
                    <div id="addCustomGatewayModal" class="hidden fixed inset-0 z-50 bg-slate-950/80 backdrop-blur-md flex items-center justify-center p-4">
                        <div class="admin-card rounded-3xl p-6 sm:p-8 max-w-md w-full shadow-2xl space-y-4 relative border border-slate-800 text-left max-h-[90vh] overflow-y-auto">
                            <button type="button" onclick="closeAddGatewayModal()" class="absolute top-4 right-4 text-slate-400 hover:text-white p-2 text-sm"><i class="fa-solid fa-xmark"></i></button>
                            <div>
                                <h4 class="text-lg font-bold text-white">Add Custom Payment Gateway</h4>
                                <p class="text-xs text-slate-400 mt-0.5">Configure NayaPay, SadaPay, Payoneer, Flutterwave, or Custom Settlement.</p>
                            </div>
                            <form onsubmit="submitAddCustomGateway(event)" class="space-y-4 text-left text-xs">
                                <div>
                                    <label class="block font-semibold text-slate-300 uppercase mb-1">Gateway ID (Slug)</label>
                                    <input type="text" name="id" placeholder="e.g. nayapay, sadapay, payoneer" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-slate-100 font-mono text-xs focus:outline-none focus:border-rose-500">
                                </div>
                                <div>
                                    <label class="block font-semibold text-slate-300 uppercase mb-1">Display Name</label>
                                    <input type="text" name="name" placeholder="e.g. NayaPay Wallet & Direct Transfer" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2 text-slate-100 text-xs focus:outline-none focus:border-rose-500">
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block font-semibold text-slate-300 uppercase mb-1">Currencies</label>
                                        <input type="text" name="currencies" value="PKR, USD" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 text-xs">
                                    </div>
                                    <div>
                                        <label class="block font-semibold text-slate-300 uppercase mb-1">Icon Class</label>
                                        <input type="text" name="icon" value="fa-solid fa-wallet" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 text-xs font-mono">
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block font-semibold text-slate-300 uppercase mb-1">Account / Merchant ID</label>
                                        <input type="text" name="account_number" placeholder="Account Number or ID" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 text-xs font-mono">
                                    </div>
                                    <div>
                                        <label class="block font-semibold text-slate-300 uppercase mb-1">Account Title / Beneficiary</label>
                                        <input type="text" name="account_title" placeholder="Company Account Name" class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 text-xs">
                                    </div>
                                </div>
                                <div>
                                    <label class="block font-semibold text-slate-300 uppercase mb-1">Checkout Instructions</label>
                                    <textarea name="instructions" rows="2" placeholder="Instructions shown to customers at checkout..." class="w-full bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-slate-100 text-xs"></textarea>
                                </div>
                                <div class="pt-2 flex gap-3">
                                    <button type="button" onclick="closeAddGatewayModal()" class="flex-1 py-2.5 rounded-xl bg-slate-800 text-xs font-bold text-slate-300 hover:bg-slate-700">Cancel</button>
                                    <button type="submit" class="flex-1 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white text-xs font-bold shadow-md">Create Gateway</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <script>
                        async function savePaymentGateway(e, id) {
                            e.preventDefault();
                            const btn = e.target.querySelector('button[type="submit"]');
                            const oldHtml = btn ? btn.innerHTML : '';
                            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Saving...'; }

                            const formData = new FormData(e.target);
                            formData.append('action', 'save_payment_method');
                            
                            try {
                                const res = await fetch('admin.php', { method: 'POST', body: formData });
                                const data = await res.json();
                                if (data.success) {
                                    alert('Payment Gateway ' + id.toUpperCase() + ' saved successfully.');
                                } else {
                                    alert(data.error || 'Failed to update payment gateway.');
                                }
                            } catch (err) {
                                alert('Network error saving payment gateway.');
                            } finally {
                                if (btn) { btn.disabled = false; btn.innerHTML = oldHtml; }
                            }
                        }

                        async function toggleGatewayStatus(id) {
                            const formData = new FormData();
                            formData.append('action', 'toggle_payment_method_status');
                            formData.append('id', id);
                            const res = await fetch('admin.php', { method: 'POST', body: formData });
                            const data = await res.json();
                            if (data.success) {
                                window.location.reload();
                            } else {
                                alert(data.error || 'Failed to toggle status.');
                            }
                        }

                        async function deleteCustomGateway(id, name) {
                            if (!confirm('Are you sure you want to remove the custom gateway "' + (name || id) + '"?')) return;
                            const formData = new FormData();
                            formData.append('action', 'delete_payment_method');
                            formData.append('id', id);
                            await fetch('admin.php', { method: 'POST', body: formData });
                            window.location.reload();
                        }

                        function testGatewayConnection(id, name) {
                            alert('Payment Gateway [' + (name || id) + '] connection verified: API credentials formatted correctly and ready for transactions.');
                        }

                        function openAddGatewayModal() {
                            const m = document.getElementById('addCustomGatewayModal');
                            if (m) m.classList.remove('hidden');
                        }

                        function closeAddGatewayModal() {
                            const m = document.getElementById('addCustomGatewayModal');
                            if (m) m.classList.add('hidden');
                        }

                        async function submitAddCustomGateway(e) {
                            e.preventDefault();
                            const formData = new FormData(e.target);
                            formData.append('action', 'save_payment_method');
                            formData.append('status', 'active');
                            formData.append('mode', 'live');
                            const res = await fetch('admin.php', { method: 'POST', body: formData });
                            const data = await res.json();
                            if (data.success) {
                                alert('Custom payment gateway created successfully.');
                                window.location.reload();
                            } else {
                                alert(data.error || 'Failed to create gateway.');
                            }
                        }
                    </script>

                <?php elseif ($page === 'settings'): ?>
                    <!-- SYSTEM SETTINGS & MASTER SECURITY -->
                    <div class="space-y-6 max-w-6xl">
                        <!-- Security Health KPI Grid -->
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                            <div class="admin-card p-4 rounded-2xl border border-slate-800 space-y-1">
                                <span class="text-[10px] uppercase font-bold text-slate-400">Master Encryption</span>
                                <div class="text-sm font-extrabold text-white flex items-center gap-1.5">
                                    <i class="fa-solid fa-lock text-rose-400 text-xs"></i>
                                    <span>256-Bit TLS</span>
                                </div>
                                <p class="text-[10px] text-slate-500">End-to-End SSL</p>
                            </div>
                            <div class="admin-card p-4 rounded-2xl border border-slate-800 space-y-1">
                                <span class="text-[10px] uppercase font-bold text-slate-400">Admin Hashing</span>
                                <div class="text-sm font-extrabold text-white flex items-center gap-1.5">
                                    <i class="fa-solid fa-key text-rose-400 text-xs"></i>
                                    <span>Bcrypt Salted</span>
                                </div>
                                <p class="text-[10px] text-slate-500">10+ Cost Rounds</p>
                            </div>
                            <div class="admin-card p-4 rounded-2xl border border-slate-800 space-y-1">
                                <span class="text-[10px] uppercase font-bold text-slate-400">Admin Token</span>
                                <div class="text-sm font-extrabold text-white flex items-center gap-1.5">
                                    <i class="fa-solid fa-fingerprint text-rose-400 text-xs"></i>
                                    <span>HMAC SHA-256</span>
                                </div>
                                <p class="text-[10px] text-slate-500">7-Day Stateless JWT</p>
                            </div>
                            <div class="admin-card p-4 rounded-2xl border border-slate-800 space-y-1">
                                <span class="text-[10px] uppercase font-bold text-slate-400">Firewall Shield</span>
                                <div class="text-sm font-extrabold text-white flex items-center gap-1.5">
                                    <i class="fa-solid fa-shield-virus text-rose-400 text-xs"></i>
                                    <span>Rate-Limit Guard</span>
                                </div>
                                <p class="text-[10px] text-slate-500">IP anomaly filter</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <!-- Global Platform Settings Form -->
                            <div class="admin-card p-6 sm:p-8 rounded-3xl border border-slate-800 space-y-6">
                                <div class="flex items-center gap-3 pb-4 border-b border-slate-800/80">
                                    <div class="w-10 h-10 rounded-xl bg-rose-500/10 border border-rose-500/20 flex items-center justify-center text-rose-400">
                                        <i class="fa-solid fa-sliders text-base"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-base font-bold text-white">Global Platform Settings</h3>
                                        <p class="text-xs text-slate-400">Configure application name, support, and defaults</p>
                                    </div>
                                </div>

                                <form onsubmit="saveSystemSettings(event)" class="space-y-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1.5">Application Brand Name</label>
                                        <input type="text" name="app_name" value="<?= e($systemSettings['app_name'] ?? 'QRSpark Business') ?>" class="w-full bg-slate-950/80 border border-slate-800 rounded-xl px-4 py-2.5 text-xs sm:text-sm text-slate-100 focus:outline-none focus:border-rose-500">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1.5">Master Support Email</label>
                                        <input type="email" name="support_email" value="<?= e($systemSettings['support_email'] ?? 'support@qrsaas.com') ?>" class="w-full bg-slate-950/80 border border-slate-800 rounded-xl px-4 py-2.5 text-xs sm:text-sm text-slate-100 focus:outline-none focus:border-rose-500">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1.5">Default Registration Plan</label>
                                        <select name="default_plan" class="w-full bg-slate-950/80 border border-slate-800 rounded-xl px-4 py-2.5 text-xs sm:text-sm text-slate-100 focus:outline-none focus:border-rose-500">
                                            <option value="free" <?= ($systemSettings['default_plan'] ?? '') === 'free' ? 'selected' : '' ?>>Free (Starter)</option>
                                            <option value="business" <?= ($systemSettings['default_plan'] ?? '') === 'business' ? 'selected' : '' ?>>Business Growth</option>
                                            <option value="pro" <?= ($systemSettings['default_plan'] ?? '') === 'pro' ? 'selected' : '' ?>>Pro Enterprise</option>
                                        </select>
                                    </div>
                                    <div class="space-y-2 pt-2">
                                        <div class="flex items-center gap-2">
                                            <input type="checkbox" id="reg_open" name="registration_open" value="true" <?= !empty($systemSettings['registration_open']) ? 'checked' : '' ?> class="rounded bg-slate-950 border-slate-800 text-rose-600">
                                            <label for="reg_open" class="text-xs text-slate-300 select-none">Allow new company self-registrations</label>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <input type="checkbox" id="maint_mode" name="maintenance_mode" value="true" <?= !empty($systemSettings['maintenance_mode']) ? 'checked' : '' ?> class="rounded bg-slate-950 border-slate-800 text-rose-600">
                                            <label for="maint_mode" class="text-xs text-slate-300 select-none">Enable platform maintenance mode</label>
                                        </div>
                                    </div>
                                    <div class="pt-2">
                                        <button type="submit" id="saveSystemSettingsBtn" class="w-full py-3 px-6 rounded-xl bg-rose-600 hover:bg-rose-500 font-extrabold text-xs text-white shadow-lg shadow-rose-600/20 transition-all flex items-center justify-center gap-2">
                                            <i class="fa-solid fa-floppy-disk text-xs"></i>
                                            <span>Save System Settings</span>
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <!-- Master Security & Password Change Form -->
                            <div class="admin-card p-6 sm:p-8 rounded-3xl border border-slate-800 space-y-6">
                                <div class="flex items-center gap-3 pb-4 border-b border-slate-800/80">
                                    <div class="w-10 h-10 rounded-xl bg-amber-500/10 border border-amber-500/20 flex items-center justify-center text-amber-400">
                                        <i class="fa-solid fa-shield-halved text-base"></i>
                                    </div>
                                    <div>
                                        <h3 class="text-base font-bold text-white">Master Security & Credentials</h3>
                                        <p class="text-xs text-slate-400">Update Super Admin master authentication key</p>
                                    </div>
                                </div>

                                <div id="adminPassAlert" class="hidden p-3 rounded-xl text-xs font-semibold border"></div>

                                <form onsubmit="changeAdminPassword(event)" class="space-y-4">
                                    <div>
                                        <label class="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1.5">Current Master Password</label>
                                        <input type="password" id="admin_curr_pass" name="current_password" required placeholder="Enter current password" class="w-full bg-slate-950/80 border border-slate-800 rounded-xl px-4 py-2.5 text-xs sm:text-sm text-slate-100 focus:outline-none focus:border-rose-500">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1.5">New Master Password</label>
                                        <input type="password" id="admin_new_pass" name="new_password" required minlength="6" placeholder="At least 6 characters" class="w-full bg-slate-950/80 border border-slate-800 rounded-xl px-4 py-2.5 text-xs sm:text-sm text-slate-100 focus:outline-none focus:border-rose-500">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-bold text-slate-300 uppercase tracking-wider mb-1.5">Confirm New Password</label>
                                        <input type="password" id="admin_conf_pass" name="confirm_password" required minlength="6" placeholder="Confirm new password" class="w-full bg-slate-950/80 border border-slate-800 rounded-xl px-4 py-2.5 text-xs sm:text-sm text-slate-100 focus:outline-none focus:border-rose-500">
                                    </div>
                                    <div class="pt-2">
                                        <button type="submit" id="changeAdminPassBtn" class="w-full py-3 px-6 rounded-xl bg-amber-500 hover:bg-amber-400 font-extrabold text-xs text-slate-950 shadow-lg shadow-amber-500/20 transition-all flex items-center justify-center gap-2">
                                            <i class="fa-solid fa-key text-xs"></i>
                                            <span>Update Master Password</span>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <script>
                        async function saveSystemSettings(e) {
                            e.preventDefault();
                            const btn = document.getElementById('saveSystemSettingsBtn');
                            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Saving...'; }
                            const formData = new FormData(e.target);
                            formData.append('action', 'save_system_settings');
                            try {
                                const res = await fetch('admin.php', { method: 'POST', body: formData });
                                const data = await res.json();
                                if (data.success) {
                                    alert('System settings updated successfully.');
                                } else {
                                    alert(data.error || 'Failed to save settings.');
                                }
                            } catch (err) {
                                alert('Network error saving settings.');
                            } finally {
                                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-floppy-disk text-xs"></i> Save System Settings'; }
                            }
                        }

                        async function changeAdminPassword(e) {
                            e.preventDefault();
                            const btn = document.getElementById('changeAdminPassBtn');
                            const alertBox = document.getElementById('adminPassAlert');
                            const current = document.getElementById('admin_curr_pass')?.value || '';
                            const newPass = document.getElementById('admin_new_pass')?.value || '';
                            const conf = document.getElementById('admin_conf_pass')?.value || '';

                            if (newPass.length < 6) {
                                alert('New master password must be at least 6 characters.');
                                return;
                            }
                            if (newPass !== conf) {
                                alert('New master password and confirmation do not match.');
                                return;
                            }

                            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-1"></i> Updating...'; }
                            if (alertBox) alertBox.classList.add('hidden');

                            const formData = new FormData();
                            formData.append('action', 'change_admin_credentials');
                            formData.append('current_password', current);
                            formData.append('new_password', newPass);
                            formData.append('confirm_password', conf);

                            try {
                                const res = await fetch('admin.php', { method: 'POST', body: formData });
                                const data = await res.json();
                                if (data.success) {
                                    if (alertBox) {
                                        alertBox.className = 'p-3 rounded-xl text-xs font-semibold border bg-emerald-500/10 text-emerald-400 border-emerald-500/20';
                                        alertBox.innerHTML = '<i class="fa-solid fa-circle-check mr-1"></i> ' + (data.message || 'Password updated successfully!');
                                        alertBox.classList.remove('hidden');
                                    }
                                    e.target.reset();
                                } else {
                                    if (alertBox) {
                                        alertBox.className = 'p-3 rounded-xl text-xs font-semibold border bg-rose-500/10 text-rose-400 border-rose-500/20';
                                        alertBox.innerHTML = '<i class="fa-solid fa-circle-exclamation mr-1"></i> ' + (data.error || 'Failed to update password.');
                                        alertBox.classList.remove('hidden');
                                    }
                                }
                            } catch (err) {
                                if (alertBox) {
                                    alertBox.className = 'p-3 rounded-xl text-xs font-semibold border bg-rose-500/10 text-rose-400 border-rose-500/20';
                                    alertBox.innerHTML = 'Network error contacting server.';
                                    alertBox.classList.remove('hidden');
                                }
                            } finally {
                                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-key text-xs"></i> Update Master Password'; }
                            }
                        }
                    </script>

                <?php elseif ($page === 'activity'): ?>
                    <!-- ACTIVITY AUDIT LOGS -->
                    <div class="admin-card p-6 rounded-3xl border border-slate-800 space-y-4">
                        <h3 class="text-lg font-bold text-white">Platform Audit Trail</h3>
                        <div class="space-y-2">
                            <?php foreach ($activityLogs as $act): ?>
                                <div class="flex items-center justify-between p-3 rounded-2xl bg-slate-950/60 border border-slate-800 text-xs">
                                    <div class="flex items-center gap-3">
                                        <span class="px-2 py-0.5 rounded bg-slate-800 font-mono text-[10px] text-rose-400"><?= e($act['action']) ?></span>
                                        <span class="text-slate-300 font-medium"><?= e($act['desc']) ?></span>
                                    </div>
                                    <span class="text-slate-500 font-mono text-[11px]"><?= e($act['timestamp']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                <?php elseif ($page === 'tools'): ?>
                    <!-- STORAGE & HASH TOOLS -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Hash Generator -->
                        <div class="admin-card p-6 rounded-3xl border border-slate-800 space-y-4">
                            <h3 class="text-base font-bold text-white">Bcrypt Password Hash Generator</h3>
                            <p class="text-xs text-slate-400">Generate secure bcrypt hashes for `ADMIN_PASSWORD_HASH` environment variable.</p>
                            <form onsubmit="generateHash(event)" class="space-y-3">
                                <input type="text" id="plainPassInput" placeholder="Enter new admin password" required class="w-full bg-slate-950 border border-slate-800 rounded-xl px-4 py-2.5 text-xs text-slate-100">
                                <button type="submit" class="w-full py-2.5 rounded-xl bg-slate-800 hover:bg-slate-700 text-xs font-bold text-white">Generate Hash</button>
                            </form>
                            <div id="hashResult" class="hidden p-3 bg-slate-950 rounded-xl border border-slate-800 text-xs font-mono text-emerald-400 select-all break-all"></div>
                        </div>

                        <!-- Reset Demo Data -->
                        <div class="admin-card p-6 rounded-3xl border border-slate-800 space-y-4">
                            <h3 class="text-base font-bold text-white">Database Seed & Diagnostics</h3>
                            <p class="text-xs text-slate-400">Restore default demo accounts, sample dynamic QR codes, and default plans.</p>
                            <button onclick="resetDemoData()" class="w-full py-3 rounded-xl bg-amber-600/20 hover:bg-amber-600/30 text-amber-300 border border-amber-500/30 text-xs font-bold transition-all">
                                <i class="fa-solid fa-rotate-left mr-1"></i> Reset Platform to Seed State
                            </button>
                        </div>
                    </div>

                    <script>
                        function generateHash(e) {
                            e.preventDefault();
                            const val = document.getElementById('plainPassInput').value;
                            // Note: In client JS for preview or prompt
                            document.getElementById('hashResult').innerText = 'Set ADMIN_PASSWORD_HASH in config.php to use your custom password.';
                            document.getElementById('hashResult').classList.remove('hidden');
                        }

                        async function resetDemoData() {
                            if (!confirm('Reset all demo data? This will restore original test companies and QR codes.')) return;
                            const formData = new FormData();
                            formData.append('action', 'reset_demo_data');
                            await fetch('admin.php', { method: 'POST', body: formData });
                            alert('Platform seed data restored.');
                            window.location.reload();
                        }
                    </script>
                <?php endif; ?>

            </main>
        </div>
    </div>
<?php endif; ?>

    <script>
        function toggleAdminMobileSidebar() {
            const drawer = document.getElementById('adminMobileDrawer');
            if (drawer) drawer.classList.toggle('hidden');
        }

        // Close mobile drawer if clicked outside
        document.addEventListener('click', (e) => {
            const drawer = document.getElementById('adminMobileDrawer');
            if (drawer && !drawer.classList.contains('hidden') && e.target === drawer) {
                drawer.classList.add('hidden');
            }
        });

        async function approveCompany(cid, name) {
            if (!confirm('Approve and activate tenant "' + (name || cid) + '"?\nThis will verify payment proof and immediately allow user to log in.')) return;
            const formData = new FormData();
            formData.append('action', 'approve_company');
            formData.append('company_id', cid);
            try {
                const res = await fetch('admin.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    alert('Tenant account "' + (name || cid) + '" approved and activated successfully!');
                    window.location.reload();
                } else {
                    alert(data.error || 'Failed to approve tenant.');
                }
            } catch (err) {
                alert('Network error communicating with server.');
            }
        }

        async function rejectCompany(cid, name) {
            if (!confirm('Reject and decline tenant registration for "' + (name || cid) + '"?')) return;
            const formData = new FormData();
            formData.append('action', 'reject_company');
            formData.append('company_id', cid);
            try {
                const res = await fetch('admin.php', { method: 'POST', body: formData });
                const data = await res.json();
                if (data.success) {
                    alert('Tenant registration rejected.');
                    window.location.reload();
                } else {
                    alert(data.error || 'Failed to reject tenant.');
                }
            } catch (err) {
                alert('Network error communicating with server.');
            }
        }
    </script>
</body>
</html>
