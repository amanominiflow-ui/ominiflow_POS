<?php
/**
 * One-Click SSO Login Gateway for OminiFlow POS
 * Exclusively provides 1-click access to OminiFlow WhatsApp & HRMS for client Ash Collective (ID: 60077667007).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/organization_ids.php';

if (!is_authenticated()) {
    if (!headers_sent()) {
        header('Location: ' . asset('login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? 'dashboard.php')));
    } else {
        echo "<script>window.location.href=" . json_encode(asset('login.php')) . ";</script>";
    }
    exit;
}

$user = current_user();
$business = current_business();

$userIdLabel = function_exists('pos_public_user_id')
    ? pos_public_user_id((int) ($user['id'] ?? 0))
    : (string) (60077667000 + (int) ($user['id'] ?? 0));

$ashUserEmail = strtolower(trim((string) ($user['email'] ?? '')));
$ashUserName = strtolower(trim((string) ($user['name'] ?? '')));
$ashBizName = strtolower(trim((string) ($business['name'] ?? '')));
$ashBizEmail = strtolower(trim((string) ($business['email'] ?? '')));
$ashPublicId = (string) ($userIdLabel ?? '');
$ashDbPublicId = (string) ($user['public_id'] ?? '');

$hasAshIdentity = (
    $ashUserEmail === 'ashcollective1@gmail.com'
    || str_contains($ashUserEmail, 'ashcollective')
    || str_contains($ashBizName, 'ash collective')
    || str_contains($ashUserName, 'ash collective')
    || str_contains($ashBizEmail, 'ashcollective')
);

$hasAshUserId = (
    $ashPublicId === '60077667007'
    || $ashDbPublicId === '60077667007'
    || (int) ($user['id'] ?? 0) === 7
);

$isAshCollective = false;
if ($hasAshIdentity) {
    $isAshCollective = true;
} elseif ($hasAshUserId && (str_contains($ashUserEmail, 'ash') || str_contains($ashBizName, 'ash') || str_contains($ashUserName, 'ash'))) {
    $isAshCollective = true;
} elseif (function_exists('is_super_admin') && is_super_admin($user) && (!empty($_GET['preview_ash']) || !empty($_SESSION['preview_ash']))) {
    $isAshCollective = true;
}

if (!$isAshCollective) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Access Denied - OminiFlow</title>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f8fafc; color: #1e293b; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
            .card { background: #fff; padding: 32px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.06); text-align: center; max-width: 420px; }
            h2 { color: #dc2626; margin-top: 0; font-size: 20px; }
            p { font-size: 14px; color: #64748b; line-height: 1.5; }
            a { display: inline-block; margin-top: 16px; background: #3b82f6; color: #fff; text-decoration: none; padding: 8px 18px; border-radius: 6px; font-weight: 600; font-size: 13px; }
        </style>
    </head>
    <body>
        <div class="card">
            <h2>Access Restricted</h2>
            <p>One-click portal login is restricted to authorized organization accounts (Ash Collective).</p>
            <a href="<?= asset('dashboard.php') ?>">&larr; Return to Dashboard</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$service = strtolower(trim((string) ($_GET['service'] ?? 'ominiflow')));

// Credentials for Ash Collective
$ashEmail = 'ashcollective1@gmail.com';
$ashPassword = 'Ash@123';

// -----------------------------------------------------------------------------
// SERVICE 1: HRMS (https://hr.ominiflow.com/Client/dashboard)
// Direct auto-submitting POST to https://hr.ominiflow.com/Client/login
// -----------------------------------------------------------------------------
if ($service === 'hrms') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Connecting to OminiFlow HRMS...</title>
        <style>
            * { box-sizing: border-box; }
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                margin: 0;
                background: linear-gradient(145deg, #faf5ff 0%, #f3e8ff 50%, #fdf4ff 100%);
                color: #1e293b;
                display: flex;
                align-items: center;
                justify-content: center;
                height: 100vh;
                padding: 16px;
            }
            .card {
                background: #ffffff;
                padding: 36px 32px;
                border-radius: 16px;
                box-shadow: 0 10px 30px rgba(102, 0, 102, 0.1);
                border: 1px solid rgba(230, 0, 230, 0.2);
                text-align: center;
                max-width: 440px;
                width: 100%;
            }
            .logo-icon {
                width: 52px;
                height: 52px;
                margin: 0 auto 18px;
                background: linear-gradient(135deg, #a855f7 0%, #7e22ce 100%);
                border-radius: 14px;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #ffffff;
                box-shadow: 0 8px 16px rgba(126, 34, 206, 0.25);
            }
            .spinner {
                width: 28px;
                height: 28px;
                border: 3px solid #e9d5ff;
                border-top-color: #7e22ce;
                border-radius: 50%;
                animation: spin 0.75s linear infinite;
                margin: 16px auto;
            }
            @keyframes spin { to { transform: rotate(360deg); } }
            h2 { margin: 0 0 6px; font-size: 20px; font-weight: 700; color: #3b0764; }
            p { margin: 0; font-size: 13.5px; color: #6b7280; line-height: 1.5; }
            .btn {
                display: inline-block;
                margin-top: 18px;
                background: #7e22ce;
                color: #ffffff;
                text-decoration: none;
                padding: 10px 22px;
                border-radius: 8px;
                font-weight: 600;
                font-size: 13px;
                border: none;
                cursor: pointer;
                transition: background 0.2s;
            }
            .btn:hover { background: #6b21a8; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="logo-icon">
                <svg width="26" height="26" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
            </div>
            <h2>Connecting to HRMS</h2>
            <p>Signing in as <strong>Ash Collective</strong> to manage staff, attendance, and payroll...</p>
            <div class="spinner"></div>

            <form id="hrmsForm" method="POST" action="https://hr.ominiflow.com/Client/login">
                <input type="hidden" name="email" value="<?= htmlspecialchars($ashEmail, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="password" value="<?= htmlspecialchars($ashPassword, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="remember" value="1">
                <noscript>
                    <button type="submit" class="btn">Click here to sign in</button>
                </noscript>
            </form>

            <button type="button" class="btn" onclick="document.getElementById('hrmsForm').submit();" style="margin-top: 10px; font-size: 12px; padding: 7px 16px; background: #9333ea;">
                Click here if not redirected in 3 seconds &rarr;
            </button>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var form = document.getElementById('hrmsForm');
                if (form) {
                    form.submit();
                }
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

// -----------------------------------------------------------------------------
// SERVICE 2: OMINIFLOW (https://whatsapp.ominiflow.com/dashboard)
// Direct seamless 1-click SSO handshake & cookie injection
// -----------------------------------------------------------------------------
function perform_whatsapp_sso_handshake(string $email, string $password): array {
    $cookieJar = tempnam(sys_get_temp_dir(), 'of_wa_sso_');
    
    // Step 1: GET /login to retrieve CSRF token and initial session cookie
    $ch = curl_init('https://whatsapp.ominiflow.com/login');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    $html = curl_exec($ch);
    curl_close($ch);

    if (!$html) {
        @unlink($cookieJar);
        return ['success' => false, 'cookies' => []];
    }

    preg_match('/name="_token"\s+value="([^"]+)"/', $html, $m);
    $token = $m[1] ?? '';
    if (!$token) {
        @unlink($cookieJar);
        return ['success' => false, 'cookies' => []];
    }

    // Step 2: POST /login with credentials and remember=on
    $ch = curl_init('https://whatsapp.ominiflow.com/login');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        '_token' => $token,
        'email' => $email,
        'password' => $password,
        'remember' => 'on'
    ]));
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    curl_close($ch);

    @unlink($cookieJar);

    preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $headers, $cookieMatches);
    $cookies = [];
    if (!empty($cookieMatches[1])) {
        foreach ($cookieMatches[1] as $c) {
            $parts = explode('=', $c, 2);
            if (count($parts) === 2) {
                $cookies[trim($parts[0])] = trim($parts[1]);
            }
        }
    }

    return [
        'success' => ($httpCode === 302 && isset($cookies['ominiflow_session'])),
        'cookies' => $cookies
    ];
}

$isOminiflowHost = (stripos($_SERVER['HTTP_HOST'] ?? '', 'ominiflow.com') !== false);

if ($isOminiflowHost) {
    try {
        $handshake = perform_whatsapp_sso_handshake($ashEmail, $ashPassword);
        if (!empty($handshake['cookies'])) {
            foreach ($handshake['cookies'] as $name => $val) {
                $ttl = (str_starts_with($name, 'remember_web_'))
                    ? (time() + 34560000) // 1 year remember token
                    : (time() + 7200);     // 2 hours session token
                $httpOnly = ($name !== 'XSRF-TOKEN');
                
                setcookie($name, $val, [
                    'expires' => $ttl,
                    'path' => '/',
                    'domain' => '.ominiflow.com',
                    'secure' => true,
                    'httponly' => $httpOnly,
                    'samesite' => 'Lax'
                ]);
            }
        }
    } catch (\Throwable $e) {
        // Fallback silently to direct redirect
        error_log('Ominiflow WhatsApp SSO error: ' . $e->getMessage());
    }
}

header("Location: https://whatsapp.ominiflow.com/dashboard");
exit;