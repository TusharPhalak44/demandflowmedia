<?php
/**
 * Professional User Profile
 * 
 * Allows users to view and update their own profile information, 
 * including profile picture and extended fields.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

requireRole(getKnownRoles());
$currentUser = getCurrentUser();
$userId = (int)($currentUser['id'] ?? 0);
ensureCsrfToken();
ensureDatabaseSchema();

$message = '';
$messageType = '';

$requestedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$isViewMode = $requestedUserId > 0;
$isAdmin = isAdmin();
$isClientAdmin = hasRole('client_admin');
$isVendorAdmin = hasRole('vendor_admin');

$viewingUserId = $isViewMode ? $requestedUserId : $userId;
$isViewingOther = $isViewMode && $viewingUserId > 0 && $viewingUserId !== $userId;

$profileUser = null;
$profileDocs = [];
if ($viewingUserId > 0) {
    $conn = getDbConnection();
    $stmt = $conn->prepare("
        SELECT
            u.*,
            p.personal_email, p.emergency_contact_number, p.date_of_birth,
            b.bank_name, b.account_number, b.account_type, b.ifsc_code, b.pan_number,
            m.full_name AS manager_name
        FROM users u
        LEFT JOIN user_personal_details p ON p.user_id = u.id
        LEFT JOIN user_bank_details b ON b.user_id = u.id
        LEFT JOIN users m ON m.id = u.reporting_manager_id
        WHERE u.id = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('i', $viewingUserId);
        $stmt->execute();
        $profileUser = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
    if ($profileUser) {
        if ($isViewingOther) {
            $ok = false;
            if ($isAdmin) $ok = true;
            if ($isClientAdmin && (int)($currentUser['client_id'] ?? 0) > 0 && (int)($profileUser['client_id'] ?? 0) === (int)($currentUser['client_id'] ?? 0)) $ok = true;
            if ($isVendorAdmin && (int)($currentUser['vendor_id'] ?? 0) > 0 && (int)($profileUser['vendor_id'] ?? 0) === (int)($currentUser['vendor_id'] ?? 0)) $ok = true;
            if (!$ok) $profileUser = null;
        }
        if ($profileUser) {
            $stD = $conn->prepare("SELECT id, category, doc_type, file_path, original_name, file_size, mime_type, uploaded_at FROM user_documents WHERE user_id = ? ORDER BY category, doc_type, id DESC");
            if ($stD) {
                $stD->bind_param('i', $viewingUserId);
                $stD->execute();
                $profileDocs = $stD->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                $stD->close();
            }
        }
    }
}

// Handle Profile Update
if (!$isViewMode && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $message = 'Invalid request token.';
        $messageType = 'danger';
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone_number'] ?? '');
        $jobTitle = trim($_POST['job_title'] ?? '');
        $dept = trim($_POST['department'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $emergency = trim($_POST['emergency_contact'] ?? '');

        if (empty($fullName) || empty($email)) {
            $message = 'Name and Email are required.';
            $messageType = 'danger';
        } else {
            $conn = getDbConnection();
            
            // Handle Profile Picture Upload
            $profilePicPath = $currentUser['profile_pic'] ?? null;
            if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = __DIR__ . '/../../uploads/profiles/';
                if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }
                
                $fileExt = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                
                if (in_array($fileExt, $allowed)) {
                    $newFileName = 'profile_' . $userId . '_' . time() . '.' . $fileExt;
                    $targetPath = $uploadDir . $newFileName;
                    
                    if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $targetPath)) {
                        // Delete old pic if exists
                        if ($profilePicPath && file_exists(__DIR__ . '/../../' . $profilePicPath)) {
                            @unlink(__DIR__ . '/../../' . $profilePicPath);
                        }
                        $profilePicPath = 'uploads/profiles/' . $newFileName;
                    }
                } else {
                    $message = 'Invalid image format. Allowed: JPG, PNG, WEBP.';
                    $messageType = 'danger';
                }
            }

            if (empty($message)) {
                $sql = "UPDATE users SET 
                        full_name = ?, email = ?, phone_number = ?, job_title = ?, 
                        department = ?, address = ?, emergency_contact = ?, profile_pic = ?
                        WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssssssi", $fullName, $email, $phone, $jobTitle, $dept, $address, $emergency, $profilePicPath, $userId);
                
                if ($stmt->execute()) {
                    $message = 'Profile updated successfully.';
                    $messageType = 'success';
                    // Refresh session data
                    refreshUserSession($userId);
                    $currentUser = getCurrentUser();
                } else {
                    $message = 'Failed to update profile.';
                    $messageType = 'danger';
                }
            }
        }
    }
}

?>
<?php $pageTitle = $isViewMode ? 'User Profile' : 'My Profile'; include __DIR__ . '/../../includes/layout/app_start.php'; ?>

    <div class="container-fluid px-0">
        <?php if ($isViewMode): ?>
            <?php if ($profileUser === null): ?>
                <div class="alert alert-danger">User not found or not allowed.</div>
            <?php else: ?>
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <div class="h3 mb-1"><?php echo htmlspecialchars((string)($profileUser['full_name'] ?? 'User Profile')); ?></div>
                        <div class="text-muted small">@<?php echo htmlspecialchars((string)($profileUser['username'] ?? '')); ?> · <?php echo htmlspecialchars((string)($profileUser['email'] ?? '')); ?></div>
                    </div>
                    <div class="d-flex gap-2">
                        <a class="btn btn-light border btn-sm" href="manage-users.php?search=<?php echo urlencode((string)($profileUser['username'] ?? '')); ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
                        <?php if ($isAdmin || $isClientAdmin || $isVendorAdmin): ?>
                            <a class="btn btn-outline-primary btn-sm" href="manage-users.php?search=<?php echo urlencode((string)($profileUser['username'] ?? '')); ?>"><i class="bi bi-pencil me-1"></i>Edit</a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-4">
                        <div class="card">
                            <div class="card-body text-center">
                                <?php if (!empty($profileUser['profile_pic'])): ?>
                                    <img src="../../<?php echo htmlspecialchars((string)$profileUser['profile_pic']); ?>" style="width: 250px; height: 250px; border-radius: 18px; object-fit: cover;">
                                <?php else: ?>
                                    <div class="user-initial" style="width:96px;height:96px;border-radius:18px;font-size:2rem; margin: 0 auto;">
                                        <?php echo strtoupper(substr((string)($profileUser['full_name'] ?? 'U'), 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="mt-3 fw-bold"><?php echo htmlspecialchars((string)($profileUser['full_name'] ?? 'User')); ?></div>
                                <div class="text-muted small">@<?php echo htmlspecialchars((string)($profileUser['username'] ?? '')); ?></div>
                                <div class="mt-2 d-flex justify-content-center gap-2 flex-wrap">
                                    <span class="badge bg-primary-subtle text-primary border"><?php echo htmlspecialchars((string)($profileUser['job_title'] ?? ($profileUser['role'] ?? ''))); ?></span>
                                    <?php if (!empty($profileUser['is_active'])): ?>
                                        <span class="badge bg-success-subtle text-success border border-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary">Inactive</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="card mt-3">
                            <div class="card-header">Basic</div>
                            <div class="card-body">
                                <div class="small text-muted">User Type</div>
                                <div class="fw-semibold mb-2">
                                    <?php
                                        $t = ((int)($profileUser['client_id'] ?? 0) > 0) ? 'Client' : (((int)($profileUser['vendor_id'] ?? 0) > 0) ? 'Vendor' : 'Internal');
                                        echo htmlspecialchars($t);
                                    ?>
                                </div>
                                <div class="small text-muted">Job Title</div>
                                <div class="fw-semibold mb-2"><?php echo htmlspecialchars((string)($profileUser['job_title'] ?? '')); ?></div>
                                <div class="small text-muted">Phone</div>
                                <div class="fw-semibold"><?php echo htmlspecialchars((string)($profileUser['phone_number'] ?? '')); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-8">
                        <div class="row g-3">
                            <div class="col-12">
                                <div class="card">
                                    <div class="card-header">Employment</div>
                                    <div class="card-body">
                                        <div class="row g-2">
                                            <div class="col-md-4">
                                                <div class="small text-muted">Employee ID</div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars((string)($profileUser['employee_id'] ?? '')); ?></div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="small text-muted">Date of Joining</div>
                                                <div class="fw-semibold"><?php echo !empty($profileUser['date_of_joining']) ? htmlspecialchars((string)$profileUser['date_of_joining']) : ''; ?></div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="small text-muted">Department</div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars((string)($profileUser['department'] ?? '')); ?></div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="small text-muted">Job Title</div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars((string)($profileUser['job_title'] ?? '')); ?></div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="small text-muted">Reporting Manager</div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars((string)($profileUser['manager_name'] ?? '')); ?></div>
                                            </div>
                                            <div class="col-12">
                                                <div class="small text-muted">Onboarding Notes</div>
                                                <div class="fw-semibold"><?php echo nl2br(htmlspecialchars((string)($profileUser['onboarding_notes'] ?? ''))); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="card">
                                    <div class="card-header">Personal</div>
                                    <div class="card-body">
                                        <div class="row g-2">
                                            <div class="col-md-6">
                                                <div class="small text-muted">Personal Email</div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars((string)($profileUser['personal_email'] ?? '')); ?></div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="small text-muted">Emergency Contact</div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars((string)($profileUser['emergency_contact_number'] ?? '')); ?></div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="small text-muted">Date of Birth</div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars((string)($profileUser['date_of_birth'] ?? '')); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="card">
                                    <div class="card-header">Bank</div>
                                    <div class="card-body">
                                        <div class="row g-2">
                                            <div class="col-md-6">
                                                <div class="small text-muted">Bank Name</div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars((string)($profileUser['bank_name'] ?? '')); ?></div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="small text-muted">Account Number</div>
                                                <div class="fw-semibold font-monospace"><?php echo htmlspecialchars(maskAccountNumber((string)($profileUser['account_number'] ?? ''))); ?></div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="small text-muted">Account Type</div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars((string)($profileUser['account_type'] ?? '')); ?></div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="small text-muted">IFSC</div>
                                                <div class="fw-semibold font-monospace"><?php echo htmlspecialchars((string)($profileUser['ifsc_code'] ?? '')); ?></div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="small text-muted">PAN</div>
                                                <div class="fw-semibold font-monospace"><?php echo htmlspecialchars((string)($profileUser['pan_number'] ?? '')); ?></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="col-12">
                                <div class="card">
                                    <div class="card-header">Documents</div>
                                    <div class="card-body">
                                        <?php if (empty($profileDocs)): ?>
                                            <div class="text-muted">No documents uploaded.</div>
                                        <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm align-middle mb-0">
                                                    <thead class="table-light">
                                                        <tr>
                                                            <th>Category</th>
                                                            <th>Type</th>
                                                            <th>File</th>
                                                            <th class="text-end">Size</th>
                                                            <th class="text-end">View</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($profileDocs as $d): ?>
                                                            <tr>
                                                                <td class="text-muted small"><?php echo htmlspecialchars((string)($d['category'] ?? '')); ?></td>
                                                                <td class="text-muted small"><?php echo htmlspecialchars((string)($d['doc_type'] ?? '')); ?></td>
                                                                <td class="fw-semibold"><?php echo htmlspecialchars((string)($d['original_name'] ?? '')); ?></td>
                                                                <td class="text-end text-muted small"><?php echo number_format(((int)($d['file_size'] ?? 0)) / 1024, 1); ?> KB</td>
                                                                <td class="text-end">
                                                                    <a class="btn btn-sm btn-outline-primary" target="_blank" href="../../<?php echo htmlspecialchars((string)($d['file_path'] ?? '')); ?>"><i class="bi bi-download"></i></a>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="row g-4">
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-body text-center">
                            <?php if (!empty($currentUser['profile_pic'])): ?>
                                <img src="../../<?php echo htmlspecialchars($currentUser['profile_pic']); ?>" style="width: 250px; height: 250px; border-radius: 18px; object-fit: cover;" id="avatarPreview">
                            <?php else: ?>
                                <div class="user-initial" style="width:96px;height:96px;border-radius:18px;font-size:2rem; margin: 0 auto;">
                                    <?php echo strtoupper(substr($currentUser['full_name'] ?? 'U', 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <div class="mt-3 fw-bold"><?php echo htmlspecialchars($currentUser['full_name'] ?? 'User'); ?></div>
                            <div class="text-muted small">@<?php echo htmlspecialchars($currentUser['username'] ?? 'username'); ?></div>
                            <div class="mt-2">
                                <span class="badge bg-primary-subtle text-primary border"><?php echo htmlspecialchars(strtoupper($currentUser['role'] ?? 'USER')); ?></span>
                            </div>
                            <label for="profile_pic_input" class="btn btn-light border btn-sm mt-3">
                                <i class="bi bi-camera me-1"></i>Change Photo
                            </label>
                        </div>
                    </div>

                    <div class="card mt-4">
                        <div class="card-header">Work Info</div>
                        <div class="card-body">
                            <div class="small text-muted">Employee ID</div>
                            <div class="fw-semibold mb-3"><?php echo htmlspecialchars($currentUser['employee_id'] ?? 'N/A'); ?></div>
                            <div class="small text-muted">Date of Joining</div>
                            <div class="fw-semibold mb-3"><?php echo (!empty($currentUser['date_of_joining'])) ? date('M d, Y', strtotime($currentUser['date_of_joining'])) : 'N/A'; ?></div>
                            <div class="small text-muted">Department</div>
                            <div class="fw-semibold"><?php echo htmlspecialchars($currentUser['department'] ?? 'N/A'); ?></div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-8">
                    <?php if ($message): ?>
                        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show border-0 shadow-sm mb-4" role="alert">
                            <?php echo htmlspecialchars($message); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span>Profile Details</span>
                            <a class="btn btn-light border btn-sm" href="../auth/change-password.php"><i class="bi bi-key me-1"></i>Security</a>
                        </div>
                        <div class="card-body">
                            <form method="post" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="update_profile">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="file" name="profile_pic" id="profile_pic_input" style="display: none;" accept=".jpg,.jpeg,.png,.webp" onchange="previewImage(this)">

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted">Full Name</label>
                                        <input type="text" class="form-control form-control-sm" name="full_name" value="<?php echo htmlspecialchars($currentUser['full_name'] ?? ''); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted">Email</label>
                                        <input type="email" class="form-control form-control-sm" name="email" value="<?php echo htmlspecialchars($currentUser['email'] ?? ''); ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted">Phone</label>
                                        <input type="text" class="form-control form-control-sm" name="phone_number" value="<?php echo htmlspecialchars($currentUser['phone_number'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted">Job Title</label>
                                        <input type="text" class="form-control form-control-sm" name="job_title" value="<?php echo htmlspecialchars($currentUser['job_title'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted">Department</label>
                                        <input type="text" class="form-control form-control-sm" name="department" value="<?php echo htmlspecialchars($currentUser['department'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small text-muted">Emergency Contact</label>
                                        <input type="text" class="form-control form-control-sm" name="emergency_contact" value="<?php echo htmlspecialchars($currentUser['emergency_contact'] ?? ''); ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small text-muted">Address</label>
                                        <textarea class="form-control form-control-sm" name="address" rows="3"><?php echo htmlspecialchars($currentUser['address'] ?? ''); ?></textarea>
                                    </div>
                                    <div class="col-12">
                                        <button type="submit" class="btn btn-primary btn-sm px-4">Save Changes</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <script>
                let cropInputEl = null;
                let cropImg = null;
                let cropUrl = '';
                let cropBaseScale = 1;
                let cropScale = 1;
                let cropX = 0;
                let cropY = 0;
                let cropDrag = null;

                function setProfilePreviewUrl(url) {
                    const preview = document.getElementById('avatarPreview');
                    if (preview && preview.tagName === 'IMG') {
                        preview.src = url;
                    }
                }

                function clampCrop() {
                    const canvas = document.getElementById('cropCanvas');
                    if (!canvas || !cropImg) return;
                    const w = cropImg.width * cropScale;
                    const h = cropImg.height * cropScale;
                    const minX = canvas.width - w;
                    const minY = canvas.height - h;
                    if (w <= canvas.width) cropX = (canvas.width - w) / 2;
                    else cropX = Math.min(0, Math.max(minX, cropX));
                    if (h <= canvas.height) cropY = (canvas.height - h) / 2;
                    else cropY = Math.min(0, Math.max(minY, cropY));
                }

                function drawCrop() {
                    const canvas = document.getElementById('cropCanvas');
                    if (!canvas) return;
                    const ctx = canvas.getContext('2d');
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    ctx.fillStyle = '#f8f9fa';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                    if (!cropImg) return;
                    clampCrop();
                    ctx.imageSmoothingEnabled = true;
                    ctx.imageSmoothingQuality = 'high';
                    ctx.drawImage(cropImg, cropX, cropY, cropImg.width * cropScale, cropImg.height * cropScale);
                }

                async function openCropperForInput(input) {
                    if (!input?.files?.[0]) return;
                    cropInputEl = input;
                    const file = input.files[0];
                    if (!String(file.type || '').startsWith('image/')) return;
                    if (cropUrl) URL.revokeObjectURL(cropUrl);
                    cropUrl = URL.createObjectURL(file);
                    cropImg = new Image();
                    await new Promise((res, rej) => {
                        cropImg.onload = () => res(true);
                        cropImg.onerror = () => rej(new Error('load'));
                        cropImg.src = cropUrl;
                    }).catch(() => null);
                    const canvas = document.getElementById('cropCanvas');
                    const zoom = document.getElementById('cropZoom');
                    if (!canvas || !zoom || !cropImg) return;
                    cropBaseScale = Math.max(canvas.width / cropImg.width, canvas.height / cropImg.height);
                    cropScale = cropBaseScale;
                    zoom.value = '1';
                    cropX = (canvas.width - cropImg.width * cropScale) / 2;
                    cropY = (canvas.height - cropImg.height * cropScale) / 2;
                    drawCrop();
                    new bootstrap.Modal(document.getElementById('cropperModal')).show();
                }

                function previewImage(input) {
                    openCropperForInput(input);
                }

                document.addEventListener('DOMContentLoaded', () => {
                    const canvas = document.getElementById('cropCanvas');
                    const zoom = document.getElementById('cropZoom');
                    const btnCancel = document.getElementById('cropCancel');
                    const btnOrig = document.getElementById('cropUseOriginal');
                    const btnApply = document.getElementById('cropApply');
                    const modalEl = document.getElementById('cropperModal');

                    if (zoom) {
                        zoom.addEventListener('input', () => {
                            if (!cropImg) return;
                            const prevScale = cropScale;
                            cropScale = cropBaseScale * parseFloat(zoom.value || '1');
                            const canvas = document.getElementById('cropCanvas');
                            if (!canvas) return;
                            const cx = canvas.width / 2;
                            const cy = canvas.height / 2;
                            cropX = cx - (cx - cropX) * (cropScale / prevScale);
                            cropY = cy - (cy - cropY) * (cropScale / prevScale);
                            drawCrop();
                        });
                    }

                    if (canvas) {
                        const onDown = (e) => {
                            if (!cropImg) return;
                            canvas.setPointerCapture(e.pointerId);
                            cropDrag = { x: e.clientX, y: e.clientY, ox: cropX, oy: cropY };
                        };
                        const onMove = (e) => {
                            if (!cropDrag) return;
                            cropX = cropDrag.ox + (e.clientX - cropDrag.x);
                            cropY = cropDrag.oy + (e.clientY - cropDrag.y);
                            drawCrop();
                        };
                        const onUp = () => { cropDrag = null; };
                        canvas.addEventListener('pointerdown', onDown);
                        canvas.addEventListener('pointermove', onMove);
                        canvas.addEventListener('pointerup', onUp);
                        canvas.addEventListener('pointercancel', onUp);
                        canvas.addEventListener('pointerleave', onUp);
                    }

                    if (btnCancel) {
                        btnCancel.addEventListener('click', () => {
                            if (cropInputEl) cropInputEl.value = '';
                            bootstrap.Modal.getInstance(modalEl)?.hide();
                        });
                    }

                    if (btnOrig) {
                        btnOrig.addEventListener('click', () => {
                            const f = cropInputEl?.files?.[0];
                            if (!f) return;
                            const r = new FileReader();
                            r.onload = (e) => setProfilePreviewUrl(String(e.target?.result || ''));
                            r.readAsDataURL(f);
                            bootstrap.Modal.getInstance(modalEl)?.hide();
                        });
                    }

                    if (btnApply) {
                        btnApply.addEventListener('click', async () => {
                            const input = cropInputEl;
                            if (!input?.files?.[0] || !cropImg) return;
                            const canvas = document.getElementById('cropCanvas');
                            if (!canvas) return;

                            const out = document.createElement('canvas');
                            out.width = canvas.width;
                            out.height = canvas.height;
                            const ctx = out.getContext('2d');
                            ctx.imageSmoothingEnabled = true;
                            ctx.imageSmoothingQuality = 'high';
                            ctx.fillStyle = '#ffffff';
                            ctx.fillRect(0, 0, out.width, out.height);
                            ctx.drawImage(cropImg, cropX, cropY, cropImg.width * cropScale, cropImg.height * cropScale);

                            const blob = await new Promise((res) => out.toBlob(res, 'image/jpeg', 0.92));
                            if (!blob) return;
                            const newFile = new File([blob], 'profile.jpg', { type: 'image/jpeg' });
                            const dt = new DataTransfer();
                            dt.items.add(newFile);
                            input.files = dt.files;
                            setProfilePreviewUrl(URL.createObjectURL(newFile));
                            bootstrap.Modal.getInstance(modalEl)?.hide();
                        });
                    }
                });
            </script>

            <div class="modal fade" id="cropperModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content border-0 shadow">
                        <div class="modal-header">
                            <h5 class="modal-title">Crop Profile Photo</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="d-flex justify-content-center">
                                <canvas id="cropCanvas" width="320" height="320" class="border rounded" style="touch-action:none; background:#f8f9fa;"></canvas>
                            </div>
                            <div class="mt-3">
                                <label class="form-label small text-muted mb-1">Zoom</label>
                                <input type="range" class="form-range" id="cropZoom" min="1" max="3" step="0.01" value="1">
                                <div class="small text-muted">Drag photo to position</div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light border btn-sm" id="cropCancel">Cancel</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="cropUseOriginal">Use Original</button>
                            <button type="button" class="btn btn-primary btn-sm" id="cropApply">Crop & Use</button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
<?php include __DIR__ . '/../../includes/layout/app_end.php'; ?>
