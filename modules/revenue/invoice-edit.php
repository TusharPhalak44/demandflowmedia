<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','director','manager_director','sales_director','operations_director']);
ensureCsrfToken();
ensureDatabaseSchema();

$user = getCurrentUser();
$conn = getDbConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'Invalid invoice';
    exit;
}

$loadInvoice = function(int $id) use ($conn): ?array {
    $stmt = $conn->prepare("SELECT * FROM revenue_invoices WHERE id = ? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
};

$loadItems = function(int $id) use ($conn): array {
    $stmt = $conn->prepare("SELECT * FROM revenue_invoice_items WHERE invoice_id = ? ORDER BY sort_order, id");
    if (!$stmt) return [];
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
    return $rows;
};

$invoice = $loadInvoice($id);
if (!$invoice) {
    http_response_code(404);
    echo 'Invoice not found';
    exit;
}
$items = $loadItems($id);

$defaults = null;
$billToProfiles = [];
$uid = (int)($user['id'] ?? 0);
if ($uid > 0) {
    $st = $conn->prepare("SELECT * FROM revenue_invoice_settings WHERE user_id = ? LIMIT 1");
    if ($st) {
        $st->bind_param('i', $uid);
        $st->execute();
        $defaults = $st->get_result()->fetch_assoc() ?: null;
        $st->close();
    }
    $st = $conn->prepare("SELECT id, label, client_code FROM revenue_invoice_billto_profiles WHERE user_id = ? ORDER BY updated_at DESC, id DESC");
    if ($st) {
        $st->bind_param('i', $uid);
        $st->execute();
        $billToProfiles = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $st->close();
    }
}

$useDefaults = isset($_GET['use_defaults']) && (string)$_GET['use_defaults'] === '1';
$autoDefaults = (!$useDefaults) && is_array($defaults) && trim((string)($invoice['bill_from_name'] ?? '')) === '' && trim((string)($invoice['bank_name'] ?? '')) === '';
if ($autoDefaults) $useDefaults = true;
$selectedProfileId = isset($_GET['billto_profile_id']) ? (int)$_GET['billto_profile_id'] : 0;
$selectedProfile = null;
if ($selectedProfileId > 0 && $uid > 0) {
    $st = $conn->prepare("SELECT * FROM revenue_invoice_billto_profiles WHERE id = ? AND user_id = ? LIMIT 1");
    if ($st) {
        $st->bind_param('ii', $selectedProfileId, $uid);
        $st->execute();
        $selectedProfile = $st->get_result()->fetch_assoc() ?: null;
        $st->close();
    }
}
if (!$selectedProfile && $uid > 0 && $selectedProfileId <= 0) {
    $clientCode = trim((string)($invoice['client_code'] ?? ''));
    if ($clientCode !== '' && trim((string)($invoice['bill_to_name'] ?? '')) === '' && trim((string)($invoice['bill_to_address'] ?? '')) === '' && trim((string)($invoice['bill_to_contacts'] ?? '')) === '') {
        $st = $conn->prepare("SELECT * FROM revenue_invoice_billto_profiles WHERE user_id = ? AND client_code = ? ORDER BY updated_at DESC, id DESC LIMIT 1");
        if ($st) {
            $st->bind_param('is', $uid, $clientCode);
            $st->execute();
            $selectedProfile = $st->get_result()->fetch_assoc() ?: null;
            $st->close();
            if ($selectedProfile) $selectedProfileId = (int)($selectedProfile['id'] ?? 0);
        }
    }
}

$clientContact = null;
if ((int)($invoice['client_id'] ?? 0) > 0) {
    $cid = (int)$invoice['client_id'];
    $st = $conn->prepare("SELECT name, email, phone FROM client_contacts WHERE client_id = ? ORDER BY id ASC LIMIT 1");
    if ($st) {
        $st->bind_param('i', $cid);
        $st->execute();
        $clientContact = $st->get_result()->fetch_assoc() ?: null;
        $st->close();
    }
}

$clientBilling = [];
if ((int)($invoice['client_id'] ?? 0) > 0) {
    $clientBilling = getClientBillingProfile((int)$invoice['client_id']);
}

$defaultBillFromName = 'Taraj Global Solutions Private Limited';
$defaultBillFromAddress = '';
$defaultBillFromCityState = '';
$defaultBillFromCountry = 'India';

$displayBillToName = (string)($invoice['bill_to_name'] ?? '');
$displayBillToAddress = (string)($invoice['bill_to_address'] ?? '');
$displayBillToContacts = (string)($invoice['bill_to_contacts'] ?? '');
if ($displayBillToContacts === '') {
    $bits = array_filter([
        (string)($invoice['bill_to_contact_name'] ?? ($clientContact['name'] ?? '')),
        (string)($invoice['bill_to_contact_email'] ?? ($clientContact['email'] ?? '')),
        (string)($invoice['bill_to_contact_phone'] ?? ($clientContact['phone'] ?? ''))
    ], fn($v) => trim((string)$v) !== '');
    if (!empty($bits)) $displayBillToContacts = implode("\n", $bits);
}
if ($selectedProfile) {
    $displayBillToName = (string)($selectedProfile['bill_to_name'] ?? $displayBillToName);
    $displayBillToAddress = (string)($selectedProfile['bill_to_address'] ?? $displayBillToAddress);
    $displayBillToContacts = (string)($selectedProfile['bill_to_contacts'] ?? $displayBillToContacts);
}

$displayBillFromName = (string)($invoice['bill_from_name'] ?? '');
$displayBillFromAddress = (string)($invoice['bill_from_address'] ?? '');
$displayBillFromCityState = (string)($invoice['bill_from_city_state'] ?? '');
$displayBillFromCountry = (string)($invoice['bill_from_country'] ?? '');
$displayBillFromEmail = (string)($invoice['bill_from_email'] ?? '');
$displayBillFromPhone = (string)($invoice['bill_from_phone'] ?? '');
$displayBankName = (string)($invoice['bank_name'] ?? '');
$displayAccountName = (string)($invoice['account_name'] ?? '');
$displayAccountNumber = (string)($invoice['account_number'] ?? '');
$displayIfsc = (string)($invoice['ifsc_code'] ?? '');
$displaySwift = (string)($invoice['swift_code'] ?? '');
$displayBenAddr = (string)($invoice['beneficiary_address'] ?? '');
$displayBenCity = (string)($invoice['beneficiary_city_state'] ?? '');

if ($useDefaults && is_array($defaults)) {
    if ($displayBillFromName === '') $displayBillFromName = (string)($defaults['bill_from_name'] ?? '');
    if ($displayBillFromAddress === '') $displayBillFromAddress = (string)($defaults['bill_from_address'] ?? '');
    if ($displayBillFromCityState === '') $displayBillFromCityState = (string)($defaults['bill_from_city_state'] ?? '');
    if ($displayBillFromCountry === '') $displayBillFromCountry = (string)($defaults['bill_from_country'] ?? '');
    if ($displayBillFromEmail === '') $displayBillFromEmail = (string)($defaults['bill_from_email'] ?? '');
    if ($displayBillFromPhone === '') $displayBillFromPhone = (string)($defaults['bill_from_phone'] ?? '');
    if ($displayBankName === '') $displayBankName = (string)($defaults['bank_name'] ?? '');
    if ($displayAccountName === '') $displayAccountName = (string)($defaults['account_name'] ?? '');
    if ($displayAccountNumber === '') $displayAccountNumber = (string)($defaults['account_number'] ?? '');
    if ($displayIfsc === '') $displayIfsc = (string)($defaults['ifsc_code'] ?? '');
    if ($displaySwift === '') $displaySwift = (string)($defaults['swift_code'] ?? '');
    if ($displayBenAddr === '') $displayBenAddr = (string)($defaults['beneficiary_address'] ?? '');
    if ($displayBenCity === '') $displayBenCity = (string)($defaults['beneficiary_city_state'] ?? '');
    if ((string)($invoice['signature_path'] ?? '') === '' && (string)($defaults['signature_path'] ?? '') !== '') {
        $invoice['signature_path'] = (string)$defaults['signature_path'];
    }
}
if ($displayBillFromName === '') $displayBillFromName = $defaultBillFromName;
if ($displayBillFromCountry === '') $displayBillFromCountry = $defaultBillFromCountry;

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = 'Invalid token.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_invoice') {
            $status = trim((string)($_POST['status'] ?? 'Draft'));
            $issueDate = trim((string)($_POST['issue_date'] ?? ''));
            $dueDate = trim((string)($_POST['due_date'] ?? ''));
            $currency = trim((string)($_POST['currency'] ?? 'USD'));
            $billToName = trim((string)($_POST['bill_to_name'] ?? ''));
            $billToAddress = trim((string)($_POST['bill_to_address'] ?? ''));
            $billToContacts = trim((string)($_POST['bill_to_contacts'] ?? ''));
            $billToContactName = '';
            $billToContactEmail = '';
            $billToContactPhone = '';

            $billFromName = trim((string)($_POST['bill_from_name'] ?? ''));
            $billFromAddress = trim((string)($_POST['bill_from_address'] ?? ''));
            $billFromCityState = trim((string)($_POST['bill_from_city_state'] ?? ''));
            $billFromCountry = trim((string)($_POST['bill_from_country'] ?? ''));
            $billFromEmail = trim((string)($_POST['bill_from_email'] ?? ''));
            $billFromPhone = trim((string)($_POST['bill_from_phone'] ?? ''));

            $bankName = trim((string)($_POST['bank_name'] ?? ''));
            $accountName = trim((string)($_POST['account_name'] ?? ''));
            $accountNumber = trim((string)($_POST['account_number'] ?? ''));
            $ifscCode = trim((string)($_POST['ifsc_code'] ?? ''));
            $swiftCode = trim((string)($_POST['swift_code'] ?? ''));
            $beneficiaryAddress = trim((string)($_POST['beneficiary_address'] ?? ''));
            $beneficiaryCityState = trim((string)($_POST['beneficiary_city_state'] ?? ''));

            $signaturePath = (string)($invoice['signature_path'] ?? '');

            $notes = trim((string)($_POST['notes'] ?? ''));
            $taxRate = (float)($_POST['tax_rate'] ?? 0);
            if ($taxRate < 0) $taxRate = 0;
            if ($taxRate > 100) $taxRate = 100;

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate)) {
                $message = 'Invalid issue date.';
                $messageType = 'danger';
            } else {
                $dueDateVal = null;
                if ($dueDate !== '') {
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
                        $message = 'Invalid due date.';
                        $messageType = 'danger';
                    } else {
                        $dueDateVal = $dueDate;
                    }
                }
            }

            $descs = isset($_POST['item_desc']) && is_array($_POST['item_desc']) ? $_POST['item_desc'] : [];
            $qtys = isset($_POST['item_qty']) && is_array($_POST['item_qty']) ? $_POST['item_qty'] : [];
            $rates = isset($_POST['item_rate']) && is_array($_POST['item_rate']) ? $_POST['item_rate'] : [];

            if ($messageType !== 'danger') {
                $newItems = [];
                $subtotal = 0.0;
                $count = max(count($descs), count($qtys), count($rates));
                for ($i = 0; $i < $count; $i++) {
                    $d = trim((string)($descs[$i] ?? ''));
                    $q = (float)($qtys[$i] ?? 0);
                    $r = (float)($rates[$i] ?? 0);
                    if ($d === '') continue;
                    if ($q <= 0) $q = 1;
                    $amt = $q * $r;
                    $subtotal += $amt;
                    $newItems[] = [
                        'description' => $d,
                        'qty' => $q,
                        'unit_price' => $r,
                        'amount' => $amt,
                        'sort_order' => (int)$i,
                    ];
                }
                if (empty($newItems)) {
                    $message = 'Add at least one item.';
                    $messageType = 'danger';
                } else {
                    $taxAmount = round(($subtotal * $taxRate) / 100.0, 2);
                    $total = $subtotal + $taxAmount;

                    if (!empty($_POST['remove_signature'])) {
                        $signaturePath = '';
                    }
                    if (isset($_FILES['signature_file']) && is_array($_FILES['signature_file']) && (int)($_FILES['signature_file']['error'] ?? 0) === UPLOAD_ERR_OK) {
                        $tmp = (string)($_FILES['signature_file']['tmp_name'] ?? '');
                        $name = (string)($_FILES['signature_file']['name'] ?? '');
                        $size = (int)($_FILES['signature_file']['size'] ?? 0);
                        if ($tmp !== '' && $size > 0 && $size <= 5_000_000) {
                            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                            $allowed = ['png','jpg','jpeg','webp'];
                            if (in_array($ext, $allowed, true)) {
                                $dir = __DIR__ . '/../../uploads/invoices/signatures';
                                if (!is_dir($dir)) @mkdir($dir, 0777, true);
                                $fileName = 'inv_' . $id . '_' . date('Ymd_His') . '.' . $ext;
                                $destAbs = $dir . '/' . $fileName;
                                if (move_uploaded_file($tmp, $destAbs)) {
                                    $signaturePath = 'uploads/invoices/signatures/' . $fileName;
                                }
                            }
                        }
                    }

                    $stmt = $conn->prepare("
                        UPDATE revenue_invoices
                        SET status = ?, issue_date = ?, due_date = ?, currency = ?,
                            bill_to_name = ?, bill_to_address = ?, bill_to_contact_name = ?, bill_to_contact_email = ?, bill_to_contact_phone = ?, bill_to_contacts = ?,
                            bill_from_name = ?, bill_from_address = ?, bill_from_city_state = ?, bill_from_country = ?, bill_from_email = ?, bill_from_phone = ?,
                            bank_name = ?, account_name = ?, account_number = ?, ifsc_code = ?, swift_code = ?, beneficiary_address = ?, beneficiary_city_state = ?,
                            signature_path = ?,
                            notes = ?, subtotal = ?, tax_rate = ?, tax_amount = ?, total = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    if (!$stmt) {
                        $message = 'Database error.';
                        $messageType = 'danger';
                    } else {
                        $stmt->bind_param(
                            'sssssssssssssssssssssssssddddi',
                            $status,
                            $issueDate,
                            $dueDateVal,
                            $currency,
                            $billToName,
                            $billToAddress,
                            $billToContactName,
                            $billToContactEmail,
                            $billToContactPhone,
                            $billToContacts,
                            $billFromName,
                            $billFromAddress,
                            $billFromCityState,
                            $billFromCountry,
                            $billFromEmail,
                            $billFromPhone,
                            $bankName,
                            $accountName,
                            $accountNumber,
                            $ifscCode,
                            $swiftCode,
                            $beneficiaryAddress,
                            $beneficiaryCityState,
                            $signaturePath,
                            $notes,
                            $subtotal,
                            $taxRate,
                            $taxAmount,
                            $total,
                            $id
                        );
                        $ok = $stmt->execute();
                        $stmt->close();
                        if ($ok) {
                            $del = $conn->prepare("DELETE FROM revenue_invoice_items WHERE invoice_id = ?");
                            if ($del) {
                                $del->bind_param('i', $id);
                                $del->execute();
                                $del->close();
                            }
                            $ins = $conn->prepare("INSERT INTO revenue_invoice_items (invoice_id, description, qty, unit_price, amount, sort_order) VALUES (?,?,?,?,?,?)");
                            if ($ins) {
                                foreach ($newItems as $it) {
                                    $desc = (string)$it['description'];
                                    $qty = (float)$it['qty'];
                                    $rate = (float)$it['unit_price'];
                                    $amt = (float)$it['amount'];
                                    $ord = (int)$it['sort_order'];
                                    $ins->bind_param('isdddi', $id, $desc, $qty, $rate, $amt, $ord);
                                    $ins->execute();
                                }
                                $ins->close();
                            }
                            $message = 'Invoice saved.';
                            $messageType = 'success';
                            $invoice = $loadInvoice($id) ?: $invoice;
                            $items = $loadItems($id);

                            if (!empty($_POST['save_defaults']) && $uid > 0) {
                                $stmt2 = $conn->prepare("
                                    INSERT INTO revenue_invoice_settings
                                    (user_id, bill_from_name, bill_from_address, bill_from_city_state, bill_from_country, bill_from_email, bill_from_phone,
                                     bank_name, account_name, account_number, ifsc_code, swift_code, beneficiary_address, beneficiary_city_state, signature_path)
                                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                                    ON DUPLICATE KEY UPDATE
                                        bill_from_name=VALUES(bill_from_name),
                                        bill_from_address=VALUES(bill_from_address),
                                        bill_from_city_state=VALUES(bill_from_city_state),
                                        bill_from_country=VALUES(bill_from_country),
                                        bill_from_email=VALUES(bill_from_email),
                                        bill_from_phone=VALUES(bill_from_phone),
                                        bank_name=VALUES(bank_name),
                                        account_name=VALUES(account_name),
                                        account_number=VALUES(account_number),
                                        ifsc_code=VALUES(ifsc_code),
                                        swift_code=VALUES(swift_code),
                                        beneficiary_address=VALUES(beneficiary_address),
                                        beneficiary_city_state=VALUES(beneficiary_city_state),
                                        signature_path=VALUES(signature_path),
                                        updated_at=NOW()
                                ");
                                if ($stmt2) {
                                    $stmt2->bind_param(
                                        'issssssssssssss',
                                        $uid,
                                        $billFromName,
                                        $billFromAddress,
                                        $billFromCityState,
                                        $billFromCountry,
                                        $billFromEmail,
                                        $billFromPhone,
                                        $bankName,
                                        $accountName,
                                        $accountNumber,
                                        $ifscCode,
                                        $swiftCode,
                                        $beneficiaryAddress,
                                        $beneficiaryCityState,
                                        $signaturePath
                                    );
                                    $stmt2->execute();
                                    $stmt2->close();
                                }
                            }

                            if (!empty($_POST['save_billto_template']) && $uid > 0) {
                                $label = trim((string)($_POST['billto_template_label'] ?? ''));
                                if ($label === '') $label = $billToName !== '' ? $billToName : ((string)($invoice['client_code'] ?? 'Bill To'));
                                $clientIdVal = (int)($invoice['client_id'] ?? 0);
                                $clientCodeVal = (string)($invoice['client_code'] ?? '');
                                $stmt3 = $conn->prepare("
                                    INSERT INTO revenue_invoice_billto_profiles
                                    (user_id, label, client_id, client_code, bill_to_name, bill_to_address, bill_to_contacts)
                                    VALUES (?,?,?,?,?,?,?)
                                    ON DUPLICATE KEY UPDATE
                                        client_id=VALUES(client_id),
                                        client_code=VALUES(client_code),
                                        bill_to_name=VALUES(bill_to_name),
                                        bill_to_address=VALUES(bill_to_address),
                                        bill_to_contacts=VALUES(bill_to_contacts),
                                        updated_at=NOW()
                                ");
                                if ($stmt3) {
                                    $stmt3->bind_param('isissss', $uid, $label, $clientIdVal, $clientCodeVal, $billToName, $billToAddress, $billToContacts);
                                    $stmt3->execute();
                                    $stmt3->close();
                                }
                            }
                        } else {
                            $message = 'Unable to save invoice.';
                            $messageType = 'danger';
                        }
                    }
                }
            }
        }
    }
}

