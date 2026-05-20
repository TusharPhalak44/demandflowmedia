<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// Require authentication
if (!isLoggedIn()) {
    header('Location: login');
    exit;
}

$currentUser = getCurrentUser();

// Only allow logged in users to reset their own password
// Admins can reset any password via user_id param
$userId = null;
$targetUsername = '';
$targetFullName = '';

if (isset($_GET['user_id'])) {
    // Admin-only: resetting someone else's password
    if (!isAdmin()) {
        header('Location: access-denied');
        exit;
    }
    $userId = (int)$_GET['user_id'];
    // Get user details
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT username, full_name FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && ($row = $result->fetch_assoc())) {
        $targetUsername = $row['username'];
        $targetFullName = $row['full_name'];
    } else {
        header('Location: ../users/manage-users');
        exit;
    }
    $stmt->close();
} else {
    // User is resetting their own password
    $userId = $currentUser['id'];
    $targetUsername = $currentUser['username'];
    $targetFullName = $currentUser['full_name'];
}

$message = '';
$messageType = '';

// CSRF protection token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $message = 'Invalid request token.';
        $messageType = 'danger';
    } else {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $currentPassword = $_POST['current_password'] ?? '';
    
    // Validate input
    if (empty($newPassword) || empty($confirmPassword)) {
        $message = 'Please fill in all password fields.';
        $messageType = 'danger';
    } else if ($newPassword !== $confirmPassword) {
        $message = 'New passwords do not match.';
        $messageType = 'danger';
    } else if (strlen($newPassword) < 8) {
        $message = 'Password must be at least 8 characters long.';
        $messageType = 'danger';
    } else {
        $conn = getDbConnection();

        // Non-admin users must verify current password
        if (!isAdmin() || !isset($_GET['user_id'])) {
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $stmt->close();
            if (!$row || !password_verify($currentPassword, $row['password'])) {
                $message = 'Current password is incorrect.';
                $messageType = 'danger';
            } else {
                // Update password
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $hashedPassword, $userId);
                if ($stmt->execute()) {
                    $message = "Password for $targetUsername has been reset successfully.";
                    $messageType = 'success';
                } else {
                    $message = 'Failed to update password. Please try again.';
                    $messageType = 'danger';
                }
                $stmt->close();
            }
        } else {
            // Admin resetting someone else's password: no current password check
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashedPassword, $userId);
            if ($stmt->execute()) {
                $message = "Password for $targetUsername has been reset successfully.";
                $messageType = 'success';
            } else {
                $message = 'Failed to update password. Please try again.';
                $messageType = 'danger';
            }
            $stmt->close();
        }
    }
    }
}
?>
<?php $pageTitle = 'Reset Password'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>

    <div class="container-fluid px-0">
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <?php if (isAdmin() && isset($_GET['user_id'])): ?>
                            <h4 class="mb-0">Reset Password for <?php echo htmlspecialchars($targetFullName); ?></h4>
                        <?php else: ?>
                            <h4 class="mb-0">Reset Your Password</h4>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            
                            <div class="mb-3">
                                <label for="new_password" class="form-label">New Password</label>
                                <input type="password" class="form-control form-control-sm" id="new_password" name="new_password" required>
                                <div class="form-text">Password must be at least 8 characters long.</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm New Password</label>
                                <input type="password" class="form-control form-control-sm" id="confirm_password" name="confirm_password" required>
                            </div>
                            <?php if (!isAdmin() || !isset($_GET['user_id'])): ?>
                            <div class="mb-3">
                                <label for="current_password" class="form-label">Current Password</label>
                                <input type="password" class="form-control form-control-sm" id="current_password" name="current_password" required>
                            </div>
                            <?php endif; ?>
                            
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary">Reset Password</button>
                                <?php if (isAdmin() && isset($_GET['user_id'])): ?>
                                    <a href="../users/manage-users.php" class="btn btn-secondary">Back to User Management</a>
                                <?php else: ?>
                                    <a href="<?php echo getRedirectUrl(); ?>" class="btn btn-secondary">Cancel</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>

<?php
// Helper function to determine where to redirect based on user role
function getRedirectUrl() {
    if (isAdmin()) {
        return '../dashboard/admin-dashboard.php';
    } else if (isQA()) {
        return '../qa/dashboard';
    } else if (isAgent()) {
        return '../dashboard/operations-dashboard.php';
    } else if (isFormFiller()) {
        return '../dashboard/email-marketing-dashboard.php';
    } else {
        return 'login.php';
    }
}
