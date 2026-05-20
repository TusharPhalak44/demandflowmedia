<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','director','manager_director','sales_director','operations_director']);
ensureCsrfToken();
ensureDatabaseSchema();

$user = getCurrentUser();
$conn = getDbConnection();

$monthStr = isset($_GET['month']) ? (string)$_GET['month'] : date('Y-m');
if (!preg_match('/^(\d{4})-(\d{2})$/', $monthStr, $m)) {
    http_response_code(400);
    echo 'Invalid month';
    exit;
}
$year = (int)$m[1];
$month = (int)$m[2];
$start = sprintf('%04d-%02d-01', $year, $month);
$end = date('Y-m-t', strtotime($start));

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = 'Invalid token.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'add_expense') {
            $expenseDate = trim((string)($_POST['expense_date'] ?? ''));
            $category = trim((string)($_POST['category'] ?? ''));
            $desc = trim((string)($_POST['description'] ?? ''));
            $amount = (float)($_POST['amount'] ?? 0);
            $currency = trim((string)($_POST['currency'] ?? 'INR'));

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expenseDate)) {
                $message = 'Invalid date.';
                $messageType = 'danger';
            } elseif ($category === '' || $amount <= 0) {
                $message = 'Category and Amount are required.';
                $messageType = 'danger';
            } else {
                $createdBy = (int)($user['id'] ?? 0);
                $stmt = $conn->prepare("INSERT INTO revenue_manual_expenses (expense_date, category, description, amount, currency, created_by) VALUES (?,?,?,?,?,?)");
                if ($stmt) {
                    $stmt->bind_param('sssdsi', $expenseDate, $category, $desc, $amount, $currency, $createdBy);
                    $ok = $stmt->execute();
                    $stmt->close();
                    $message = $ok ? 'Expense added.' : 'Failed to add expense.';
                    $messageType = $ok ? 'success' : 'danger';
                } else {
                    $message = 'Database error.';
                    $messageType = 'danger';
                }
            }
        } elseif ($action === 'delete_expense') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $message = 'Invalid expense.';
                $messageType = 'danger';
            } else {
                $stmt = $conn->prepare("DELETE FROM revenue_manual_expenses WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('i', $id);
                    $ok = $stmt->execute();
                    $stmt->close();
                    $message = $ok ? 'Expense deleted.' : 'Failed to delete expense.';
                    $messageType = $ok ? 'success' : 'danger';
                } else {
                    $message = 'Database error.';
                    $messageType = 'danger';
                }
            }
        }
    }
}

$rows = [];
$stmt = $conn->prepare("SELECT e.* FROM revenue_manual_expenses e WHERE e.expense_date BETWEEN ? AND ? ORDER BY e.expense_date DESC, e.id DESC");
if ($stmt) {
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$total = 0.0;
foreach ($rows as $r) $total += (float)($r['amount'] ?? 0);

$pageTitle = 'Revenue Expenses';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="h3 mb-1">Manual Expenses</div>
            <div class="text-muted small">Month: <?php echo htmlspecialchars($monthStr); ?></div>
        </div>
        <form method="get" class="d-flex gap-2 align-items-end">
            <div>
                <label class="form-label small text-muted mb-1">Month</label>
                <input class="form-control form-control-sm" name="month" value="<?php echo htmlspecialchars($monthStr); ?>" placeholder="YYYY-MM">
            </div>
            <div>
                <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-funnel me-1"></i>Apply</button>
            </div>
        </form>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header fw-semibold">Add Extra Expense</div>
        <div class="card-body">
            <form method="post" class="row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="add_expense">
                <div class="col-md-2">
                    <label class="form-label small text-muted">Date</label>
                    <input class="form-control form-control-sm" type="date" name="expense_date" value="<?php echo htmlspecialchars(date('Y-m-d')); ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small text-muted">Category</label>
                    <input class="form-control form-control-sm" name="category" placeholder="Office / Tools / Travel" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">Description</label>
                    <input class="form-control form-control-sm" name="description" placeholder="Optional">
                </div>
                <div class="col-md-1">
                    <label class="form-label small text-muted">Amount</label>
                    <input class="form-control form-control-sm" type="number" step="0.01" min="0" name="amount" required>
                </div>
                <div class="col-md-1">
                    <label class="form-label small text-muted">Currency</label>
                    <input class="form-control form-control-sm" name="currency" value="INR">
                </div>
                <div class="col-12">
                    <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-plus-circle me-1"></i>Add</button>
                    <a class="btn btn-light border btn-sm" href="revenue-dashboard.php?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="fw-semibold">Expenses List</div>
            <div class="text-muted small">Total: INR <?php echo number_format($total, 2); ?></div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th class="text-end">Amount</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No expenses in this month.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td class="font-monospace"><?php echo htmlspecialchars((string)($r['expense_date'] ?? '')); ?></td>
                                <td class="fw-semibold"><?php echo htmlspecialchars((string)($r['category'] ?? '')); ?></td>
                                <td class="text-muted small"><?php echo htmlspecialchars((string)($r['description'] ?? '')); ?></td>
                                <td class="text-end"><?php echo htmlspecialchars(((string)($r['currency'] ?? 'INR')) . ' ' . number_format((float)($r['amount'] ?? 0), 2)); ?></td>
                                <td class="text-end">
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this expense?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action" value="delete_expense">
                                        <input type="hidden" name="id" value="<?php echo (int)($r['id'] ?? 0); ?>">
                                        <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