$campaignName = '';
if ((int)($invoice['campaign_id'] ?? 0) > 0) {
    $st = $conn->prepare("SELECT name FROM campaigns WHERE id = ? LIMIT 1");
    if ($st) {
        $cid = (int)$invoice['campaign_id'];
        $st->bind_param('i', $cid);
        $st->execute();
        $campaignName = (string)(($st->get_result()->fetch_assoc() ?: [])['name'] ?? '');
        $st->close();
    }
}

$pageTitle = 'Edit Invoice';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="h3 mb-1">Invoice <?php echo htmlspecialchars((string)($invoice['invoice_no'] ?? '')); ?></div>
            <div class="text-muted small"><?php echo htmlspecialchars((string)($invoice['client_name'] ?? ($invoice['client_code'] ?? ''))); ?><?php echo $campaignName !== '' ? (' · ' . htmlspecialchars($campaignName)) : ''; ?></div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-light border btn-sm" href="invoices?month=<?php echo urlencode((string)($invoice['month_str'] ?? date('Y-m'))); ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
            <a class="btn btn-light border btn-sm" href="invoice-edit?id=<?php echo (int)$id; ?>&use_defaults=1"><i class="bi bi-clipboard-check me-1"></i>Use Defaults</a>
            <a class="btn btn-light border btn-sm" data-bs-toggle="collapse" href="#invoicePreviewCollapse" role="button" aria-expanded="false" aria-controls="invoicePreviewCollapse"><i class="bi bi-eye me-1"></i>Preview</a>
            <a class="btn btn-outline-primary btn-sm" href="invoice-pdf?id=<?php echo (int)$id; ?>&amp;download=1&amp;invoice_no=<?php echo urlencode((string)($invoice['invoice_no'] ?? '')); ?>" target="_blank" rel="noopener"><i class="bi bi-download me-1"></i>PDF</a>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="collapse mb-3" id="invoicePreviewCollapse">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-light fw-semibold d-flex justify-content-between align-items-center">
                <div>PDF Preview</div>
                <div class="d-flex gap-2">
                    <a class="btn btn-sm btn-light border" href="invoice-pdf?id=<?php echo (int)$id; ?>&amp;download=1&amp;invoice_no=<?php echo urlencode((string)($invoice['invoice_no'] ?? '')); ?>" target="_blank" rel="noopener"><i class="bi bi-download me-1"></i>Download</a>
                    <a class="btn btn-sm btn-light border" href="invoice-view?id=<?php echo (int)$id; ?>&amp;invoice_no=<?php echo urlencode((string)($invoice['invoice_no'] ?? '')); ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1"></i>Open</a>
                </div>
            </div>
            <div class="card-body p-0" style="height: 70vh;">
                <iframe title="Invoice PDF" src="invoice-pdf?id=<?php echo (int)$id; ?>&amp;invoice_no=<?php echo urlencode((string)($invoice['invoice_no'] ?? '')); ?>" style="width:100%;height:100%;border:0;display:block;"></iframe>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="fw-semibold">Saved Bill To</div>
            <div class="text-muted small">Select a saved template to prefill Bill To fields</div>
        </div>
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                <div class="col-md-8">
                    <label class="form-label small text-muted">Template</label>
                    <select class="form-select form-select-sm" name="billto_profile_id">
                        <option value="">—</option>
                        <?php foreach ($billToProfiles as $p): ?>
                            <option value="<?php echo (int)($p['id'] ?? 0); ?>" <?php echo ((int)($p['id'] ?? 0) === $selectedProfileId) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)($p['label'] ?? '')); ?><?php echo !empty($p['client_code']) ? (' · ' . htmlspecialchars((string)$p['client_code'])) : ''; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-grid">
                    <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-box-arrow-in-down me-1"></i>Load</button>
                </div>
            </form>
            <div class="d-flex justify-content-end mt-2">
                <a class="btn btn-light border btn-sm" href="billto-templates"><i class="bi bi-gear me-1"></i>Manage Templates</a>
            </div>
        </div>
    </div>

    <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="save_invoice">
        <div class="row g-3">
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-header fw-semibold">Header</div>
                    <div class="card-body">
                        <div class="mb-2">
                            <label class="form-label small text-muted">Status</label>
                            <select class="form-select form-select-sm" name="status">
                                <?php foreach (['Draft','Sent','Paid','Cancelled'] as $s): ?>
                                    <option value="<?php echo htmlspecialchars($s); ?>" <?php echo ((string)($invoice['status'] ?? 'Draft') === $s) ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label small text-muted">Issue Date</label>
                                <input class="form-control form-control-sm" type="date" name="issue_date" value="<?php echo htmlspecialchars((string)($invoice['issue_date'] ?? date('Y-m-d'))); ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small text-muted">Due Date</label>
                                <input class="form-control form-control-sm" type="date" name="due_date" value="<?php echo htmlspecialchars((string)($invoice['due_date'] ?? '')); ?>">
                            </div>
                        </div>
                        <div class="mt-2">
                            <label class="form-label small text-muted">Currency</label>
                            <input class="form-control form-control-sm" name="currency" value="<?php echo htmlspecialchars((string)($invoice['currency'] ?? 'USD')); ?>">
                        </div>
                        <div class="mt-2">
                            <label class="form-label small text-muted">Tax Rate (%)</label>
                            <input class="form-control form-control-sm" type="number" step="0.01" min="0" max="100" name="tax_rate" value="<?php echo htmlspecialchars((string)($invoice['tax_rate'] ?? 0)); ?>">
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mt-3">
                    <div class="card-header fw-semibold">Bill To</div>
                    <div class="card-body">
                        <div class="mb-2">
                            <label class="form-label small text-muted">Name</label>
                            <input class="form-control form-control-sm" name="bill_to_name" value="<?php echo htmlspecialchars($displayBillToName !== '' ? $displayBillToName : (string)($invoice['client_name'] ?? '')); ?>">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small text-muted">Contact Details</label>
                            <textarea class="form-control form-control-sm" name="bill_to_contacts" rows="3" placeholder="Email: a@x.com / b@y.com&#10;Phone: +91... / +1...&#10;Name: Person 1 / Person 2"><?php echo htmlspecialchars($displayBillToContacts); ?></textarea>
                            <div class="text-muted small mt-1">Use multiple lines or slash-separated values.</div>
                        </div>
                        <div>
                            <label class="form-label small text-muted">Address</label>
                            <textarea class="form-control form-control-sm" name="bill_to_address" rows="4"><?php echo htmlspecialchars($displayBillToAddress); ?></textarea>
                        </div>
                        <div class="mt-2">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" value="1" name="save_billto_template" id="saveBillToTpl">
                                <label class="form-check-label small text-muted" for="saveBillToTpl">Save Bill To as template</label>
                            </div>
                            <input class="form-control form-control-sm mt-2" name="billto_template_label" placeholder="Template name (optional)">
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mt-3">
                    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                        <span>Client Billing (Provided)</span>
                        <?php if ((int)($invoice['client_id'] ?? 0) > 0): ?>
                            <a class="btn btn-sm btn-light border" href="<?php echo htmlspecialchars(appBackUrl('../clients/client-billing.php?client_id=' . (int)$invoice['client_id'])); ?>"><i class="bi bi-box-arrow-up-right me-1"></i>Open</a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (empty($clientBilling)): ?>
                            <div class="text-muted small">No client billing profile saved.</div>
                        <?php else: ?>
                            <div class="row g-2">
                                <div class="col-12">
                                    <div class="fw-semibold"><?php echo htmlspecialchars((string)($clientBilling['billing_name'] ?? ($invoice['client_name'] ?? ''))); ?></div>
                                    <?php if (!empty($clientBilling['billing_email']) || !empty($clientBilling['billing_phone'])): ?>
                                        <div class="text-muted small">
                                            <?php echo htmlspecialchars(trim((string)($clientBilling['billing_email'] ?? ''))); ?>
                                            <?php if (!empty($clientBilling['billing_email']) && !empty($clientBilling['billing_phone'])): ?> · <?php endif; ?>
                                            <?php echo htmlspecialchars(trim((string)($clientBilling['billing_phone'] ?? ''))); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($clientBilling['billing_address'])): ?>
                                    <div class="col-12">
                                        <div class="small text-muted">Address</div>
                                        <div class="small"><?php echo nl2br(htmlspecialchars((string)$clientBilling['billing_address'])); ?></div>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($clientBilling['tax_id'])): ?>
                                    <div class="col-12">
                                        <div class="small text-muted">Tax ID / GST / VAT</div>
                                        <div class="small fw-semibold"><?php echo htmlspecialchars((string)$clientBilling['tax_id']); ?></div>
                                    </div>
                                <?php endif; ?>
                                <div class="col-12">
                                    <div class="small text-muted">Bank Details</div>
                                    <div class="small">
                                        <?php if (!empty($clientBilling['bank_name'])): ?><?php echo htmlspecialchars((string)$clientBilling['bank_name']); ?><br><?php endif; ?>
                                        <?php if (!empty($clientBilling['bank_account_name'])): ?><?php echo htmlspecialchars((string)$clientBilling['bank_account_name']); ?><br><?php endif; ?>
                                        <?php if (!empty($clientBilling['bank_account_number'])): ?><?php echo htmlspecialchars((string)$clientBilling['bank_account_number']); ?><br><?php endif; ?>
                                        <?php if (!empty($clientBilling['bank_ifsc_swift'])): ?><?php echo htmlspecialchars((string)$clientBilling['bank_ifsc_swift']); ?><br><?php endif; ?>
                                        <?php if (!empty($clientBilling['bank_iban'])): ?><?php echo htmlspecialchars((string)$clientBilling['bank_iban']); ?><?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mt-3">
                    <div class="card-header fw-semibold">Billed By (Company)</div>
                    <div class="card-body">
                        <div class="mb-2">
                            <label class="form-label small text-muted">Company Name</label>
                            <input class="form-control form-control-sm" name="bill_from_name" value="<?php echo htmlspecialchars($displayBillFromName); ?>">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small text-muted">Address</label>
                            <textarea class="form-control form-control-sm" name="bill_from_address" rows="3"><?php echo htmlspecialchars($displayBillFromAddress); ?></textarea>
                        </div>
                        <div class="row g-2">
                            <div class="col-12">
                                <label class="form-label small text-muted">City & State</label>
                                <input class="form-control form-control-sm" name="bill_from_city_state" value="<?php echo htmlspecialchars($displayBillFromCityState); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label small text-muted">Country</label>
                                <input class="form-control form-control-sm" name="bill_from_country" value="<?php echo htmlspecialchars($displayBillFromCountry); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label small text-muted">Email</label>
                                <input class="form-control form-control-sm" name="bill_from_email" value="<?php echo htmlspecialchars($displayBillFromEmail); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label small text-muted">Phone</label>
                                <input class="form-control form-control-sm" name="bill_from_phone" value="<?php echo htmlspecialchars($displayBillFromPhone); ?>">
                            </div>
                        </div>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" value="1" name="save_defaults" id="saveDefaults">
                            <label class="form-check-label small text-muted" for="saveDefaults">Save Company/Bank/Signature as default</label>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mt-3">
                    <div class="card-header fw-semibold">Bank Details</div>
                    <div class="card-body">
                        <div class="mb-2">
                            <label class="form-label small text-muted">Bank Name</label>
                            <input class="form-control form-control-sm" name="bank_name" value="<?php echo htmlspecialchars($displayBankName); ?>">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small text-muted">Account Name</label>
                            <input class="form-control form-control-sm" name="account_name" value="<?php echo htmlspecialchars($displayAccountName); ?>">
                        </div>
                        <div class="mb-2">
                            <label class="form-label small text-muted">Account Number</label>
                            <input class="form-control form-control-sm" name="account_number" value="<?php echo htmlspecialchars($displayAccountNumber); ?>">
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label small text-muted">IFSC</label>
                                <input class="form-control form-control-sm" name="ifsc_code" value="<?php echo htmlspecialchars($displayIfsc); ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label small text-muted">SWIFT</label>
                                <input class="form-control form-control-sm" name="swift_code" value="<?php echo htmlspecialchars($displaySwift); ?>">
                            </div>
                        </div>
                        <div class="mt-2">
                            <label class="form-label small text-muted">Beneficiary Address</label>
                            <textarea class="form-control form-control-sm" name="beneficiary_address" rows="3"><?php echo htmlspecialchars($displayBenAddr); ?></textarea>
                        </div>
                        <div class="mt-2">
                            <label class="form-label small text-muted">Beneficiary City & State</label>
                            <input class="form-control form-control-sm" name="beneficiary_city_state" value="<?php echo htmlspecialchars($displayBenCity); ?>">
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mt-3">
                    <div class="card-header fw-semibold">Authorised Signature</div>
                    <div class="card-body">
                        <?php if (!empty($invoice['signature_path'])): ?>
                            <div class="mb-2">
                                <img src="../../<?php echo htmlspecialchars((string)$invoice['signature_path']); ?>" style="max-width:100%;height:60px;object-fit:contain;background:rgba(0,0,0,0.03);border:1px solid var(--app-border);border-radius:10px;padding:6px;">
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="remove_signature" value="1" id="removeSig">
                                <label class="form-check-label small text-muted" for="removeSig">Remove existing signature</label>
                            </div>
                        <?php endif; ?>
                        <label class="form-label small text-muted">Upload Signature (PNG/JPG/WebP)</label>
                        <input class="form-control form-control-sm" type="file" name="signature_file" accept=".png,.jpg,.jpeg,.webp">
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div class="fw-semibold">Items</div>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="addItemBtn"><i class="bi bi-plus-circle me-1"></i>Add Item</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0" id="itemsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Campaign</th>
                                    <th style="width:120px;" class="text-end">Leads</th>
                                    <th style="width:140px;" class="text-end">CPL</th>
                                    <th style="width:160px;" class="text-end">Amount</th>
                                    <th style="width:80px;" class="text-end">Remove</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $it): ?>
                                    <tr>
                                        <td><input class="form-control form-control-sm" name="item_desc[]" value="<?php echo htmlspecialchars((string)($it['description'] ?? '')); ?>"></td>
                                        <td><input class="form-control form-control-sm text-end" type="number" step="0.01" min="0" name="item_qty[]" value="<?php echo htmlspecialchars((string)($it['qty'] ?? 1)); ?>"></td>
                                        <td><input class="form-control form-control-sm text-end" type="number" step="0.01" min="0" name="item_rate[]" value="<?php echo htmlspecialchars((string)($it['unit_price'] ?? 0)); ?>"></td>
                                        <td class="text-end fw-semibold item-amt">—</td>
                                        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-remove-row><i class="bi bi-trash"></i></button></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($items)): ?>
                                    <tr>
                                        <td><input class="form-control form-control-sm" name="item_desc[]" value=""></td>
                                        <td><input class="form-control form-control-sm text-end" type="number" step="0.01" min="0" name="item_qty[]" value="1"></td>
                                        <td><input class="form-control form-control-sm text-end" type="number" step="0.01" min="0" name="item_rate[]" value="0"></td>
                                        <td class="text-end fw-semibold item-amt">—</td>
                                        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-remove-row><i class="bi bi-trash"></i></button></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-body border-top">
                        <div class="row g-2">
                            <div class="col-12">
                                <label class="form-label small text-muted">Notes</label>
                                <textarea class="form-control form-control-sm" name="notes" rows="3"><?php echo htmlspecialchars((string)($invoice['notes'] ?? '')); ?></textarea>
                            </div>
                            <div class="col-12 d-flex justify-content-end gap-2">
                                <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-check2-circle me-1"></i>Save</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mt-3">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div class="text-muted small">Totals auto-calculate on Save and PDF export.</div>
                        <div class="fw-semibold"><?php echo htmlspecialchars((string)($invoice['currency'] ?? 'USD') . ' ' . number_format((float)($invoice['total'] ?? 0), 2)); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
