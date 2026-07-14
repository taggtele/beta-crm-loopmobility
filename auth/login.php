<?php
/**
 * Login Page - Enterprise Version
 * Consolidated PHP logic and UI.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../system_logs/log_helper.php';

app_session_start();
require_guest($pdo);

$pageTitle = 'Login | CRM Portal';
$pageHeading = 'Welcome Back';
$pageDescription = 'Access your workspace securely.';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $userId = trim($_POST['user_id'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($userId === '' || $password === '') {
        $error = 'Please enter user ID and password.';
    } elseif (!valid_user_id($userId)) {
        $error = 'Please enter a valid user ID.';
    } else {
        $stmt = $pdo->prepare(
            'SELECT id, name, user_id, password, role, status, deleted
             FROM users
             WHERE user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([':user_id' => $userId]);
        $user = $stmt->fetch();

        $isValidPassword = false;
        $needsUpgrade = false;

        if ($user && (int) $user['deleted'] === 0) {
            if (password_verify($password, $user['password'])) {
                $isValidPassword = true;
            } elseif (old_password_matches($password, $user['password'])) {
                $isValidPassword = true;
                $needsUpgrade = true;
            }
        }

        if (!$user || !$isValidPassword || (int) $user['deleted'] === 1) {
            $error = 'Invalid user ID or password.';
            log_login_activity($pdo, 0, $userId, 'FAILED');
        } elseif ($user['status'] !== 'Active') {
            $error = 'Your account is suspended. Please contact admin.';
            log_login_activity($pdo, (int) $user['id'], (string) $user['name'], 'FAILED');
        } else {
            if ($needsUpgrade) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $upgradeStmt = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
                $upgradeStmt->execute([':password' => $newHash, ':id' => $user['id']]);
            }

            session_regenerate_id(true);
            $_SESSION['user_pk'] = (int) $user['id'];
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['role'] = $user['role'];

            require_once __DIR__ . '/../includes/rbac.php';
            log_login_activity($pdo, (int) $user['id'], (string) $user['name'], 'SUCCESS');
            if (($user['role'] ?? '') === 'Finance') {
                redirect(rbac_finance_home_path());
            }
            redirect('dashboard/index.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --dark-blue: #0f172a;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --bg: #f1f5f9;
        }
        
        body, html {
            height: 100%;
            font-family: "Inter", "Segoe UI", -apple-system, BlinkMacSystemFont, Roboto, sans-serif;
            background: var(--bg);
        }
        
        .login-layout {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 2rem;
        }
        
        .login-card {
            display: flex;
            width: 100%;
            max-width: 1100px;
            background: #fff;
            border-radius: 28px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08), 0 1px 3px rgba(0, 0, 0, 0.04);
            overflow: hidden;
        }
        
        .side-branding {
            width: 42%;
            background: linear-gradient(160deg, #a8bdf9 0%, #3557c7 50%, #0f172a 100%);
            color: white;
            padding: 3.5rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }
        
        .side-branding::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.05) 1px, transparent 1px);
            background-size: 30px 30px;
            opacity: 0.3;
            pointer-events: none;
        }
        
        .branding-top { position: relative; z-index: 1; }
        .branding-bottom { position: relative; z-index: 2; }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 3rem;
        }
        
        .logo-icon {
            width: 40px;
            height: 40px;
            background: rgba(255,255,255,0.15);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .logo-img {
            height: 60px;
            width: auto;
            object-fit: contain;

        }
        
        .logo-text {
            font-size: 1.25rem;
            font-weight: 600;
            letter-spacing: -0.01em;
        }
        
        .branding-subtitle {
            color: #f8f8f8;
            font-size: 1rem;
            font-weight: 500;
            margin-bottom: 1.5rem;
            display: block;
        }
        
        .branding-headline {
            font-size: 2rem;
            line-height: 1.2;
            font-weight: 700;
            margin-bottom: 1rem;
            letter-spacing: -0.02em;
        }
        
        .branding-desc {
            color: #cbd5e1;
            font-size: 1rem;
            line-height: 1.6;
            margin-bottom: 2.5rem;
        }
        
        .dashboard-illustration {
            width: 100%;
            max-width: 420px;
            margin-bottom: 2rem;
        }
        
        .features-grid {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            position: relative;
            z-index: 2;
        }
        
        .feature-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 1rem;
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            backdrop-filter: blur(10px);
        }
        
        .feature-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: rgba(255,255,255,0.1);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .feature-content h4 {
            font-size: 0.9rem;
            font-weight: 600;
            margin-bottom: 2px;
        }
        
        .feature-content p {
            font-size: 0.8rem;
            color: #94a3b8;
            margin: 0;
        }
        
        .side-form {
            width: 58%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 4rem 3.5rem;
            background: #fff;
        }
        
        .form-header {
            margin-bottom: 2rem;
        }
        
        .form-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 0.5rem;
            letter-spacing: -0.02em;
        }
        
        .form-header p {
            color: var(--text-muted);
            font-size: 0.95rem;
        }
        
        .form-group {
            margin-bottom: 1.25rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            font-size: 0.875rem;
            color: var(--text-main);
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .input-wrapper svg.input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            color: #94a3b8;
            pointer-events: none;
        }
        
        .input-wrapper input {
            width: 100%;
            padding: 0.75rem 0.75rem 0.75rem 2.75rem;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 0.95rem;
            transition: all 0.2s;
            background: #fff;
        }
        
        .input-wrapper input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        .password-toggle {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
            color: #94a3b8;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: color 0.2s;
        }
        
        .password-toggle:hover {
            color: var(--text-main);
        }
        
        .form-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }
        
        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .checkbox-wrapper input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
            cursor: pointer;
        }
        
        .checkbox-wrapper label {
            margin: 0;
            font-size: 0.875rem;
            color: var(--text-muted);
            cursor: pointer;
        }
        
        .forgot-link {
            font-size: 0.875rem;
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }
        
        .forgot-link:hover {
            text-decoration: underline;
        }
        
        .btn-primary {
            width: 100%;
            padding: 0.875rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }
        
        .divider {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin: 1.5rem 0;
            color: var(--text-muted);
            font-size: 0.875rem;
        }
        
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }
        
        .btn-sso {
            width: 100%;
            padding: 0.875rem;
            background: white;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-weight: 500;
            font-size: 0.95rem;
            cursor: pointer;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            transition: all 0.2s;
        }
        
        .btn-sso:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }
        
        .error-msg {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            padding: 0.875rem 1rem;
            border-radius: 10px;
            margin-bottom: 1.25rem;
            font-size: 0.875rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .error-msg svg {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }
        
        .footer-note {
            margin-top: 2.5rem;
            text-align: center;
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        
        .footer-note a {
            color: var(--text-muted);
            text-decoration: none;
        }
        
        @media (max-width: 900px) {
            .login-layout {
                padding: 1rem;
            }
            .side-branding {
                display: none;
            }
            .side-form {
                width: 100%;
                padding: 2rem;
            }
            .login-card {
                max-width: 100%;
                animation: slideUpMobile 0.8s cubic-bezier(0.16, 1, 0.3, 1);
            }
        }

        /* Entrance Animations */
        .login-layout {
            animation: pageFadeIn 0.6s ease-out;
        }

        .login-card {
            animation: cardEntrance 0.9s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .side-branding {
            animation: slideInLeft 0.9s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .side-form {
            animation: slideInRight 0.9s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes pageFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes cardEntrance {
            from { opacity: 0; transform: translateY(40px) scale(0.97); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        @keyframes slideInLeft {
            from { opacity: 0; transform: translateX(-30px); }
            to { opacity: 1; transform: translateX(0); }
        }

        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(30px); }
            to { opacity: 1; transform: translateX(0); }
        }

        /* Branding Elements Stagger */
        .branding-top > *,
        .dashboard-illustration,
        .feature-item {
            opacity: 0;
            animation: fadeUp 0.7s ease-out forwards;
        }

        .logo { animation-delay: 0.1s; }
        .branding-subtitle { animation-delay: 0.2s; }
        .branding-headline { animation-delay: 0.3s; }
        .branding-desc { animation-delay: 0.4s; }
        .dashboard-illustration { animation-delay: 0.5s; }
        .feature-item:nth-child(1) { animation-delay: 0.6s; }
        .feature-item:nth-child(2) { animation-delay: 0.7s; }
        .feature-item:nth-child(3) { animation-delay: 0.8s; }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(14px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Form Elements Stagger */
        .side-form > div > .form-header,
        .side-form > div > form,
        .side-form > div > .divider,
        .side-form > div > .btn-sso,
        .side-form > div > .footer-note {
            opacity: 0;
            animation: fadeUp 0.7s ease-out forwards;
        }

        .side-form > div > .form-header { animation-delay: 0.15s; }
        .side-form > div > form { animation-delay: 0.25s; }
        .side-form > div > .divider { animation-delay: 0.4s; }
        .side-form > div > .btn-sso { animation-delay: 0.5s; }
        .side-form > div > .footer-note { animation-delay: 0.6s; }

        /* Smooth Transitions */
        .input-wrapper input {
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .input-wrapper input:focus {
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .btn-primary {
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .btn-primary:hover {
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .feature-item {
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .feature-item:hover {
            background: rgba(255,255,255,0.1);
            transform: translateX(4px);
        }

        /* Error Shake */
        .error-msg {
            opacity: 0;
            animation: shake 0.5s ease-out, fadeUp 0.7s ease-out 0.15s forwards;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20% { transform: translateX(-5px); }
            40% { transform: translateX(5px); }
            60% { transform: translateX(-3px); }
            80% { transform: translateX(3px); }
        }

        /* Password Toggle Interaction */
        .password-toggle svg {
            transition: transform 0.2s ease;
        }

        .password-toggle:active svg {
            transform: scale(0.85);
        }

        /* Floating Dashboard Illustration */
        .dashboard-illustration svg {
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        /* Button Press Effects */
        .btn-primary:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.25);
        }

        .btn-sso:active {
            transform: scale(0.98);
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateX(40px); }
            to { opacity: 1; transform: translateX(0); }
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="login-layout">
        <div class="login-card">
            <!-- LEFT SIDE: BRANDING -->
            <div class="side-branding">
                <div class="branding-top">
                    <div class="logo">
                        <img src="https://loopmobility.com.au/wp-content/uploads/2025/08/loop-logo-1-300x181.png" alt="CRM Portal" class="logo-img">
                        <span class="logo-text">CRM Portal</span>
                    </div>
                    
                    <span class="branding-subtitle">Support Desk</span>
                    <h2 class="branding-headline">Smart Support.<br>Faster Resolution.</h2>
                    <p class="branding-desc">Access your workspace securely.</p>
                </div>
                
                <div class="branding-bottom">
                    <div class="dashboard-illustration">
                        <svg viewBox="0 0 420 220" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <rect width="420" height="220" rx="16" fill="rgba(255,255,255,0.05)"/>
                            <rect width="420" height="36" rx="16" fill="rgba(255,255,255,0.08)"/>
                            <rect y="36" width="420" height="4" fill="rgba(255,255,255,0.08)"/>
                            <circle cx="16" cy="18" r="4" fill="#f87171" opacity="0.8"/>
                            <circle cx="28" cy="18" r="4" fill="#fbbf24" opacity="0.8"/>
                            <circle cx="40" cy="18" r="4" fill="#34d399" opacity="0.8"/>
                            <rect x="16" y="48" width="100" height="156" rx="8" fill="rgba(255,255,255,0.06)"/>
                            <rect x="24" y="60" width="84" height="8" rx="4" fill="rgba(255,255,255,0.15)"/>
                            <rect x="24" y="80" width="60" height="8" rx="4" fill="rgba(59,130,246,0.4)"/>
                            <rect x="24" y="100" width="72" height="8" rx="4" fill="rgba(255,255,255,0.15)"/>
                            <rect x="24" y="120" width="48" height="8" rx="4" fill="rgba(255,255,255,0.15)"/>
                            <rect x="132" y="48" width="272" height="60" rx="8" fill="rgba(255,255,255,0.06)"/>
                            <rect x="148" y="64" width="120" height="8" rx="4" fill="rgba(255,255,255,0.2)"/>
                            <rect x="148" y="80" width="180" height="8" rx="4" fill="rgba(255,255,255,0.15)"/>
                            <rect x="148" y="120" width="20" height="58" rx="4" fill="rgba(59,130,246,0.6)"/>
                            <rect x="180" y="100" width="20" height="78" rx="4" fill="rgba(59,130,246,0.8)"/>
                            <rect x="212" y="112" width="20" height="66" rx="4" fill="rgba(59,130,246,0.4)"/>
                            <rect x="244" y="132" width="20" height="46" rx="4" fill="rgba(59,130,246,0.3)"/>
                            <rect x="132" y="116" width="272" height="88" rx="8" fill="rgba(255,255,255,0.06)"/>
                            <rect x="148" y="132" width="100" height="8" rx="4" fill="rgba(255,255,255,0.2)"/>
                            <circle cx="340" cy="180" r="24" fill="rgba(59,130,246,0.2)"/>
                            <circle cx="340" cy="180" r="16" fill="rgba(59,130,246,0.4)"/>
                            <circle cx="340" cy="180" r="8" fill="rgba(59,130,246,0.8)"/>
                        </svg>
                    </div>
                    
                    <div class="features-grid">
                        <div class="feature-item">
                            <div class="feature-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                </svg>
                            </div>
                            <div class="feature-content">
                                <h4>Secure Access</h4>
                                <p>Protected authentication</p>
                            </div>
                        </div>
                        
                        <div class="feature-item">
                            <div class="feature-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
                                </svg>
                            </div>
                            <div class="feature-content">
                                <h4>Reliable Platform</h4>
                                <p>High availability and performance</p>
                            </div>
                        </div>
                        
                        <div class="feature-item">
                            <div class="feature-icon">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                    <circle cx="9" cy="7" r="4"/>
                                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                                </svg>
                            </div>
                            <div class="feature-content">
                                <h4>Trusted Environment</h4>
                                <p>Security-focused access control</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- RIGHT SIDE: FORM -->
            <div class="side-form">
                <div style="width: 100%; max-width: 360px;">
                    <div class="form-header">
                        <h1><?php echo $pageHeading; ?></h1>
                        <p><?php echo $pageDescription; ?></p>
                    </div>
                    
                    <?php if ($error !== ''): ?>
                        <div class="error-msg">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="12" y1="8" x2="12" y2="12"/>
                                <line x1="12" y1="16" x2="12.01" y2="16"/>
                            </svg>
                            <?php echo e($error); ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" novalidate id="loginForm">
                        <?php echo csrf_field(); ?>
                        
                        <div class="form-group">
                            <label for="user_id">User ID</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                    <circle cx="12" cy="7" r="4"/>
                                </svg>
                                <input type="text" id="user_id" name="user_id" value="<?php echo e($_POST['user_id'] ?? ''); ?>" required placeholder="Enter your user ID" autocomplete="username">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="password">Password</label>
                            <div class="input-wrapper">
                                <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                                </svg>
                                <input type="password" id="password" name="password" required placeholder="Enter your password" autocomplete="current-password">
                                <button type="button" class="password-toggle" id="togglePassword" aria-label="Show password">
                                    <svg id="eyeIcon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                        <circle cx="12" cy="12" r="3"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <div class="checkbox-wrapper">
                                <input type="checkbox" id="remember" name="remember">
                                <label for="remember">Remember Me</label>
                            </div>
                            <!-- <a href="#" class="forgot-link">Forgot Password?</a> -->
                        </div>
                        
                        <button type="submit" class="btn-primary">Sign In</button>
                    </form>
                    
                    <div class="divider">or</div>
                    
                    <button class="btn-sso">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                        </svg>
                        Sign in with SSO
                    </button>
                    
                    <div class="footer-note">
                        <div>🔐Authorized users only. Access may be monitored and logged.</div>
                        <div>© 2026 LoopMobility. All rights reserved.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        const toggleBtn = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const eyeIcon = document.getElementById('eyeIcon');
        
        toggleBtn.addEventListener('click', function() {
            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';
            toggleBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            
            if (isPassword) {
                eyeIcon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
            } else {
                eyeIcon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
            }
        });

        const card = document.querySelector('.login-card');
        if (card) {
            card.addEventListener('mousemove', function(e) {
                const rect = card.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                const centerX = rect.width / 2;
                const centerY = rect.height / 2;
                const rotateX = ((y - centerY) / centerY) * -2;
                const rotateY = ((x - centerX) / centerX) * 2;
                card.style.transform = `perspective(1000px) rotateX(${rotateX}deg) rotateY(${rotateY}deg)`;
            });
            card.addEventListener('mouseleave', function() {
                card.style.transform = 'perspective(1000px) rotateX(0) rotateY(0)';
                card.style.transition = 'transform 0.5s ease';
            });
            card.addEventListener('mouseenter', function() {
                card.style.transition = 'none';
            });
        }

        const loginForm = document.getElementById('loginForm');
        if (loginForm) {
            loginForm.addEventListener('submit', function() {
                if (!navigator.geolocation) {
                    return;
                }

                const locToast = document.createElement('div');
                locToast.style.cssText = 'position:fixed;bottom:24px;right:24px;z-index:99999;max-width:360px;padding:12px 16px;border-radius:10px;background:#f1f5f9;border:1px solid #e2e8f0;color:#334155;font-size:0.85rem;font-family:inherit;box-shadow:0 8px 24px rgba(0,0,0,0.12);display:flex;align-items:center;gap:10px;animation:slideIn 0.3s ease-out;';
                locToast.innerHTML = '<span style="display:inline-flex;width:16px;height:16px;border:2px solid #cbd5e1;border-top-color:#2563eb;border-radius:50%;animation:spin 0.8s linear infinite;flex-shrink:0;"></span><span>Detecting your location...</span>';
                document.body.appendChild(locToast);

                navigator.geolocation.getCurrentPosition(function(position) {
                    locToast.innerHTML = '<span style="color:#10b981;font-weight:700;flex-shrink:0;">✓</span><span>Location captured</span>';
                    setTimeout(function() { locToast.remove(); }, 2500);

                    fetch('<?php echo e(url('system_logs/ajax/update_location.php')); ?>', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            csrf_token: '<?php echo e(csrf_token()); ?>',
                            lat: position.coords.latitude,
                            lng: position.coords.longitude
                        })
                    }).catch(function() {
                        locToast.innerHTML = '<span style="color:#ef4444;font-weight:700;flex-shrink:0;">!</span><span>Location update failed</span>';
                        setTimeout(function() { locToast.remove(); }, 2500);
                    });
                }, function(error) {
                    locToast.remove();
                }, {
                    timeout: 8000,
                    maximumAge: 60000
                });
            });
        }
    </script>
</body>
</html>