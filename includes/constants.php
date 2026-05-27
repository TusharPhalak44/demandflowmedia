<?php
// Centralized constants and helpers for roles, statuses, and field normalization

// Roles used across the application
$ROLES = [
    'admin' => 'Admin',
    'qa' => 'QA',
    'agent' => 'Agent',
    'form_filler' => 'Form Filler',
];

// Allowed QA statuses (normalized display values)
$QA_STATUSES = ['Pending', 'Reopened', 'Qualified', 'Disqualified', 'Rework Needed', 'Duplicate', 'Rectified', 'Approved', 'Rejected'];

$CLIENT_DELIVERY_STATUSES = ['Pending', 'Delivered', 'Accepted', 'Rejected', 'TBD(To be discussed)', 'In Progress'];

function getClientDeliveryStatuses(): array {
    global $CLIENT_DELIVERY_STATUSES;
    return $CLIENT_DELIVERY_STATUSES;
}

/**
 * Expose QA statuses for templates.
 */
function getQaStatuses(): array {
    global $QA_STATUSES;
    return $QA_STATUSES;
}

// Allowed form completion values
$FORM_DONE_VALUES = ['Yes', 'No'];

/**
 * Incentive Constants
 */
define('DAILY_INCENTIVE_AMOUNT', 500);
define('MONTHLY_BONUS_AMOUNT', 10000);

/**
 * Lead Status Normalization
 */

/**
 * Normalize QA status input to a canonical display value.
 */
function normalizeQaStatus(?string $status): string
{
    if ($status === null) return 'Pending';
    $status = trim($status);
    $map = [
        'qualified' => 'Qualified',
        'disqualified' => 'Disqualified',
        'pending' => 'Pending',
        'reopened' => 'Reopened',
        'rework needed' => 'Rework Needed',
        'rework' => 'Rework Needed',
        'duplicate' => 'Duplicate',
        'rectified' => 'Rectified',
        'delivered' => 'Delivered',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];
    $key = strtolower($status);
    return $map[$key] ?? $status;
}

function normalizeClientDeliveryStatus(?string $status): string
{
    if ($status === null) return 'Pending';
    $status = trim($status);
    $lower = strtolower($status);
    if (in_array($lower, ['delivered', 'yes', 'y', 'true', '1'], true)) return 'Delivered';
    if (in_array($lower, ['pending', 'no', 'n', 'false', '0'], true)) return 'Pending';
    if ($lower === 'accepted') return 'Accepted';
    if ($lower === 'rejected') return 'Rejected';
    if ($lower === 'tbd' || $lower === 'tbd(to be discussed)') return 'TBD(To be discussed)';
    if ($lower === 'in progress' || $lower === 'in_progress') return 'In Progress';
    return $status;
}

/**
 * Normalize form_done/form_filled values to 'Yes'/'No'.
 */
function normalizeFormDone(?string $value): string
{
    if ($value === null) return 'No';
    $value = strtolower(trim($value));
    if (in_array($value, ['yes', 'y', 'true', '1'], true)) return 'Yes';
    if (in_array($value, ['no', 'n', 'false', '0'], true)) return 'No';
    return $value;
}

/**
 * Derive legacy aliases expected in some templates.
 * Adds form_filled alias from form_done and ensures qa_status is normalized.
 */
function enrichLeadRow(array $row): array
{
    if (array_key_exists('form_done', $row) && !array_key_exists('form_filled', $row)) {
        $row['form_filled'] = normalizeFormDone($row['form_done']);
    }
    // Legacy alias: ensure phone field is available from contact_phone
    if (array_key_exists('contact_phone', $row) && !array_key_exists('phone', $row)) {
        $row['phone'] = $row['contact_phone'];
    }
    if (array_key_exists('qa_status', $row)) {
        $row['qa_status'] = normalizeQaStatus($row['qa_status']);
    }
    if (array_key_exists('client_delivery_status', $row)) {
        $row['client_delivery_status'] = normalizeClientDeliveryStatus($row['client_delivery_status']);
    }
    return $row;
}

/**
 * Build WHERE fragments for filters, mapping legacy keys to canonical columns.
 */
function buildLeadFilterWhereClauses(array $filters, array &$params, array &$types): array
{
    $where = [];
    if (!empty($filters['campaign'])) {
        $where[] = 'campaign_name = ?';
        $params[] = $filters['campaign'];
        $types .= 's';
    }
    if (!empty($filters['agent'])) {
        $where[] = 'agent_name = ?';
        $params[] = $filters['agent'];
        $types .= 's';
    }
    if (!empty($filters['country'])) {
        $where[] = 'country = ?';
        $params[] = $filters['country'];
        $types .= 's';
    }
    if (!empty($filters['qa_status'])) {
        $where[] = 'qa_status = ?';
        $params[] = normalizeQaStatus($filters['qa_status']);
        $types .= 's';
    }
    if (!empty($filters['client_delivery_status'])) {
        $where[] = 'client_delivery_status = ?';
        $params[] = normalizeClientDeliveryStatus($filters['client_delivery_status']);
        $types .= 's';
    }
    // Legacy alias support
    if (!empty($filters['form_filled'])) {
        $where[] = 'form_done = ?';
        $params[] = normalizeFormDone($filters['form_filled']);
        $types .= 's';
    }
    if (!empty($filters['form_done'])) {
        $where[] = 'form_done = ?';
        $params[] = normalizeFormDone($filters['form_done']);
        $types .= 's';
    }
    if (!empty($filters['date_from'])) {
        $where[] = 'DATE(created_at) >= ?';
        $params[] = $filters['date_from'];
        $types .= 's';
    }
    if (!empty($filters['date_to'])) {
        $where[] = 'DATE(created_at) <= ?';
        $params[] = $filters['date_to'];
        $types .= 's';
    }
    return $where;
}

?>