<script>
    function calcRow(tr) {
        const q = parseFloat(tr.querySelector('input[name="item_qty[]"]')?.value || '0');
        const r = parseFloat(tr.querySelector('input[name="item_rate[]"]')?.value || '0');
        const amt = (q * r);
        const cell = tr.querySelector('.item-amt');
        if (cell) cell.textContent = isFinite(amt) ? amt.toFixed(2) : '—';
    }
    function bindRow(tr) {
        tr.querySelectorAll('input').forEach(i => i.addEventListener('input', () => calcRow(tr)));
        tr.querySelector('[data-remove-row]')?.addEventListener('click', () => {
            tr.remove();
        });
        calcRow(tr);
    }
    document.querySelectorAll('#itemsTable tbody tr').forEach(bindRow);
    document.getElementById('addItemBtn')?.addEventListener('click', () => {
        const tbody = document.querySelector('#itemsTable tbody');
        if (!tbody) return;
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td><input class="form-control form-control-sm" name="item_desc[]" value=""></td>
            <td><input class="form-control form-control-sm text-end" type="number" step="0.01" min="0" name="item_qty[]" value="1"></td>
            <td><input class="form-control form-control-sm text-end" type="number" step="0.01" min="0" name="item_rate[]" value="0"></td>
            <td class="text-end fw-semibold item-amt">—</td>
            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-remove-row><i class="bi bi-trash"></i></button></td>
        `;
        tbody.appendChild(tr);
        bindRow(tr);
    });
</script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
