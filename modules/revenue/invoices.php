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
$startDt = $start . ' 00:00:00';
$endDt = $end . ' 23:59:59';

$prefCampaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0;
$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : 'All';
$q = trim((string)($_GET['q'] ?? ''));
$allowedStatuses = ['All','Draft','Sent','Paid','Cancelled'];
if (!in_array($statusFilter, $allowedStatuses, true)) $statusFilter = 'All';

$message = '';
$messageType = 'success';

$makeInvoiceNo = function(string $monthStr) use ($conn): string {
    $prefix = 'INV-' . str_replace('-', '', $monthStr) . '-';
    $stmt = $conn->prepare("SELECT invoice_no FROM revenue_invoices WHERE invoice_no LIKE ? ORDER BY id DESC LIMIT 1");
    $like = $prefix . '%';
    $lastNo = '';
    if ($stmt) {
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $lastNo = (string)(($stmt->get_result()->fetch_assoc() ?: [])['invoice_no'] ?? '');
        $stmt->close();
    }
    $n = 1;
    if ($lastNo !== '' && substr($lastNo, 0, strlen($prefix)) === $prefix) {
        $tail = substr($lastNo, strlen($prefix));
        if (preg_match('/^\d+$/', $tail)) $n = ((int)$tail) + 1;
    }
    return $prefix . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $message = 'Invalid token.';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'delete_invoice') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $message = 'Invalid invoice.';
                $messageType = 'danger';
            } else {
                $st = $conn->prepare("SELECT status FROM revenue_invoices WHERE id = ? LIMIT 1");
                $status = '';
                if ($st) {
                    $st->bind_param('i', $id);
                    $st->execute();
                    $status = (string)(($st->get_result()->fetch_assoc() ?: [])['status'] ?? '');
                    $st->close();
                }
                if ($status === 'Paid') {
                    $message = 'Paid invoice cannot be deleted.';
                    $messageType = 'danger';
                } else {
                    $delItems = $conn->prepare("DELETE FROM revenue_invoice_items WHERE invoice_id = ?");
                    if ($delItems) {
                        $delItems->bind_param('i', $id);
                        $delItems->execute();
                        $delItems->close();
                    }
                    $delInv = $conn->prepare("DELETE FROM revenue_invoices WHERE id = ? LIMIT 1");
                    if ($delInv) {
                        $delInv->bind_param('i', $id);
                        $ok = $delInv->execute();
                        $delInv->close();
                        $message = $ok ? 'Invoice deleted.' : 'Unable to delete invoice.';
                        $messageType = $ok ? 'success' : 'danger';
                    } else {
                        $message = 'Database error.';
                        $messageType = 'danger';
                    }
                }
            }
        } elseif ($action === 'update_status') {
            $id = (int)($_POST['id'] ?? 0);
            $newStatus = trim((string)($_POST['new_status'] ?? ''));
            $allowed = ['Draft','Sent','Paid','Cancelled'];
            if ($id <= 0 || !in_array($newStatus, $allowed, true)) {
                $message = 'Invalid request.';
                $messageType = 'danger';
            } else {
                $stmt = $conn->prepare("UPDATE revenue_invoices SET status = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('si', $newStatus, $id);
                    $ok = $stmt->execute();
                    $stmt->close();
                    $message = $ok ? 'Status updated.' : 'Unable to update status.';
                    $messageType = $ok ? 'success' : 'danger';
                    if ($ok) {
                        $st2 = $conn->prepare("SELECT invoice_no, created_by FROM revenue_invoices WHERE id = ? LIMIT 1");
                        $invNo = '';
                        $createdBy = 0;
                        if ($st2) {
                            $st2->bind_param('i', $id);
                            $st2->execute();
                            $row = $st2->get_result()->fetch_assoc() ?: null;
                            $st2->close();
                            if ($row) {
                                $invNo = (string)($row['invoice_no'] ?? '');
                                $createdBy = (int)($row['created_by'] ?? 0);
                            }
                        }
                        if ($createdBy > 0 && $createdBy !== (int)($user['id'] ?? 0)) {
                            $ttl = 'Invoice status updated';
                            $msg = ($invNo !== '' ? $invNo : ('Invoice #' . $id)) . ' · Status: ' . $newStatus;
                            $link = 'invoice-edit?id=' . $id;
                            createNotificationSmart($createdBy, 'invoice.status_changed', $ttl, $msg, $link, [
                                'importance' => $newStatus === 'Paid' ? 'high' : 'normal',
                                'show_toast' => $newStatus === 'Paid',
                                'dedup_key' => 'inv_status:' . $id . ':' . $newStatus,
                                'dedup_window_min' => 1440,
                            ]);
                        }
                    }
                } else {
                    $message = 'Database error.';
                    $messageType = 'danger';
                }
            }
        } elseif ($action === 'bulk_delete' || $action === 'bulk_copy' || $action === 'bulk_status') {
            $idsRaw = trim((string)($_POST['ids'] ?? ''));
            $ids = array_values(array_filter(array_map('intval', preg_split('/\s*,\s*/', $idsRaw) ?: []), fn($v) => $v > 0));
            $ids = array_values(array_unique($ids));
            if (empty($ids)) {
                $message = 'Select at least one invoice.';
                $messageType = 'danger';
            } else {
                if ($action === 'bulk_status') {
                    $newStatus = trim((string)($_POST['new_status'] ?? ''));
                    $allowed = ['Draft','Sent','Paid','Cancelled'];
                    if (!in_array($newStatus, $allowed, true)) {
                        $message = 'Invalid status.';
                        $messageType = 'danger';
                    } else {
                        $stmt = $conn->prepare("UPDATE revenue_invoices SET status = ?, updated_at = NOW() WHERE id = ?");
                        $updated = 0;
                        if ($stmt) {
                            foreach ($ids as $id) {
                                $stmt->bind_param('si', $newStatus, $id);
                                if ($stmt->execute()) $updated++;
                            }
                            $stmt->close();
                        }
                        $message = 'Updated: ' . $updated;
                        $messageType = 'success';
                    }
                } elseif ($action === 'bulk_delete') {
                    $deleted = 0;
                    $skipped = 0;
                    foreach ($ids as $id) {
                        $st = $conn->prepare("SELECT status FROM revenue_invoices WHERE id = ? LIMIT 1");
                        $status = '';
                        if ($st) {
                            $st->bind_param('i', $id);
                            $st->execute();
                            $status = (string)(($st->get_result()->fetch_assoc() ?: [])['status'] ?? '');
                            $st->close();
                        }
                        if ($status === 'Paid') {
                            $skipped++;
                            continue;
                        }
                        $delItems = $conn->prepare("DELETE FROM revenue_invoice_items WHERE invoice_id = ?");
                        if ($delItems) {
                            $delItems->bind_param('i', $id);
                            $delItems->execute();
                            $delItems->close();
                        }
                        $delInv = $conn->prepare("DELETE FROM revenue_invoices WHERE id = ? LIMIT 1");
                        if ($delInv) {
                            $delInv->bind_param('i', $id);
                            if ($delInv->execute()) $deleted++;
                            $delInv->close();
                        }
                    }
                    $message = 'Deleted: ' . $deleted . ($skipped > 0 ? (' · Skipped paid: ' . $skipped) : '');
                    $messageType = $deleted > 0 ? 'success' : 'danger';
                } elseif ($action === 'bulk_copy') {
                    $copied = 0;
                    foreach ($ids as $srcId) {
                        $st = $conn->prepare("SELECT * FROM revenue_invoices WHERE id = ? LIMIT 1");
                        $src = null;
                        if ($st) {
                            $st->bind_param('i', $srcId);
                            $st->execute();
                            $src = $st->get_result()->fetch_assoc() ?: null;
                            $st->close();
                        }
                        if (!$src) continue;

                        $items = [];
                        $st = $conn->prepare("SELECT description, qty, unit_price, amount, sort_order FROM revenue_invoice_items WHERE invoice_id = ? ORDER BY sort_order, id");
                        if ($st) {
                            $st->bind_param('i', $srcId);
                            $st->execute();
                            $items = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                            $st->close();
                        }

                        $issueDate = date('Y-m-d');
                        $invoiceNo = ((string)(getAppSetting('invoice.numbering.mode', 'sequence') ?? 'sequence') === 'legacy')
                            ? $makeInvoiceNo((string)($src['month_str'] ?? $monthStr))
                            : nextInvoiceNumber($issueDate);
                        $dueDate = date('Y-m-d', strtotime($issueDate . ' +15 days'));
                        $currency = (string)($src['currency'] ?? 'USD');
                        $clientIdVal = (int)($src['client_id'] ?? 0);
                        $clientCode = (string)($src['client_code'] ?? '');
                        $clientName = (string)($src['client_name'] ?? '');
                        $campaignIdVal = (int)($src['campaign_id'] ?? 0);
                        $monthVal = (string)($src['month_str'] ?? $monthStr);
                        $createdBy = (int)($user['id'] ?? 0);
                        $status = 'Draft';

                        $billToName = (string)($src['bill_to_name'] ?? '');
                        $billToAddress = (string)($src['bill_to_address'] ?? '');
                        $billToContactName = (string)($src['bill_to_contact_name'] ?? '');
                        $billToContactEmail = (string)($src['bill_to_contact_email'] ?? '');
                        $billToContactPhone = (string)($src['bill_to_contact_phone'] ?? '');
                        $billToContacts = (string)($src['bill_to_contacts'] ?? '');

                        $billFromName = (string)($src['bill_from_name'] ?? '');
                        $billFromAddress = (string)($src['bill_from_address'] ?? '');
                        $billFromCityState = (string)($src['bill_from_city_state'] ?? '');
                        $billFromCountry = (string)($src['bill_from_country'] ?? '');
                        $billFromEmail = (string)($src['bill_from_email'] ?? '');
                        $billFromPhone = (string)($src['bill_from_phone'] ?? '');

                        $bankName = (string)($src['bank_name'] ?? '');
                        $accountName = (string)($src['account_name'] ?? '');
                        $accountNumber = (string)($src['account_number'] ?? '');
                        $ifscCode = (string)($src['ifsc_code'] ?? '');
                        $swiftCode = (string)($src['swift_code'] ?? '');
                        $beneficiaryAddress = (string)($src['beneficiary_address'] ?? '');
                        $beneficiaryCityState = (string)($src['beneficiary_city_state'] ?? '');
                        $signaturePath = (string)($src['signature_path'] ?? '');

                        $notes = (string)($src['notes'] ?? '');
                        $taxRate = (float)($src['tax_rate'] ?? 0);

                        $subtotal = 0.0;
                        foreach ($items as $it) $subtotal += (float)($it['amount'] ?? 0);
                        $taxAmount = round(($subtotal * $taxRate) / 100.0, 2);
                        $total = $subtotal + $taxAmount;

                        $stmt = $conn->prepare("
                            INSERT INTO revenue_invoices
                            (invoice_no, status, issue_date, due_date, currency, client_id, client_code, client_name,
                             bill_to_name, bill_to_address, bill_to_contact_name, bill_to_contact_email, bill_to_contact_phone, bill_to_contacts,
                             bill_from_name, bill_from_address, bill_from_city_state, bill_from_country, bill_from_email, bill_from_phone,
                             bank_name, account_name, account_number, ifsc_code, swift_code, beneficiary_address, beneficiary_city_state, signature_path,
                             campaign_id, month_str, notes, subtotal, tax_rate, tax_amount, total, created_by, created_at)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                        ");
                        if ($stmt) {
                            $types = 'sssss' . 'i' . str_repeat('s', 22) . 'iss' . 'dddd' . 'i';
                            $stmt->bind_param(
                                $types,
                                $invoiceNo,
                                $status,
                                $issueDate,
                                $dueDate,
                                $currency,
                                $clientIdVal,
                                $clientCode,
                                $clientName,
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
                                $campaignIdVal,
                                $monthVal,
                                $notes,
                                $subtotal,
                                $taxRate,
                                $taxAmount,
                                $total,
                                $createdBy
                            );
                            $ok = $stmt->execute();
                            $newId = (int)$conn->insert_id;
                            $stmt->close();
                            if ($ok && $newId > 0) {
                                $ins = $conn->prepare("INSERT INTO revenue_invoice_items (invoice_id, description, qty, unit_price, amount, sort_order) VALUES (?,?,?,?,?,?)");
                                if ($ins) {
                                    foreach ($items as $it) {
                                        $desc = (string)($it['description'] ?? '');
                                        $qty = (float)($it['qty'] ?? 1);
                                        $rate = (float)($it['unit_price'] ?? 0);
                                        $amt = (float)($it['amount'] ?? ($qty * $rate));
                                        $ord = (int)($it['sort_order'] ?? 0);
                                        $ins->bind_param('isdddi', $newId, $desc, $qty, $rate, $amt, $ord);
                                        $ins->execute();
                                    }
                                    $ins->close();
                                }
                                $copied++;
                            }
                        }
                    }
                    $message = 'Copied: ' . $copied;
                    $messageType = $copied > 0 ? 'success' : 'danger';
                }
            }
        } elseif ($action === 'copy_invoice') {
            $srcId = (int)($_POST['id'] ?? 0);
            if ($srcId <= 0) {
                $message = 'Invalid invoice.';
                $messageType = 'danger';
            } else {
                $st = $conn->prepare("SELECT * FROM revenue_invoices WHERE id = ? LIMIT 1");
                $src = null;
                if ($st) {
                    $st->bind_param('i', $srcId);
                    $st->execute();
                    $src = $st->get_result()->fetch_assoc() ?: null;
                    $st->close();
                }
                if (!$src) {
                    $message = 'Invoice not found.';
                    $messageType = 'danger';
                } else {
                    $items = [];
                    $st = $conn->prepare("SELECT description, qty, unit_price, amount, sort_order FROM revenue_invoice_items WHERE invoice_id = ? ORDER BY sort_order, id");
                    if ($st) {
                        $st->bind_param('i', $srcId);
                        $st->execute();
                        $items = $st->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                        $st->close();
                    }

                    $issueDate = date('Y-m-d');
                    $invoiceNo = ((string)(getAppSetting('invoice.numbering.mode', 'sequence') ?? 'sequence') === 'legacy')
                        ? $makeInvoiceNo((string)($src['month_str'] ?? $monthStr))
                        : nextInvoiceNumber($issueDate);
                    $dueDate = date('Y-m-d', strtotime($issueDate . ' +15 days'));
                    $currency = (string)($src['currency'] ?? 'USD');
                    $clientId = (int)($src['client_id'] ?? 0);
                    $clientCode = (string)($src['client_code'] ?? '');
                    $clientName = (string)($src['client_name'] ?? '');
                    $campaignId = (int)($src['campaign_id'] ?? 0);
                    $monthVal = (string)($src['month_str'] ?? $monthStr);
                    $createdBy = (int)($user['id'] ?? 0);

                    $billToName = (string)($src['bill_to_name'] ?? '');
                    $billToAddress = (string)($src['bill_to_address'] ?? '');
                    $billToContactName = (string)($src['bill_to_contact_name'] ?? '');
                    $billToContactEmail = (string)($src['bill_to_contact_email'] ?? '');
                    $billToContactPhone = (string)($src['bill_to_contact_phone'] ?? '');
                    $billToContacts = (string)($src['bill_to_contacts'] ?? '');

                    $billFromName = (string)($src['bill_from_name'] ?? '');
                    $billFromAddress = (string)($src['bill_from_address'] ?? '');
                    $billFromCityState = (string)($src['bill_from_city_state'] ?? '');
                    $billFromCountry = (string)($src['bill_from_country'] ?? '');
                    $billFromEmail = (string)($src['bill_from_email'] ?? '');
                    $billFromPhone = (string)($src['bill_from_phone'] ?? '');

                    $bankName = (string)($src['bank_name'] ?? '');
                    $accountName = (string)($src['account_name'] ?? '');
                    $accountNumber = (string)($src['account_number'] ?? '');
                    $ifscCode = (string)($src['ifsc_code'] ?? '');
                    $swiftCode = (string)($src['swift_code'] ?? '');
                    $beneficiaryAddress = (string)($src['beneficiary_address'] ?? '');
                    $beneficiaryCityState = (string)($src['beneficiary_city_state'] ?? '');
                    $signaturePath = (string)($src['signature_path'] ?? '');

                    $notes = (string)($src['notes'] ?? '');
                    $taxRate = (float)($src['tax_rate'] ?? 0);
                    $status = 'Draft';

                    $subtotal = 0.0;
                    foreach ($items as $it) {
                        $subtotal += (float)($it['amount'] ?? 0);
                    }
                    $taxAmount = round(($subtotal * $taxRate) / 100.0, 2);
                    $total = $subtotal + $taxAmount;

                    $stmt = $conn->prepare("
                        INSERT INTO revenue_invoices
                        (invoice_no, status, issue_date, due_date, currency, client_id, client_code, client_name,
                         bill_to_name, bill_to_address, bill_to_contact_name, bill_to_contact_email, bill_to_contact_phone, bill_to_contacts,
                         bill_from_name, bill_from_address, bill_from_city_state, bill_from_country, bill_from_email, bill_from_phone,
                         bank_name, account_name, account_number, ifsc_code, swift_code, beneficiary_address, beneficiary_city_state, signature_path,
                         campaign_id, month_str, notes, subtotal, tax_rate, tax_amount, total, created_by, created_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
                    ");
                    if ($stmt) {
                        $clientIdVal = (int)$clientId;
                        $campaignIdVal = (int)$campaignId;
                        $types = 'sssss' . 'i' . str_repeat('s', 22) . 'iss' . 'dddd' . 'i';
                        $stmt->bind_param(
                            $types,
                            $invoiceNo,
                            $status,
                            $issueDate,
                            $dueDate,
                            $currency,
                            $clientIdVal,
                            $clientCode,
                            $clientName,
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
                            $campaignIdVal,
                            $monthVal,
                            $notes,
                            $subtotal,
                            $taxRate,
                            $taxAmount,
                            $total,
                            $createdBy
                        );
                        $ok = $stmt->execute();
                        $newId = (int)$conn->insert_id;
                        $stmt->close();
                        if ($ok && $newId > 0) {
                            $ins = $conn->prepare("INSERT INTO revenue_invoice_items (invoice_id, description, qty, unit_price, amount, sort_order) VALUES (?,?,?,?,?,?)");
                            if ($ins) {
                                foreach ($items as $it) {
                                    $desc = (string)($it['description'] ?? '');
                                    $qty = (float)($it['qty'] ?? 1);
                                    $rate = (float)($it['unit_price'] ?? 0);
                                    $amt = (float)($it['amount'] ?? ($qty * $rate));
                                    $ord = (int)($it['sort_order'] ?? 0);
                                    $ins->bind_param('isdddi', $newId, $desc, $qty, $rate, $amt, $ord);
                                    $ins->execute();
                                }
                                $ins->close();
                            }
                            createNotificationSmart($createdBy, 'invoice.created', 'Invoice created', $invoiceNo . ' created as a copy.', 'invoice-edit?id=' . $newId, [
                                'dedup_key' => 'inv_created:' . $newId,
                                'dedup_window_min' => 1440,
                            ]);
                            header('Location: invoice-edit?id=' . $newId);
                            exit;
                        }
                        $message = 'Unable to copy invoice.';
                        $messageType = 'danger';
                    } else {
                        $message = 'Database error.';
                        $messageType = 'danger';
                    }
                }
            }
        } elseif ($action === 'create_invoice_from_campaign') {
            $campaignId = (int)($_POST['campaign_id'] ?? 0);
            if ($campaignId <= 0) {
                $message = 'Select a campaign.';
                $messageType = 'danger';
            } else {
                $defaults = null;
                $uid = (int)($user['id'] ?? 0);
                if ($uid > 0) {
                    $stDef = $conn->prepare("SELECT * FROM revenue_invoice_settings WHERE user_id = ? LIMIT 1");
                    if ($stDef) {
                        $stDef->bind_param('i', $uid);
                        $stDef->execute();
                        $defaults = $stDef->get_result()->fetch_assoc() ?: null;
                        $stDef->close();
                    }
                }
                $stmt = $conn->prepare("
                    SELECT c.id, c.name, d.client_code, d.cpl, d.cpl_currency,
                           COUNT(l.id) AS delivered
                    FROM campaigns c
                    LEFT JOIN campaign_details d ON d.campaign_id = c.id
                    LEFT JOIN leads l ON l.campaign_id = c.id
                        AND l.client_delivery_status = 'Delivered'
                        AND l.created_at BETWEEN ? AND ?
                    WHERE c.id = ?
                    GROUP BY c.id, c.name, d.client_code, d.cpl, d.cpl_currency
                    LIMIT 1
                ");
                $info = null;
                if ($stmt) {
                    $stmt->bind_param('ssi', $startDt, $endDt, $campaignId);
                    $stmt->execute();
                    $info = $stmt->get_result()->fetch_assoc() ?: null;
                    $stmt->close();
                }
                if (!$info) {
                    $message = 'Campaign not found.';
                    $messageType = 'danger';
                } else {
                    $delivered = (int)($info['delivered'] ?? 0);
                    $cpl = (float)($info['cpl'] ?? 0);
                    $cur = trim((string)($info['cpl_currency'] ?? 'USD'));
                    if ($cur === '') $cur = 'USD';
                    if ($delivered <= 0 || $cpl <= 0) {
                        $message = 'No delivered leads / CPL for this campaign in this month.';
                        $messageType = 'danger';
                    } else {
                        $clientCode = trim((string)($info['client_code'] ?? ''));
                        $clientId = null;
                        $clientName = null;
                        if ($clientCode !== '') {
                            $stc = $conn->prepare("SELECT id, name FROM clients WHERE client_code = ? LIMIT 1");
                            if ($stc) {
                                $stc->bind_param('s', $clientCode);
                                $stc->execute();
                                $cRow = $stc->get_result()->fetch_assoc() ?: null;
                                $stc->close();
                                if ($cRow) {
                                    $clientId = (int)($cRow['id'] ?? 0);
                                    $clientName = (string)($cRow['name'] ?? '');
                                }
                            }
                        }

                        $invoiceNo = ((string)(getAppSetting('invoice.numbering.mode', 'sequence') ?? 'sequence') === 'legacy')
                            ? $makeInvoiceNo($monthStr)
                            : nextInvoiceNumber(date('Y-m-d'));
                        $issueDate = date('Y-m-d');
                        $dueDate = date('Y-m-d', strtotime($issueDate . ' +15 days'));
                        $subtotal = $delivered * $cpl;
                        $taxRate = 0.0;
                        $taxAmount = 0.0;
                        $total = $subtotal;
                        $createdBy = (int)($user['id'] ?? 0);

                        $billToName = $clientName ?? '';
                        $billToAddress = '';
                        $billToContacts = '';
                        if ($uid > 0 && $clientCode !== '') {
                            $stTpl = $conn->prepare("SELECT bill_to_name, bill_to_address, bill_to_contacts FROM revenue_invoice_billto_profiles WHERE user_id = ? AND client_code = ? ORDER BY updated_at DESC, id DESC LIMIT 1");
                            if ($stTpl) {
                                $stTpl->bind_param('is', $uid, $clientCode);
                                $stTpl->execute();
                                $tpl = $stTpl->get_result()->fetch_assoc() ?: null;
                                $stTpl->close();
                                if ($tpl) {
                                    $billToName = (string)($tpl['bill_to_name'] ?? $billToName);
                                    $billToAddress = (string)($tpl['bill_to_address'] ?? '');
                                    $billToContacts = (string)($tpl['bill_to_contacts'] ?? '');
                                }
                            }
                        }
                        if ($billToContacts === '' && $clientId) {
                            $names = [];
                            $emails = [];
                            $phones = [];
                            $stCon = $conn->prepare("SELECT name, email, phone FROM client_contacts WHERE client_id = ? ORDER BY id ASC LIMIT 3");
                            if ($stCon) {
                                $cid = (int)$clientId;
                                $stCon->bind_param('i', $cid);
                                $stCon->execute();
                                $cons = $stCon->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                                $stCon->close();
                                foreach ($cons as $cRow) {
                                    $n = trim((string)($cRow['name'] ?? ''));
                                    $e = trim((string)($cRow['email'] ?? ''));
                                    $p = trim((string)($cRow['phone'] ?? ''));
                                    if ($n !== '') $names[] = $n;
                                    if ($e !== '') $emails[] = $e;
                                    if ($p !== '') $phones[] = $p;
                                }
                            }
                            $lines = [];
                            if (!empty($emails)) $lines[] = 'Email: ' . implode(' / ', $emails);
                            if (!empty($phones)) $lines[] = 'Phone: ' . implode(' / ', $phones);
                            if (!empty($names)) $lines[] = 'Name: ' . implode(' / ', $names);
                            $billToContacts = implode("\n", $lines);
                        }

                        $billFromName = is_array($defaults) ? (string)($defaults['bill_from_name'] ?? '') : '';
                        $billFromAddress = is_array($defaults) ? (string)($defaults['bill_from_address'] ?? '') : '';
                        $billFromCityState = is_array($defaults) ? (string)($defaults['bill_from_city_state'] ?? '') : '';
                        $billFromCountry = is_array($defaults) ? (string)($defaults['bill_from_country'] ?? '') : '';
                        $billFromEmail = is_array($defaults) ? (string)($defaults['bill_from_email'] ?? '') : '';
                        $billFromPhone = is_array($defaults) ? (string)($defaults['bill_from_phone'] ?? '') : '';
                        $bankName = is_array($defaults) ? (string)($defaults['bank_name'] ?? '') : '';
                        $accountName = is_array($defaults) ? (string)($defaults['account_name'] ?? '') : '';
                        $accountNumber = is_array($defaults) ? (string)($defaults['account_number'] ?? '') : '';
                        $ifscCode = is_array($defaults) ? (string)($defaults['ifsc_code'] ?? '') : '';
                        $swiftCode = is_array($defaults) ? (string)($defaults['swift_code'] ?? '') : '';
                        $beneficiaryAddress = is_array($defaults) ? (string)($defaults['beneficiary_address'] ?? '') : '';
                        $beneficiaryCityState = is_array($defaults) ? (string)($defaults['beneficiary_city_state'] ?? '') : '';
                        $signaturePath = is_array($defaults) ? (string)($defaults['signature_path'] ?? '') : '';

                        $stmt = $conn->prepare("
                            INSERT INTO revenue_invoices
                            (invoice_no, status, issue_date, due_date, currency, client_id, client_code, client_name,
                             bill_to_name, bill_to_address, bill_to_contacts,
                             bill_from_name, bill_from_address, bill_from_city_state, bill_from_country, bill_from_email, bill_from_phone,
                             bank_name, account_name, account_number, ifsc_code, swift_code, beneficiary_address, beneficiary_city_state, signature_path,
                             campaign_id, month_str, notes, subtotal, tax_rate, tax_amount, total, created_by)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                        ");
                        if ($stmt) {
                            $notes = 'Generated from delivered leads for ' . $monthStr;
                            $clientIdVal = (int)($clientId ?? 0);
                            $clientNameVal = (string)($clientName ?? '');
                            $status = 'Draft';
                            $types = 'sssss' . 'i' . str_repeat('s', 19) . 'iss' . 'dddd' . 'i';
                            $stmt->bind_param(
                                $types,
                                $invoiceNo,
                                $status,
                                $issueDate,
                                $dueDate,
                                $cur,
                                $clientIdVal,
                                $clientCode,
                                $clientNameVal,
                                $billToName,
                                $billToAddress,
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
                                $campaignId,
                                $monthStr,
                                $notes,
                                $subtotal,
                                $taxRate,
                                $taxAmount,
                                $total,
                                $createdBy
                            );
                            $ok = $stmt->execute();
                            $newId = (int)$conn->insert_id;
                            $stmt->close();
                            if ($ok && $newId > 0) {
                                $desc = 'Lead Delivery - ' . (string)($info['name'] ?? '') . ' (' . $monthStr . ')';
                                $amount = $subtotal;
                                $ins = $conn->prepare("INSERT INTO revenue_invoice_items (invoice_id, description, qty, unit_price, amount, sort_order) VALUES (?,?,?,?,?,0)");
                                if ($ins) {
                                    $qty = (float)$delivered;
                                    $unitPrice = (float)$cpl;
                                    $ins->bind_param('isddd', $newId, $desc, $qty, $unitPrice, $amount);
                                    $ins->execute();
                                    $ins->close();
                                }
                                createNotificationSmart($createdBy, 'invoice.created', 'Invoice created', $invoiceNo . ' created from campaign.', 'invoice-edit?id=' . $newId, [
                                    'dedup_key' => 'inv_created:' . $newId,
                                    'dedup_window_min' => 1440,
                                ]);
                                header('Location: invoice-edit?id=' . $newId);
                                exit;
                            }
                            $message = 'Failed to create invoice.';
                            $messageType = 'danger';
                        } else {
                            $message = 'Database error.';
                            $messageType = 'danger';
                        }
                    }
                }
            }
        }
    }
}

$campaigns = [];
$rs = $conn->query("SELECT c.id, c.name, d.code, d.client_code FROM campaigns c LEFT JOIN campaign_details d ON d.campaign_id = c.id ORDER BY c.name");
if ($rs) $campaigns = $rs->fetch_all(MYSQLI_ASSOC) ?: [];

$rows = [];
$params = [];
$types = '';
$where = "WHERE (i.month_str = ? OR i.month_str IS NULL)";
$params[] = $monthStr;
$types .= 's';
if ($statusFilter !== 'All') {
    $where .= " AND i.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}
if ($q !== '') {
    $where .= " AND (i.invoice_no LIKE ? OR i.client_name LIKE ? OR i.client_code LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'sss';
}
$stmt = $conn->prepare("SELECT i.*, c.name AS campaign_name FROM revenue_invoices i LEFT JOIN campaigns c ON c.id = i.campaign_id {$where} ORDER BY i.issue_date DESC, i.id DESC");
if ($stmt) {
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    $stmt->close();
}

$counts = ['Draft' => 0, 'Sent' => 0, 'Paid' => 0, 'Cancelled' => 0];
$totalsByCur = [];
foreach ($rows as $r) {
    $st = (string)($r['status'] ?? 'Draft');
    if (isset($counts[$st])) $counts[$st]++;
    $cur = strtoupper(trim((string)($r['currency'] ?? 'USD')));
    if ($cur === '') $cur = 'USD';
    $val = (float)($r['total'] ?? 0);
    if (!isset($totalsByCur[$cur])) $totalsByCur[$cur] = 0.0;
    $totalsByCur[$cur] += $val;
}
$topCur = 'USD';
$topVal = 0.0;
foreach ($totalsByCur as $c => $v) {
    if ($v > $topVal) {
        $topVal = (float)$v;
        $topCur = (string)$c;
    }
}

$pageTitle = 'Invoices';
include __DIR__ . '/../../includes/layout/app_start.php';
?>
<div class="container-fluid px-0">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <div class="h3 mb-1">Invoices</div>
            <div class="text-muted small">Month: <?php echo htmlspecialchars($monthStr); ?><?php echo $statusFilter !== 'All' ? (' · Status: ' . htmlspecialchars($statusFilter)) : ''; ?></div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-light border btn-sm" href="revenue-dashboard?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
        </div>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> border-0 shadow-sm"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm p-3 h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div class="text-muted small">Total Invoices</div>
                    <span class="badge bg-primary-subtle text-primary border"><i class="bi bi-file-earmark-text"></i></span>
                </div>
                <div class="h4 mb-0 mt-1"><?php echo number_format(count($rows)); ?></div>
                <div class="text-muted small mt-1">Total: <?php echo htmlspecialchars($topCur . ' ' . number_format($topVal, 2)); ?></div>
            </div>
        </div>
        <div class="col-md-3">
            <a class="text-decoration-none" href="?month=<?php echo urlencode($monthStr); ?>&status=Draft&q=<?php echo urlencode($q); ?>">
                <div class="card border-0 shadow-sm p-3 h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="text-muted small">Draft</div>
                        <span class="badge bg-secondary-subtle text-secondary border"><i class="bi bi-pencil-square"></i></span>
                    </div>
                    <div class="h4 mb-0 mt-1"><?php echo number_format((int)$counts['Draft']); ?></div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a class="text-decoration-none" href="?month=<?php echo urlencode($monthStr); ?>&status=Sent&q=<?php echo urlencode($q); ?>">
                <div class="card border-0 shadow-sm p-3 h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="text-muted small">Sent</div>
                        <span class="badge bg-primary-subtle text-primary border"><i class="bi bi-send"></i></span>
                    </div>
                    <div class="h4 mb-0 mt-1"><?php echo number_format((int)$counts['Sent']); ?></div>
                </div>
            </a>
        </div>
        <div class="col-md-3">
            <a class="text-decoration-none" href="?month=<?php echo urlencode($monthStr); ?>&status=Paid&q=<?php echo urlencode($q); ?>">
                <div class="card border-0 shadow-sm p-3 h-100">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="text-muted small">Paid</div>
                        <span class="badge bg-success-subtle text-success border border-success"><i class="bi bi-check2-circle"></i></span>
                    </div>
                    <div class="h4 mb-0 mt-1"><?php echo number_format((int)$counts['Paid']); ?></div>
                </div>
            </a>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="fw-semibold"><i class="bi bi-plus-circle me-2"></i>Create Invoice</div>
            <button class="btn btn-sm btn-light border" type="button" data-bs-toggle="collapse" data-bs-target="#createInvoiceBox" aria-expanded="true">
                <i class="bi bi-chevron-expand"></i>
            </button>
        </div>
        <div id="createInvoiceBox" class="collapse show">
            <div class="card-body">
                <form method="post" class="row g-2 align-items-end">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="create_invoice_from_campaign">
                    <div class="col-md-7">
                        <label class="form-label small text-muted">Campaign</label>
                        <select class="form-select form-select-sm" name="campaign_id" required>
                            <option value="">Select campaign</option>
                            <?php foreach ($campaigns as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>" <?php echo ((int)$c['id'] === $prefCampaignId) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)($c['name'] ?? '') . ((string)($c['code'] ?? '') !== '' ? (' [' . (string)$c['code'] . ']') : '') . ((string)($c['client_code'] ?? '') !== '' ? (' · ' . (string)$c['client_code']) : '')); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="text-muted small mt-1">Creates a Draft invoice using Delivered leads × CPL for selected month.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small text-muted">Month</label>
                        <input class="form-control form-control-sm" name="month" value="<?php echo htmlspecialchars($monthStr); ?>" readonly>
                    </div>
                    <div class="col-md-2 d-grid">
                        <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-file-earmark-plus me-1"></i>Create Draft</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header">
            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
                <div class="fw-semibold"><i class="bi bi-list-ul me-2"></i>Invoice List</div>
                <div class="d-flex flex-wrap gap-2 align-items-end">
                    <form method="post" id="bulkForm" class="d-flex gap-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" id="bulkAction" value="">
                        <input type="hidden" name="ids" id="bulkIds" value="">
                        <input type="hidden" name="new_status" id="bulkStatus" value="">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-check2-square me-1"></i>Bulk
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><button class="dropdown-item" type="button" data-bulk-action="bulk_copy"><i class="bi bi-files me-2"></i>Copy Selected</button></li>
                                <li><button class="dropdown-item text-danger" type="button" data-bulk-action="bulk_delete" data-confirm="Delete selected invoices?"><i class="bi bi-trash me-2"></i>Delete Selected</button></li>
                                <li><hr class="dropdown-divider"></li>
                                <li class="dropdown-header">Mark As</li>
                                <li><button class="dropdown-item" type="button" data-bulk-action="bulk_status" data-status="Draft"><i class="bi bi-pencil-square me-2"></i>Draft</button></li>
                                <li><button class="dropdown-item" type="button" data-bulk-action="bulk_status" data-status="Sent"><i class="bi bi-send me-2"></i>Sent</button></li>
                                <li><button class="dropdown-item" type="button" data-bulk-action="bulk_status" data-status="Paid"><i class="bi bi-check2-circle me-2"></i>Paid</button></li>
                                <li><button class="dropdown-item" type="button" data-bulk-action="bulk_status" data-status="Cancelled"><i class="bi bi-x-circle me-2"></i>Cancelled</button></li>
                            </ul>
                        </div>
                    </form>
                    <form method="get" class="d-flex flex-wrap gap-2 align-items-end">
                    <input type="hidden" name="month" value="<?php echo htmlspecialchars($monthStr); ?>">
                    <div>
                        <label class="form-label small text-muted mb-1">Status</label>
                        <select class="form-select form-select-sm" name="status">
                            <?php foreach ($allowedStatuses as $st): ?>
                                <option value="<?php echo htmlspecialchars($st); ?>" <?php echo $statusFilter === $st ? 'selected' : ''; ?>><?php echo htmlspecialchars($st); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label small text-muted mb-1">Search</label>
                        <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Invoice / Client">
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-search me-1"></i>Apply</button>
                        <a class="btn btn-light border btn-sm" href="?month=<?php echo urlencode($monthStr); ?>"><i class="bi bi-x-circle me-1"></i>Reset</a>
                    </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="table-responsive invoice-table-wrap">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:40px;"><input type="checkbox" class="form-check-input" id="invCheckAll"></th>
                        <th>Invoice</th>
                        <th>Status</th>
                        <th>Client</th>
                        <th>Campaign</th>
                        <th class="text-end">Total</th>
                        <th>Issue</th>
                        <th>Due</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">No invoices found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <?php
                                $iid = (int)($r['id'] ?? 0);
                                $status = (string)($r['status'] ?? 'Draft');
                                $badge = $status === 'Paid' ? 'bg-success-subtle text-success border border-success'
                                    : ($status === 'Sent' ? 'bg-primary-subtle text-primary border'
                                    : ($status === 'Cancelled' ? 'bg-danger-subtle text-danger border border-danger' : 'bg-secondary-subtle text-secondary border'));
                                $campaignName = (string)($r['campaign_name'] ?? '');
                            ?>
                            <tr>
                                <td><input type="checkbox" class="form-check-input inv-check" value="<?php echo $iid; ?>"></td>
                                <td class="fw-semibold">
                                    <a class="text-decoration-none" href="invoice-edit?id=<?php echo $iid; ?>"><?php echo htmlspecialchars((string)($r['invoice_no'] ?? '')); ?></a>
                                    <div class="text-muted small"><?php echo htmlspecialchars((string)($r['month_str'] ?? '')); ?></div>
                                </td>
                                <td>
                                    <a class="text-decoration-none" href="?month=<?php echo urlencode($monthStr); ?>&status=<?php echo urlencode($status); ?>&q=<?php echo urlencode($q); ?>">
                                        <span class="badge <?php echo htmlspecialchars($badge); ?>"><?php echo htmlspecialchars($status); ?></span>
                                    </a>
                                </td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars((string)($r['client_name'] ?? '')); ?></div>
                                    <div class="text-muted small"><?php echo htmlspecialchars((string)($r['client_code'] ?? '')); ?></div>
                                </td>
                                <td class="text-muted small"><?php echo htmlspecialchars($campaignName); ?></td>
                                <td class="text-end fw-semibold"><?php echo htmlspecialchars((string)($r['currency'] ?? 'USD') . ' ' . number_format((float)($r['total'] ?? 0), 2)); ?></td>
                                <td class="font-monospace"><?php echo htmlspecialchars((string)($r['issue_date'] ?? '')); ?></td>
                                <td class="font-monospace"><?php echo htmlspecialchars((string)($r['due_date'] ?? '')); ?></td>
                                <td class="text-end">
                                    <div class="d-inline-flex align-items-center gap-1 justify-content-end flex-wrap">
                                        <a class="btn btn-xs btn-outline-primary px-2 py-1" href="invoice-edit?id=<?php echo $iid; ?>" data-bs-toggle="tooltip" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                                        <a class="btn btn-xs btn-light border px-2 py-1" href="invoice-pdf?id=<?php echo $iid; ?>&download=1" target="_blank" data-bs-toggle="tooltip" title="Download PDF" aria-label="Download PDF"><i class="bi bi-download"></i></a>
                                        <form method="post" class="m-0">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action" value="copy_invoice">
                                            <input type="hidden" name="id" value="<?php echo $iid; ?>">
                                            <button class="btn btn-xs btn-light border px-2 py-1" type="submit" data-bs-toggle="tooltip" title="Create Copy" aria-label="Create Copy"><i class="bi bi-files"></i></button>
                                        </form>
                                        <div class="btn-group btn-group-xs" role="group" aria-label="Status">
                                            <?php foreach (['Draft','Sent','Paid','Cancelled'] as $st): ?>
                                                <?php
                                                    $stIcon = $st === 'Draft' ? 'bi-pencil-square'
                                                        : ($st === 'Sent' ? 'bi-send'
                                                        : ($st === 'Paid' ? 'bi-check2-circle' : 'bi-x-circle'));
                                                ?>
                                                <form method="post" class="m-0">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="id" value="<?php echo $iid; ?>">
                                                    <input type="hidden" name="new_status" value="<?php echo htmlspecialchars($st); ?>">
                                                    <button class="btn px-2 py-1 <?php echo $status === $st ? 'btn-secondary' : 'btn-outline-secondary'; ?>" type="submit" <?php echo $status === $st ? 'disabled' : ''; ?> data-bs-toggle="tooltip" title="Mark as <?php echo htmlspecialchars($st); ?>" aria-label="Mark as <?php echo htmlspecialchars($st); ?>"><i class="bi <?php echo $stIcon; ?>"></i></button>
                                                </form>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if ($status !== 'Paid'): ?>
                                            <form method="post" class="m-0" onsubmit="return confirm('Delete this invoice?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action" value="delete_invoice">
                                                <input type="hidden" name="id" value="<?php echo $iid; ?>">
                                                <button class="btn btn-xs btn-outline-danger px-2 py-1" type="submit" data-bs-toggle="tooltip" title="Delete" aria-label="Delete"><i class="bi bi-trash"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<style>
.btn-xs{font-size:.72rem;line-height:1.1;border-radius:.25rem}
.btn-group-xs>.btn{font-size:.7rem;line-height:1.1;padding:.16rem .38rem}
.btn-group-xs>.btn i{margin-right:.18rem}
.btn-group-xs>.btn i{margin-right:0}
.invoice-table-wrap{border-radius:.6rem;overflow:hidden;border:1px solid rgba(0,0,0,.08)}
</style>
<script>
    function getSelectedInvoiceIds() {
        return Array.from(document.querySelectorAll('.inv-check:checked')).map(el => el.value).filter(Boolean);
    }
    const checkAll = document.getElementById('invCheckAll');
    if (checkAll) {
        checkAll.addEventListener('change', () => {
            document.querySelectorAll('.inv-check').forEach(cb => cb.checked = checkAll.checked);
        });
    }
    document.querySelectorAll('[data-bulk-action]').forEach(btn => {
        btn.addEventListener('click', () => {
            const ids = getSelectedInvoiceIds();
            if (!ids.length) return;
            const confirmMsg = btn.getAttribute('data-confirm');
            if (confirmMsg && !confirm(confirmMsg)) return;
            const form = document.getElementById('bulkForm');
            if (!form) return;
            const action = btn.getAttribute('data-bulk-action') || '';
            const status = btn.getAttribute('data-status') || '';
            const bulkAction = document.getElementById('bulkAction');
            const bulkIds = document.getElementById('bulkIds');
            const bulkStatus = document.getElementById('bulkStatus');
            if (bulkAction) bulkAction.value = action;
            if (bulkIds) bulkIds.value = ids.join(',');
            if (bulkStatus) bulkStatus.value = status;
            form.submit();
        });
    });
</script>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
