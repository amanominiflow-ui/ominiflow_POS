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
// Performs server-side authentication handshake & cookie injection
// -----------------------------------------------------------------------------
$loginSuccess = false;
$postCookies = [];
$targetUrl = 'https://whatsapp.ominiflow.com/dashboard';

try {
    $cookieFile = tempnam(sys_get_temp_dir(), 'of_sso_');
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    // Step 1: Fetch login page to retrieve CSRF token and initial session cookie
    $ch = curl_init('https://whatsapp.ominiflow.com/login');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_USERAGENT => $ua,
    ]);
    $initResp = (string) curl_exec($ch);

    preg_match_all('/^Set-Cookie:\s*([^=]+)=([^;]+)/mi', $initResp, $initMatches, PREG_SET_ORDER);
    $initialCookies = [];
    foreach ($initMatches as $im) {
        $initialCookies[trim($im[1])] = trim($im[2]);
    }

    preg_match('/name="_token" value="([^"]+)"/', $initResp, $tm);
    $csrfToken = $tm[1] ?? '';

    if ($csrfToken !== '') {
        $cookieHeader = [];
        foreach ($initialCookies as $ck => $cv) {
            $cookieHeader[] = "$ck=$cv";
        }

        // Step 2: Post login credentials
        curl_setopt_array($ch, [
            CURLOPT_URL => 'https://whatsapp.ominiflow.com/login',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                '_token' => $csrfToken,
                'email' => $ashEmail,
                'password' => $ashPassword,
                'remember' => 'on',
            ]),
            CURLOPT_HTTPHEADER => ['Cookie: ' . implode('; ', $cookieHeader)],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 12,
        ]);
        $loginResp = (string) curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode === 302 || $httpCode === 200) {
            preg_match_all('/^Set-Cookie:\s*([^=]+)=([^;]+)/mi', $loginResp, $postMatches, PREG_SET_ORDER);
            foreach ($postMatches as $pm) {
                $postCookies[trim($pm[1])] = trim($pm[2]);
            }
            if (!empty($postCookies['ominiflow_session'])) {
                $loginSuccess = true;
            }
        }
    }
    curl_close($ch);
    @unlink($cookieFile);
} catch (Throwable $e) {
    $loginSuccess = false;
}

// Check if running on live ominiflow.com domain (e.g. pos.ominiflow.com)
$httpHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$isLiveOminiflowDomain = str_contains($httpHost, 'ominiflow.com');

if ($loginSuccess && $isLiveOminiflowDomain) {
    // Set cookies for .ominiflow.com so they apply to whatsapp.ominiflow.com
    $expireTime = time() + (86400 * 30); // 30 days
    foreach ($postCookies as $cName => $cVal) {
        setcookie($cName, $cVal, [
            'expires' => $expireTime,
            'path' => '/',
            'domain' => '.ominiflow.com',
            'secure' => true,
            'httponly' => ($cName !== 'XSRF-TOKEN'),
            'samesite' => 'Lax',
        ]);
    }
    // Redirect directly to WhatsApp dashboard
    header("Location: $targetUrl");
    exit;
}

// Fallback / Development screen (e.g. on localhost or if direct domain cookies cannot be set)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connecting to OminiFlow...</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 0;
            background: linear-gradient(135deg, #f0fdf4 0%, #e0e7ff 100%);
            color: #1e293b;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .card {
            background: #ffffff;
            padding: 36px 32px;
            border-radius: 16px;
            box-shadow: 0 12px 35px rgba(16, 185, 129, 0.12);
            border: 1px solid rgba(16, 185, 129, 0.2);
            text-align: center;
            max-width: 460px;
            width: 100%;
        }
        .logo-icon {
            width: 52px;
            height: 52px;
            margin: 0 auto 16px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #ffffff;
            box-shadow: 0 8px 16px rgba(16, 185, 129, 0.25);
        }
        h2 { margin: 0 0 6px; font-size: 21px; font-weight: 700; color: #064e3b; }
        p { margin: 0; font-size: 13.5px; color: #64748b; line-height: 1.5; }
        .spinner {
            width: 28px;
            height: 28px;
            border: 3px solid #bbf7d0;
            border-top-color: #10b981;
            border-radius: 50%;
            animation: spin 0.75s linear infinite;
            margin: 18px auto;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .cred-box {
            margin-top: 20px;
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            padding: 14px 16px;
            text-align: left;
            font-size: 13px;
        }
        .cred-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 4px 0;
        }
        .cred-label { color: #64748b; font-weight: 600; font-size: 12px; }
        .cred-val { font-family: ui-monospace, monospace; font-weight: 700; color: #0f172a; }
        .btn-primary {
            display: block;
            width: 100%;
            margin-top: 18px;
            background: #10b981;
            color: #ffffff;
            text-decoration: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
            border: none;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
        }
        .btn-primary:hover { background: #059669; }
        .btn-copy {
            background: #e2e8f0;
            color: #334155;
            border: none;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            margin-left: 8px;
        }
        .btn-copy:hover { background: #cbd5e1; }
        .toast {
            display: none;
            margin-top: 10px;
            font-size: 12px;
            color: #059669;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo-icon">
            <svg width="26" height="26" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
            </svg>
        </div>
        <h2>OminiFlow WhatsApp API</h2>
        <p>Signing in as <strong>Ash Collective</strong>...</p>

        <?php if ($loginSuccess): ?>
            <div class="spinner"></div>
            <p style="font-size: 12px; color: #10b981; font-weight: 600;">✓ Authentication token verified successfully.</p>
        <?php else: ?>
            <div style="margin: 14px 0;">
                <span style="font-size: 12px; color: #64748b;">Direct portal credentials:</span>
            </div>
        <?php endif; ?>

        <div class="cred-box">
            <div class="cred-row">
                <span class="cred-label">Login Email:</span>
                <div>
                    <span class="cred-val"><?= htmlspecialchars($ashEmail, ENT_QUOTES, 'UTF-8') ?></span>
                    <button type="button" class="btn-copy" onclick="copyText('<?= htmlspecialchars($ashEmail, ENT_QUOTES, 'UTF-8') ?>')">Copy</button>
                </div>
            </div>
            <div class="cred-row" style="margin-top: 6px;">
                <span class="cred-label">Password:</span>
                <div>
                    <span class="cred-val"><?= htmlspecialchars($ashPassword, ENT_QUOTES, 'UTF-8') ?></span>
                    <button type="button" class="btn-copy" onclick="copyText('<?= htmlspecialchars($ashPassword, ENT_QUOTES, 'UTF-8') ?>')">Copy</button>
                </div>
            </div>
        </div>
        <div id="copyToast" class="toast">✓ Copied to clipboard!</div>

        <a href="<?= htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-primary" id="launchBtn">
            Open WhatsApp Dashboard &rarr;
        </a>
    </div>

    <script>
        function copyText(txt) {
            navigator.clipboard.writeText(txt).then(function() {
                var t = document.getElementById('copyToast');
                if (t) {
                    t.style.display = 'block';
                    setTimeout(function() { t.style.display = 'none'; }, 2000);
                }
            });
        }

        <?php if ($loginSuccess): ?>
        // If handshake succeeded, auto-navigate
        setTimeout(function() {
            window.location.href = <?= json_encode($targetUrl) ?>;
        }, 1200);
        <?php endif; ?>
    </script>
</body>
</html>
