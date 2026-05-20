<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin','director','manager_director','sales_director','sales_manager']);
ensureCsrfToken();

$conn = getDbConnection();
$user = getCurrentUser();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $error = 'Invalid token.';
    } else {
        $action = $_POST['action'] ?? '';
        try {
            if ($action === 'set_user_active') {
                $uid = (int)($_POST['user_id'] ?? 0);
                $cid = (int)($_POST['client_id'] ?? 0);
                $active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 0;
                if ($uid <= 0 || $cid <= 0) throw new RuntimeException('Invalid request.');
                $conn = getDbConnection();
                $stmt = $conn->prepare("SELECT client_id FROM users WHERE id = ? LIMIT 1");
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc() ?: null;
                $stmt->close();
                if (!$row || (int)$row['client_id'] !== $cid) throw new RuntimeException('Not allowed.');
                $st = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                $st->bind_param('ii', $active, $uid);
                if (!$st->execute()) throw new RuntimeException('Failed to update status.');
                $st->close();
                $success = $active ? 'User activated.' : 'User deactivated.';
            }
            if ($action === 'create_client_user') {
                $clientId = (int)($_POST['client_id'] ?? 0);
                $username = trim((string)($_POST['username'] ?? ''));
                $fullName = trim((string)($_POST['full_name'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $role = (string)($_POST['role'] ?? 'client_sdr');
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                $password = (string)($_POST['password'] ?? '');
                if ($clientId <= 0) throw new RuntimeException('Select a client first.');
                if ($username === '' || $fullName === '' || $email === '') throw new RuntimeException('Username, Full Name, Email are required.');
                if (!in_array($role, ['client_admin','client_sdr'], true)) throw new RuntimeException('Invalid role for Client user.');
                if ($password === '') $password = bin2hex(random_bytes(4));
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $conn = getDbConnection();
                $chk = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
                $chk->bind_param('s', $username);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0) throw new RuntimeException('Username already exists.');
                $chk->close();
                $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, email, role, is_active, client_id, vendor_id) VALUES (?,?,?,?,?,?,?,0)");
                $stmt->bind_param('sssssis', $username, $hashed, $fullName, $email, $role, $isActive, $clientId);
                if (!$stmt->execute()) throw new RuntimeException('Failed to create client user.');
                $stmt->close();
                $success = 'Client user created.';
            } elseif ($action === 'save_client') {
                $clientId = (int)($_POST['client_id'] ?? 0);
                $clientCode = trim((string)($_POST['client_code'] ?? ''));
                $name = trim((string)($_POST['name'] ?? ''));
                $website = trim((string)($_POST['website'] ?? ''));
                $industry = trim((string)($_POST['industry'] ?? ''));
                $country = trim((string)($_POST['country'] ?? ''));
                $notes = trim((string)($_POST['notes'] ?? ''));
                $tagsRaw = trim((string)($_POST['tags'] ?? ''));

                if ($clientCode === '' || $name === '') {
                    throw new RuntimeException('Client Code and Name are required.');
                }

                $websiteDomain = extractDomain($website);

                if ($clientId > 0) {
                    $stmt = $conn->prepare("UPDATE clients SET client_code = ?, name = ?, website = ?, website_domain = ?, industry = ?, country = ?, notes = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->bind_param('sssssssi', $clientCode, $name, $website, $websiteDomain, $industry, $country, $notes, $clientId);
                    if (!$stmt->execute()) throw new RuntimeException('Failed to update client.');
                    $stmt->close();
                } else {
                    $createdBy = (int)($user['id'] ?? 0);
                    $stmt = $conn->prepare("INSERT INTO clients (client_code, name, website, website_domain, industry, country, notes, created_by) VALUES (?,?,?,?,?,?,?,?)");
                    $stmt->bind_param('sssssssi', $clientCode, $name, $website, $websiteDomain, $industry, $country, $notes, $createdBy);
                    if (!$stmt->execute()) throw new RuntimeException('Failed to create client.');
                    $clientId = (int)$conn->insert_id;
                    $stmt->close();
                }

                $tags = array_values(array_filter(array_map(function($t) { return trim($t); }, preg_split('/[,\\n]+/', $tagsRaw) ?: [])));
                $tagIds = [];
                foreach ($tags as $t) {
                    if ($t === '') continue;
                    $ins = $conn->prepare("INSERT IGNORE INTO tags (name) VALUES (?)");
                    $ins->bind_param('s', $t);
                    $ins->execute();
                    $ins->close();

                    $sel = $conn->prepare("SELECT id FROM tags WHERE name = ? LIMIT 1");
                    $sel->bind_param('s', $t);
                    $sel->execute();
                    $row = $sel->get_result()->fetch_assoc();
                    $sel->close();
                    if ($row) $tagIds[] = (int)$row['id'];
                }

                $conn->query("DELETE FROM client_tags WHERE client_id = ".(int)$clientId);
                if (!empty($tagIds)) {
                    $link = $conn->prepare("INSERT IGNORE INTO client_tags (client_id, tag_id) VALUES (?, ?)");
                    foreach ($tagIds as $tid) {
                        $link->bind_param('ii', $clientId, $tid);
                        $link->execute();
                    }
                    $link->close();
                }

                $success = 'Client saved.';
            } elseif ($action === 'add_contact') {
                $clientId = (int)($_POST['client_id'] ?? 0);
                $name = trim((string)($_POST['contact_name'] ?? ''));
                $email = trim((string)($_POST['contact_email'] ?? ''));
                $phone = trim((string)($_POST['contact_phone'] ?? ''));
                $title = trim((string)($_POST['contact_title'] ?? ''));
                if ($clientId <= 0 || $name === '') throw new RuntimeException('Client and contact name are required.');
                $stmt = $conn->prepare("INSERT INTO client_contacts (client_id, name, email, phone, title) VALUES (?,?,?,?,?)");
                $stmt->bind_param('issss', $clientId, $name, $email, $phone, $title);
                if (!$stmt->execute()) throw new RuntimeException('Failed to add contact.');
                $stmt->close();
                $success = 'Contact added.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$clientViewId = (int)($_GET['client_id'] ?? 0);

$whereSql = '';
$params = [];
$types = '';
if ($q !== '') {
    $whereSql = "WHERE c.client_code LIKE ? OR c.name LIKE ? OR c.website LIKE ? OR c.industry LIKE ?";
    $like = '%'.$q.'%';
    $params = [$like, $like, $like, $like];
    $types = 'ssss';
}

$sql = "SELECT c.*,
        (SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ')
         FROM client_tags ct JOIN tags t ON t.id = ct.tag_id
         WHERE ct.client_id = c.id) AS tags,
        (SELECT COUNT(*) FROM client_contacts cc WHERE cc.client_id = c.id) AS contacts_count,
        sco.owner_id, sco.manager_id, sco.assigned_at, sco.assigned_by,
        ou.full_name AS owner_name, ou.role AS owner_role,
        mu.full_name AS manager_name, mu.role AS manager_role,
        abu.full_name AS assigned_by_name,
        (SELECT COUNT(*) FROM campaign_details d WHERE d.client_id = c.id) AS campaigns_total,
        (SELECT COUNT(*) FROM campaign_details d WHERE d.client_id = c.id AND d.status = 'Live') AS campaigns_live
        FROM clients c
        LEFT JOIN sales_client_ownership sco ON sco.client_id = c.id
        LEFT JOIN users ou ON ou.id = sco.owner_id
        LEFT JOIN users mu ON mu.id = sco.manager_id
        LEFT JOIN users abu ON abu.id = sco.assigned_by
        $whereSql
        ORDER BY c.created_at DESC";
$stmt = $conn->prepare($sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$clients = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$stmt->close();

$clientRow = null;
$contacts = [];
$clientUsers = [];
if ($clientViewId > 0) {
    $stmt = $conn->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $clientViewId);
    $stmt->execute();
    $clientRow = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($clientRow) {
        $stmt = $conn->prepare("SELECT * FROM client_contacts WHERE client_id = ? ORDER BY id DESC");
        $stmt->bind_param('i', $clientViewId);
        $stmt->execute();
        $contacts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();

        $stmt = $conn->prepare("SELECT GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ') AS tags
            FROM client_tags ct JOIN tags t ON t.id = ct.tag_id WHERE ct.client_id = ?");
        $stmt->bind_param('i', $clientViewId);
        $stmt->execute();
        $trow = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        $clientRow['tags'] = $trow['tags'] ?? '';

        $userQ = trim((string)($_GET['user_q'] ?? ''));
        $userPage = max(1, (int)($_GET['user_page'] ?? 1));
        $pageSize = 10;
        $offset = ($userPage - 1) * $pageSize;
        if ($userQ !== '') {
            $like = '%'.$userQ.'%';
            $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM users WHERE client_id = ? AND (full_name LIKE ? OR username LIKE ? OR email LIKE ?)");
            $stmt->bind_param('isss', $clientViewId, $like, $like, $like);
        } else {
            $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM users WHERE client_id = ?");
            $stmt->bind_param('i', $clientViewId);
        }
        $stmt->execute();
        $rowc = $stmt->get_result()->fetch_assoc() ?: ['cnt' => 0];
        $stmt->close();
        $clientUsersTotal = (int)($rowc['cnt'] ?? 0);
        $clientUsersPages = max(1, (int)ceil($clientUsersTotal / $pageSize));
        if ($userQ !== '') {
            $like = '%'.$userQ.'%';
            $sqlu = "SELECT id, full_name, username, email, job_title, role, is_active, profile_pic FROM users WHERE client_id = ? AND (full_name LIKE ? OR username LIKE ? OR email LIKE ?) ORDER BY role, full_name LIMIT $pageSize OFFSET $offset";
            $stmt = $conn->prepare($sqlu);
            $stmt->bind_param('isss', $clientViewId, $like, $like, $like);
        } else {
            $sqlu = "SELECT id, full_name, username, email, job_title, role, is_active, profile_pic FROM users WHERE client_id = ? ORDER BY role, full_name LIMIT $pageSize OFFSET $offset";
            $stmt = $conn->prepare($sqlu);
            $stmt->bind_param('i', $clientViewId);
        }
        $stmt->execute();
        $clientUsers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
}
?>

<?php $pageTitle = 'Clients'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>
<div class="container-fluid px-0">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">Clients</h3>
    <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#clientModal" onclick="openClientModal()">
      <i class="bi bi-plus-circle me-1"></i>Add Client
    </button>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
  <?php endif; ?>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get">
        <div class="col-md-9">
          <label class="form-label">Search</label>
          <input class="form-control form-control-sm" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Client code, name, website, industry">
        </div>
        <div class="col-md-3 d-grid">
          <button class="btn btn-outline-primary btn-sm" type="submit"><i class="bi bi-search me-1"></i>Search</button>
        </div>
      </form>
      <div class="text-muted small mt-2">Total: <?php echo number_format(count($clients)); ?></div>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Client</th>
            <th>Owner</th>
            <th>Manager</th>
            <th class="text-end">Campaigns</th>
            <th class="text-end">Live</th>
            <th>Assigned</th>
            <th class="text-end">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($clients)): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No clients found.</td></tr>
          <?php else: ?>
            <?php foreach ($clients as $c): ?>
              <?php
                $cid = (int)($c['id'] ?? 0);
                $assignedAt = (string)($c['assigned_at'] ?? '');
                $assignedBy = trim((string)($c['assigned_by_name'] ?? ''));
              ?>
              <tr>
                <td>
                  <div class="fw-semibold"><?php echo htmlspecialchars((string)($c['name'] ?? '')); ?></div>
                  <div class="text-muted small">
                    <?php echo htmlspecialchars((string)($c['client_code'] ?? '')); ?>
                    <?php echo !empty($c['industry']) ? ' · '.htmlspecialchars((string)$c['industry']) : ''; ?>
                    <?php echo !empty($c['contacts_count']) ? ' · '.number_format((int)$c['contacts_count']).' contacts' : ''; ?>
                  </div>
                </td>
                <td class="text-muted small">
                  <?php echo !empty($c['owner_name']) ? htmlspecialchars(formatUserNameWithRole((string)$c['owner_name'], (string)($c['owner_role'] ?? ''))) : '<span class="text-muted">—</span>'; ?>
                </td>
                <td class="text-muted small">
                  <?php echo !empty($c['manager_name']) ? htmlspecialchars(formatUserNameWithRole((string)$c['manager_name'], (string)($c['manager_role'] ?? ''))) : '<span class="text-muted">—</span>'; ?>
                </td>
                <td class="text-end font-monospace"><?php echo number_format((int)($c['campaigns_total'] ?? 0)); ?></td>
                <td class="text-end font-monospace"><?php echo number_format((int)($c['campaigns_live'] ?? 0)); ?></td>
                <td class="text-muted small">
                  <?php if ($assignedAt): ?>
                    <div><?php echo htmlspecialchars(date('d M Y, H:i', strtotime($assignedAt))); ?></div>
                    <?php if ($assignedBy !== ''): ?><div class="text-muted">by <?php echo htmlspecialchars($assignedBy); ?></div><?php endif; ?>
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <div class="d-inline-flex align-items-center gap-1">
                    <a class="btn btn-sm btn-light border" href="clients.php?client_id=<?php echo $cid; ?>" data-bs-toggle="tooltip" title="Open"><i class="bi bi-box-arrow-up-right"></i></a>
                    <button type="button" class="btn btn-sm btn-light border" data-bs-toggle="modal" data-bs-target="#clientModal" onclick="openClientModal(<?php echo $cid; ?>)" data-bs-toggle="tooltip" title="Edit"><i class="bi bi-pencil"></i></button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">Client Details</div>
    <div class="card-body">
      <?php if (!$clientRow): ?>
        <div class="text-muted">Open a client from the table to view details, contacts, and logins.</div>
      <?php else: ?>
            <div class="d-flex align-items-start justify-content-between">
              <div>
                <div class="fw-semibold fs-5"><?php echo htmlspecialchars($clientRow['name']); ?></div>
                <div class="text-muted small"><?php echo htmlspecialchars($clientRow['client_code']); ?><?php echo !empty($clientRow['industry']) ? ' · '.htmlspecialchars($clientRow['industry']) : ''; ?></div>
              </div>
              <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#clientModal" onclick="openClientModal(<?php echo (int)$clientRow['id']; ?>)"><i class="bi bi-pencil"></i> Edit</button>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#clientUserModal"><i class="bi bi-person-plus"></i> Add Login</button>
              </div>
            </div>
            
            <div class="row g-3 mt-3">
              <?php if (!empty($clientRow['website'])): ?>
                <div class="col-sm-6">
                  <div class="text-muted small">Website</div>
                  <a href="<?php echo htmlspecialchars($clientRow['website']); ?>" target="_blank" class="text-decoration-none fw-medium"><i class="bi bi-globe me-1"></i><?php echo htmlspecialchars($clientRow['website']); ?></a>
                </div>
              <?php endif; ?>
              <?php if (!empty($clientRow['tags'])): ?>
                <div class="col-sm-6">
                  <div class="text-muted small mb-1">Tags</div>
                  <?php foreach(explode(',', $clientRow['tags']) as $tag): ?>
                    <span class="badge bg-light text-dark border"><?php echo htmlspecialchars(trim($tag)); ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <?php if (!empty($clientRow['notes'])): ?>
                <div class="col-12">
                  <div class="text-muted small">Notes</div>
                  <div class="bg-light rounded p-2 small border"><?php echo nl2br(htmlspecialchars($clientRow['notes'])); ?></div>
                </div>
              <?php endif; ?>
            </div>

            <hr class="my-4">

            <div class="d-flex align-items-center justify-content-between mb-3">
              <h6 class="fw-bold mb-0"><i class="bi bi-person-lines-fill me-2"></i>Contacts</h6>
            </div>
            <div class="table-responsive mb-4">
              <table class="table table-sm table-hover align-middle border mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Title</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($contacts)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-3">No contacts added yet.</td></tr>
                  <?php else: ?>
                    <?php foreach ($contacts as $cc): ?>
                      <tr>
                        <td class="fw-medium"><?php echo htmlspecialchars($cc['name']); ?></td>
                        <td><a href="mailto:<?php echo htmlspecialchars($cc['email'] ?? ''); ?>" class="text-decoration-none text-muted"><?php echo htmlspecialchars($cc['email'] ?? ''); ?></a></td>
                        <td><a href="tel:<?php echo htmlspecialchars($cc['phone'] ?? ''); ?>" class="text-decoration-none text-muted"><?php echo htmlspecialchars($cc['phone'] ?? ''); ?></a></td>
                        <td class="text-muted"><?php echo htmlspecialchars($cc['title'] ?? ''); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

            <form method="post" class="row g-2 align-items-end p-3 bg-light rounded border mb-4">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
              <input type="hidden" name="action" value="add_contact">
              <input type="hidden" name="client_id" value="<?php echo (int)$clientRow['id']; ?>">
              <div class="col-md-3">
                <label class="form-label small fw-bold mb-1">Contact Name *</label>
                <input class="form-control form-control-sm" name="contact_name" placeholder="Name" required>
              </div>
              <div class="col-md-3">
                <label class="form-label small fw-bold mb-1">Email</label>
                <input class="form-control form-control-sm" type="email" name="contact_email" placeholder="Email">
              </div>
              <div class="col-md-2">
                <label class="form-label small fw-bold mb-1">Phone</label>
                <input class="form-control form-control-sm" name="contact_phone" placeholder="Phone">
              </div>
              <div class="col-md-2">
                <label class="form-label small fw-bold mb-1">Job Title</label>
                <input class="form-control form-control-sm" name="contact_title" placeholder="Title">
              </div>
              <div class="col-md-2 d-grid">
                <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-plus-circle me-1"></i>Add</button>
              </div>
            </form>

            <div class="d-flex align-items-center justify-content-between mb-3">
              <h6 class="fw-bold mb-0"><i class="bi bi-shield-lock me-2"></i>Client Logins</h6>
              <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#clientUserModal"><i class="bi bi-person-plus"></i> New Login</button>
            </div>
            <form class="row g-2 mb-3" method="get">
              <input type="hidden" name="client_id" value="<?php echo (int)$clientRow['id']; ?>">
              <div class="col-md-9">
                <input class="form-control form-control-sm" name="user_q" value="<?php echo htmlspecialchars($_GET['user_q'] ?? ''); ?>" placeholder="Search client users by name, username, email...">
              </div>
              <div class="col-md-3 d-grid">
                <button class="btn btn-outline-secondary btn-sm" type="submit"><i class="bi bi-search"></i></button>
              </div>
            </form>
            <div class="table-responsive">
              <table class="table table-sm table-hover align-middle border mb-0">
                <thead class="table-light">
                  <tr>
                    <th>User</th>
                    <th>Job Title</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($clientUsers)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No login accounts created yet.</td></tr>
                  <?php else: ?>
                    <?php foreach ($clientUsers as $uu): ?>
                      <tr>
                        <td>
                          <div class="d-flex align-items-center gap-2">
                            <?php if (!empty($uu['profile_pic'])): ?>
                              <img src="../../<?php echo htmlspecialchars($uu['profile_pic']); ?>" style="height:32px;width:32px;border-radius:50%;object-fit:cover;">
                            <?php else: ?>
                              <div class="d-inline-flex align-items-center justify-content-center bg-primary text-white" style="height:32px;width:32px;border-radius:50%;font-weight:600;"><?php echo strtoupper(substr($uu['full_name'] ?? 'U', 0, 1)); ?></div>
                            <?php endif; ?>
                            <div>
                              <div class="fw-bold"><?php echo htmlspecialchars($uu['full_name'] ?? 'User'); ?></div>
                              <div class="text-muted" style="font-size: 0.75rem;">@<?php echo htmlspecialchars($uu['username'] ?? 'username'); ?> · <?php echo htmlspecialchars($uu['email'] ?? ''); ?></div>
                            </div>
                          </div>
                        </td>
                        <td class="text-muted small"><?php echo htmlspecialchars((string)($uu['job_title'] ?? '')); ?></td>
                        <td>
                          <?php if (!empty($uu['is_active'])): ?>
                            <span class="badge bg-success-subtle text-success border border-success"><i class="bi bi-check-circle me-1"></i>Active</span>
                          <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary"><i class="bi bi-x-circle me-1"></i>Inactive</span>
                          <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <div class="dropdown">
                                <button class="btn btn-sm btn-light border dropdown-toggle" data-bs-toggle="dropdown">Actions</button>
                                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                    <li><a class="dropdown-item py-2 text-primary" href="../users/manage-users.php?search=<?php echo urlencode($uu['username']); ?>"><i class="bi bi-pencil me-2"></i>Edit User Profile</a></li>
                                    <li><a class="dropdown-item py-2 text-warning" href="../auth/reset-password.php?user_id=<?php echo (int)$uu['id']; ?>"><i class="bi bi-key me-2"></i>Reset Password</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <form method="post" class="m-0 p-0 d-inline w-100">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action" value="set_user_active">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$uu['id']; ?>">
                                            <input type="hidden" name="client_id" value="<?php echo (int)$clientRow['id']; ?>">
                                            <input type="hidden" name="is_active" value="<?php echo !empty($uu['is_active']) ? 0 : 1; ?>">
                                            <button type="submit" class="dropdown-item py-2 <?php echo !empty($uu['is_active']) ? 'text-danger' : 'text-success'; ?>">
                                                <?php if (!empty($uu['is_active'])): ?><i class="bi bi-person-x me-2"></i>Deactivate<?php else: ?><i class="bi bi-person-check me-2"></i>Activate<?php endif; ?>
                                            </button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
            <?php if (!empty($clientUsers)): ?>
              <nav class="mt-2">
                <ul class="pagination pagination-sm mb-0">
                  <?php
                    $cur = (int)($_GET['user_page'] ?? 1);
                    $totalPages = isset($clientUsersPages) ? (int)$clientUsersPages : 1;
                    $makeUrl = function($p) use ($clientRow) {
                      $q = urlencode((string)($_GET['user_q'] ?? ''));
                      return 'clients.php?client_id='.(int)$clientRow['id'].'&user_q='.$q.'&user_page='.(int)$p;
                    };
                  ?>
                  <li class="page-item <?php echo $cur <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $makeUrl(max(1, $cur-1)); ?>">Prev</a></li>
                  <li class="page-item disabled"><span class="page-link"><?php echo $cur; ?> / <?php echo $totalPages; ?></span></li>
                  <li class="page-item <?php echo $cur >= $totalPages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $makeUrl(min($totalPages, $cur+1)); ?>">Next</a></li>
                </ul>
              </nav>
            <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="modal fade" id="clientModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="save_client">
        <input type="hidden" name="client_id" id="client_id" value="0">
        <div class="modal-header">
          <h5 class="modal-title" id="clientModalTitle">Add Client</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-md-4">
              <label class="form-label small text-muted">Client Code</label>
              <input class="form-control form-control-sm" name="client_code" id="client_code" required>
            </div>
            <div class="col-md-8">
              <label class="form-label small text-muted">Client Name</label>
              <input class="form-control form-control-sm" name="name" id="name" required>
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted">Website</label>
              <input class="form-control form-control-sm" name="website" id="website" placeholder="https://">
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted">Industry</label>
              <input class="form-control form-control-sm" name="industry" id="industry">
            </div>
            <div class="col-md-6">
              <label class="form-label small text-muted">Country</label>
              <input class="form-control form-control-sm" name="country" id="country">
            </div>
            <div class="col-12">
              <label class="form-label small text-muted">Tags</label>
              <input class="form-control form-control-sm" name="tags" id="tags" placeholder="Comma-separated tags">
            </div>
            <div class="col-12">
              <label class="form-label small text-muted">Notes</label>
              <textarea class="form-control form-control-sm" name="notes" id="notes" rows="3"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="clientUserModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="create_client_user">
        <input type="hidden" name="client_id" value="<?php echo (int)($clientRow['id'] ?? 0); ?>">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title"><i class="bi bi-person-plus-fill me-2"></i>Create Client Login</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <?php if (!$clientRow): ?>
            <div class="alert alert-warning">Select a client first.</div>
          <?php else: ?>
            <div class="alert alert-light border mb-4">
              <div class="small text-muted"><i class="bi bi-info-circle me-1"></i>Create an external access account for <strong><?php echo htmlspecialchars($clientRow['name']); ?></strong>. They will only see data related to their own account.</div>
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-bold small">Full Name *</label>
                <input class="form-control" name="full_name" required placeholder="e.g. John Doe">
              </div>
              <div class="col-md-6">
                <label class="form-label fw-bold small">Username *</label>
                <input class="form-control" name="username" required placeholder="Unique ID">
              </div>
              <div class="col-md-12">
                <label class="form-label fw-bold small">Email Address *</label>
                <input class="form-control" name="email" type="email" required placeholder="john@example.com">
              </div>
              <div class="col-md-6">
                <label class="form-label fw-bold small">Role</label>
                <select class="form-select" name="role">
                  <option value="client_admin">Client Admin (Full Access)</option>
                  <option value="client_sdr" selected>Client SDR (Limited Access)</option>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-bold small">Initial Password</label>
                <input class="form-control" name="password" type="text" placeholder="Auto-generated if blank">
              </div>
              <div class="col-12 mt-3">
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" name="is_active" id="clientUserActive" value="1" checked>
                  <label class="form-check-label fw-bold small" for="clientUserActive">Account Active</label>
                </div>
              </div>
            </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer bg-light">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary px-4" <?php echo !$clientRow ? 'disabled' : ''; ?>>Create Login</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
const clientsData = <?php echo json_encode(array_map(function($c){
    return [
        'id'=>(int)$c['id'],
        'client_code'=>$c['client_code'] ?? '',
        'name'=>$c['name'] ?? '',
        'website'=>$c['website'] ?? '',
        'industry'=>$c['industry'] ?? '',
        'country'=>$c['country'] ?? '',
        'notes'=>$c['notes'] ?? '',
        'tags'=>$c['tags'] ?? '',
    ];
}, $clients), JSON_UNESCAPED_UNICODE); ?>;

function openClientModal(id){
  const mTitle = document.getElementById('clientModalTitle');
  const cId = document.getElementById('client_id');
  const code = document.getElementById('client_code');
  const name = document.getElementById('name');
  const website = document.getElementById('website');
  const industry = document.getElementById('industry');
  const country = document.getElementById('country');
  const notes = document.getElementById('notes');
  const tags = document.getElementById('tags');
  const row = clientsData.find(x => x.id === Number(id));
  if(row){
    mTitle.textContent = 'Edit Client';
    cId.value = row.id;
    code.value = row.client_code;
    name.value = row.name;
    website.value = row.website;
    industry.value = row.industry;
    country.value = row.country;
    notes.value = row.notes;
    tags.value = row.tags;
  }else{
    mTitle.textContent = 'Add Client';
    cId.value = 0;
    code.value = '';
    name.value = '';
    website.value = '';
    industry.value = '';
    country.value = '';
    notes.value = '';
    tags.value = '';
  }
}
</script>

<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
