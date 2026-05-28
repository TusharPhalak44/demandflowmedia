<?php
/**
 * Login Page
 * 
 * Handles user authentication and login
 */

// Include authentication system
require_once __DIR__ . '/../../includes/auth.php';

// Check if user is already logged in
if (isLoggedIn()) {
    // Redirect based on role
    $user = getCurrentUser();
    switch ($user['role']) {
        case 'admin':
            header("Location: ../dashboard/admin-dashboard");
            break;
        case 'director':
        case 'manager_director':
            header("Location: ../dashboard/admin-dashboard");
            break;
        case 'qa':
        case 'qa_agent':
        case 'qa_manager':
        case 'qa_director':
            header("Location: ../qa/dashboard");
            break;
        case 'agent':
        case 'operations_agent':
        case 'operations_manager':
        case 'operations_director':
            header("Location: ../dashboard/operations-dashboard");
            break;
        case 'form_filler':
        case 'email_marketing_executive':
        case 'email_marketing_agent':
        case 'email_marketing_manager':
        case 'email_marketing_director':
            header("Location: ../dashboard/email-marketing-dashboard");
            break;
        case 'sales_director':
        case 'sales_manager':
        case 'sdr':
            header("Location: ../dashboard/sales-dashboard");
            break;
        case 'vendor_admin':
        case 'vendor_user':
            header("Location: ../dashboard/vendor-dashboard");
            break;
        case 'client_admin':
        case 'client_sdr':
            header("Location: ../dashboard/client-dashboard");
            break;
        default:
            header("Location: ../../");
    }
    exit;
}

// Process login form submission
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Username and password are required';
    } else {
        $user = loginUser($username, $password);
        
        if ($user) {
            // Redirect based on role
            switch ($user['role']) {
                case 'admin':
                    header("Location: ../dashboard/admin-dashboard");
                    break;
                case 'director':
                case 'manager_director':
                    header("Location: ../dashboard/admin-dashboard");
                    break;
                case 'qa':
                case 'qa_agent':
                case 'qa_manager':
                case 'qa_director':
                    header("Location: ../qa/dashboard");
                    break;
                case 'agent':
                case 'operations_agent':
                case 'operations_manager':
                case 'operations_director':
                    header("Location: ../dashboard/operations-dashboard");
                    break;
                case 'form_filler':
                case 'email_marketing_executive':
                case 'email_marketing_agent':
                case 'email_marketing_manager':
                case 'email_marketing_director':
                    header("Location: ../dashboard/email-marketing-dashboard");
                    break;
                case 'sales_director':
                case 'sales_manager':
                case 'sdr':
                    header("Location: ../dashboard/sales-dashboard");
                    break;
                case 'vendor_admin':
                case 'vendor_user':
                    header("Location: ../dashboard/vendor-dashboard");
                    break;
                case 'client_admin':
                case 'client_sdr':
                    header("Location: ../dashboard/client-dashboard");
                    break;
                default:
                    header("Location: ../../");
            }
            exit;
        } else {
            // Provide clearer error messages based on reason set by auth
            $error = 'Invalid username or password';
            if (isset($_SESSION['login_error'])) {
                switch ($_SESSION['login_error']) {
                    case 'too_many_attempts':
                        $error = 'Too many login attempts. Please wait 15 minutes and try again.';
                        break;
                    case 'ip_restricted':
                        $ctx = is_array($_SESSION['ip_restricted_context'] ?? null) ? $_SESSION['ip_restricted_context'] : [];
                        $ipTxt = trim((string)($ctx['ip'] ?? ''));
                        $error = $ipTxt !== '' ? ('Access restricted for your IP: ' . $ipTxt . '. Please contact the administrator.') : 'Access restricted for your IP. Please contact the administrator.';
                        unset($_SESSION['ip_restricted_context']);
                        break;
                    case 'system_error':
                        $error = 'A system error occurred. Please contact the administrator.';
                        break;
                    case 'invalid_credentials':
                    default:
                        $error = 'Invalid username or password';
                }
                unset($_SESSION['login_error']);
            }
        }
    }
}
?>
<?php $pageTitle = 'Login'; include __DIR__ . '/../../includes/layout/auth_start.php'; ?>

<div class="row g-0 align-items-stretch">
  <div class="col-md-6 d-flex flex-column justify-content-center align-items-center bg-light-subtle" style="padding: 48px 24px;">
    <img src="../../assets/images/logos/DemandFlow-Media-logo.png" alt="DemandFlow Bridge" class="img-fluid" style="max-height: 120px; object-fit: contain;">
  </div>
  <div class="col-md-6 border-start-md" style="padding: 32px;">
    <div class="mb-4">
      <h4 class="fw-bold mb-1">Welcome Back</h4>
      <p class="text-muted small">Please sign in to your account to continue</p>
    </div>
    <?php if (!empty($error)): ?>
      <div class="alert alert-danger d-flex align-items-center gap-2 mb-3">
          <i class="bi bi-exclamation-triangle"></i>
          <div><?php echo htmlspecialchars($error); ?></div>
      </div>
    <?php endif; ?>
    <form method="post" action="">
        <div class="mb-3">
            <label for="username" class="form-label small text-muted">Username</label>
            <input type="text" class="form-control form-control-sm" id="username" name="username" required>
        </div>
        <div class="mb-3">
            <label for="password" class="form-label small text-muted">Password</label>
            <input type="password" class="form-control form-control-sm" id="password" name="password" required>
        </div>
        <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="1" id="rememberMe">
                <label class="form-check-label small" for="rememberMe">Remember me</label>
            </div>
            <a href="reset-password" class="small text-decoration-none">Forgot password?</a>
        </div>
        <button type="submit" class="btn btn-primary w-100">Sign In</button>
        <div class="text-center mt-3 small text-muted">
            Don’t have an account? <a href="register" class="text-decoration-none fw-semibold">Create one</a>
        </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../../includes/layout/auth_end.php'; ?>
