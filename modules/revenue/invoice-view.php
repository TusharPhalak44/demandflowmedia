<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','director','manager_director','sales_director','operations_director']);
ensureCsrfToken();
ensureDatabaseSchema();

$conn = getDbConnection();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'Invalid invoice';
    exit;
}
$invoiceNoParam = isset($_GET['invoice_no']) ? trim((string)$_GET['invoice_no']) : '';
$invoiceNoParam = $invoiceNoParam !== '' ? substr($invoiceNoParam, 0, 50) : '';

$stmt = $conn->prepare("SELECT invoice_no FROM revenue_invoices WHERE id = ? LIMIT 1");
$invoiceNo = '';
if ($stmt) {
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $invoiceNo = (string)(($stmt->get_result()->fetch_assoc() ?: [])['invoice_no'] ?? '');
    $stmt->close();
}
if ($invoiceNo === '' && $invoiceNoParam !== '') {
    $stmt = $conn->prepare("SELECT id, invoice_no FROM revenue_invoices WHERE invoice_no = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $invoiceNoParam);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $id = (int)($row['id'] ?? $id);
        $invoiceNo = (string)($row['invoice_no'] ?? $invoiceNo);
    }
}
if ($invoiceNo === '') $invoiceNo = 'Invoice #' . $id;

$pageTitle = 'Invoice PDF';
include __DIR__ . '/../../includes/layout/app_start.php';
?>

<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="h3 mb-1"><?php echo htmlspecialchars($invoiceNo); ?></div>
            <div class="text-muted small">PDF preview</div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-light border btn-sm" href="invoice-edit?id=<?php echo (int)$id; ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
            <a class="btn btn-outline-primary btn-sm" href="invoice-pdf?id=<?php echo (int)$id; ?>&amp;invoice_no=<?php echo urlencode((string)$invoiceNo); ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Open</a>
            <a class="btn btn-light border btn-sm" href="invoice-pdf?id=<?php echo (int)$id; ?>&amp;download=1&amp;invoice_no=<?php echo urlencode((string)$invoiceNo); ?>" target="_blank" rel="noopener"><i class="bi bi-download me-1"></i>Download</a>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0" style="height: calc(100vh - 210px);">
            <iframe
                title="Invoice PDF"
                src="invoice-pdf?id=<?php echo (int)$id; ?>&amp;invoice_no=<?php echo urlencode((string)$invoiceNo); ?>"
                style="width:100%;height:100%;border:0;display:block;"
            ></iframe>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
