<?php
/**
 * Trang Account — theo khuôn `pages-account-settings-account.html` + `-security.html` của
 * Vuexy (2 tab: Account / Security), rút gọn còn đúng những gì site này có dữ liệu thật:
 * ảnh đại diện, tên đăng nhập, email, cấp/team/trạng thái/ngày vào (chỉ đọc), lương, API key
 * của extension, đổi mật khẩu, và thiết bị đã ghi nhớ đăng nhập (author_remember_tokens).
 *
 * Lớp UI: xem hồ sơ NGƯỜI KHÁC thì cả 2 khối ghi (form sửa + đổi mật khẩu) và khối thiết bị
 * KHÔNG render — ẩn hẳn chứ không khoá, vì chúng không gắn với dòng nào của bảng mà là cả
 * một vùng chức năng. Endpoint vẫn tự kiểm lại.
 */
$acc_id  = (int)($_GET['id'] ?? 0);
$acc_row = Account::viewable($acc_id);
if (!$acc_row) {
    ?>
    <div class="alert alert-danger" role="alert">
        <h6 class="alert-heading mb-1">Account not available</h6>
        You do not have permission to view this account, or it no longer exists.
    </div>
    <?php
    return;
}
$acc_la_toi = (int)$acc_row['ID'] === (int)($_SESSION['auth']['user_id'] ?? 0);
// Lương của CHÍNH MÌNH thì luôn được xem — tiền của mình.
$acc_xem_luong = $acc_la_toi || Users::can_see_salary();
?>
<h4 class="mb-1">
    <?= $acc_la_toi ? 'My Account' : h((string)$acc_row['username']) ?>
</h4>
<p class="mb-6 text-body-secondary">
    <?= $acc_la_toi
        ? 'Your profile, password and signed-in devices.'
        : 'You are viewing someone else\'s account. This page is read-only.' ?>
</p>

<div class="row">
    <div class="col-12">
        <ul class="nav nav-pills flex-column flex-md-row mb-6">
            <li class="nav-item">
                <a class="nav-link active" href="javascript:void(0);" data-bs-toggle="tab" data-bs-target="#tab-account">
                    <i class="icon-base ti tabler-users icon-sm me-1_5"></i>Account
                </a>
            </li>
            <?php if ($acc_la_toi) : ?>
                <li class="nav-item">
                    <a class="nav-link" href="javascript:void(0);" data-bs-toggle="tab" data-bs-target="#tab-security">
                        <i class="icon-base ti tabler-lock icon-sm me-1_5"></i>Security
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </div>
</div>

<div class="tab-content p-0">
    <!-- ============ TAB ACCOUNT ============ -->
    <div class="tab-pane fade show active" id="tab-account">
        <div class="card mb-6">
            <div class="card-body">
                <div class="d-flex align-items-start align-items-sm-center gap-6">
                    <div class="avatar avatar-xl">
                        <span class="avatar-initial rounded bg-label-secondary" id="acc-avatar-initial">?</span>
                        <img id="acc-avatar-preview" src="" alt="Avatar" class="rounded d-none"
                             style="object-fit:cover;width:100%;height:100%;">
                    </div>
                    <?php if ($acc_la_toi) : ?>
                        <div class="button-wrapper">
                            <label class="btn btn-primary me-3 mb-4" for="acc-avatar-file" tabindex="0">
                                <i class="icon-base ti tabler-upload icon-xs me-1"></i>Upload new photo
                                <input type="file" id="acc-avatar-file" accept="image/png,image/jpeg" hidden>
                            </label>
                            <button type="button" class="btn btn-label-secondary mb-4" id="acc-avatar-reset">
                                <i class="icon-base ti tabler-reset icon-xs me-1"></i>Reset
                            </button>
                            <div class="form-text" id="acc-avatar-hint">PNG or JPG, max 2 MB. Resized to 96×96.</div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card-body pt-4">
                <form id="accProfileForm" onsubmit="return false">
                    <input type="hidden" id="acc-avatar" value="">
                    <div class="row gy-4 gx-6 mb-6">
                        <div class="col-md-6 form-control-validation">
                            <label class="form-label" for="acc-username">Username</label>
                            <input type="text" class="form-control" id="acc-username" maxlength="100"
                                   <?= $acc_la_toi ? '' : 'disabled' ?>>
                            <div class="invalid-feedback">Please enter a username.</div>
                        </div>
                        <div class="col-md-6 form-control-validation">
                            <label class="form-label" for="acc-email">Email</label>
                            <input type="email" class="form-control" id="acc-email" maxlength="100"
                                   <?= $acc_la_toi ? '' : 'disabled' ?>>
                            <div class="invalid-feedback">Please enter a valid email.</div>
                        </div>

                        <!-- Chỉ đọc với MỌI người: 3 trường này chỉ đổi được ở menu Users,
                             và không ai tự đổi của chính mình (tự nâng quyền / tự khoá) -->
                        <div class="col-md-6">
                            <label class="form-label" for="acc-role">Role</label>
                            <input type="text" class="form-control" id="acc-role" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="acc-team">Team</label>
                            <input type="text" class="form-control" id="acc-team" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="acc-status">Status</label>
                            <input type="text" class="form-control" id="acc-status" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="acc-date">Member since</label>
                            <input type="text" class="form-control" id="acc-date" disabled>
                        </div>
                        <?php if ($acc_xem_luong) : ?>
                            <div class="col-md-6">
                                <label class="form-label" for="acc-wage">Salary</label>
                                <input type="text" class="form-control" id="acc-wage" disabled>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="acc-insurance">Insurance</label>
                                <input type="text" class="form-control" id="acc-insurance" disabled>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($acc_la_toi) : ?>
                        <div class="alert alert-info d-flex" role="alert">
                            <i class="icon-base ti tabler-info-circle me-2"></i>
                            <span>Role, team and status can only be changed by someone who manages
                                  your account.</span>
                        </div>
                        <button type="button" class="btn btn-primary me-3" id="accProfileSave">Save changes</button>
                        <button type="button" class="btn btn-label-secondary" id="accProfileReset">Reset</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <?php if ($acc_la_toi) : ?>
            <!-- API key của extension: bí mật đăng nhập nên MẶC ĐỊNH che, chỉ chủ tài khoản
                 mới nhận được giá trị từ endpoint (admin xem hồ sơ người khác cũng không) -->
            <div class="card mb-6">
                <h5 class="card-header">Extension API key</h5>
                <div class="card-body">
                    <p class="text-body-secondary">
                        The Chrome extension uses this key to push listings into your account.
                        Treat it like a password.
                    </p>
                    <div class="input-group">
                        <input type="password" class="form-control" id="acc-api-key" value="" readonly>
                        <button class="btn btn-outline-secondary" type="button" id="acc-key-toggle"
                                title="Show/hide">
                            <i class="icon-base ti tabler-eye"></i>
                        </button>
                        <button class="btn btn-outline-secondary" type="button" id="acc-key-copy"
                                title="Copy">
                            <i class="icon-base ti tabler-copy"></i>
                        </button>
                    </div>
                    <div class="form-text" id="acc-key-hint"></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ============ TAB SECURITY (chỉ của chính mình) ============ -->
    <?php if ($acc_la_toi) : ?>
        <div class="tab-pane fade" id="tab-security">
            <div class="card mb-6">
                <h5 class="card-header">Change password</h5>
                <div class="card-body">
                    <form id="accPasswordForm" onsubmit="return false">
                        <div class="row gy-4 gx-6">
                            <div class="col-md-6 form-control-validation">
                                <label class="form-label" for="acc-cur-pass">Current password</label>
                                <input type="password" class="form-control" id="acc-cur-pass"
                                       autocomplete="current-password">
                                <div class="invalid-feedback">Please enter your current password.</div>
                            </div>
                        </div>
                        <div class="row gy-4 gx-6 mt-0">
                            <div class="col-md-6 form-control-validation">
                                <label class="form-label" for="acc-new-pass">New password</label>
                                <input type="password" class="form-control" id="acc-new-pass"
                                       autocomplete="new-password">
                                <div class="invalid-feedback">At least 8 characters.</div>
                            </div>
                            <div class="col-md-6 form-control-validation">
                                <label class="form-label" for="acc-new-pass2">Confirm new password</label>
                                <input type="password" class="form-control" id="acc-new-pass2"
                                       autocomplete="new-password">
                                <div class="invalid-feedback">The two passwords do not match.</div>
                            </div>
                        </div>
                        <div class="alert alert-warning d-flex mt-6" role="alert">
                            <i class="icon-base ti tabler-alert-triangle me-2"></i>
                            <span>Changing your password signs out every remembered device,
                                  including this one if it was remembered.</span>
                        </div>
                        <button type="button" class="btn btn-primary" id="accPasswordSave">Change password</button>
                    </form>
                </div>
            </div>

            <div class="card mb-6">
                <h5 class="card-header d-flex justify-content-between align-items-center">
                    <span>Remembered devices</span>
                    <button type="button" class="btn btn-label-danger btn-sm" id="accRevokeAll">
                        <i class="icon-base ti tabler-logout icon-xs me-1"></i>Sign out all
                    </button>
                </h5>
                <div class="card-body">
                    <p class="text-body-secondary">
                        Devices that can sign in without a password until the date shown.
                        Only a fingerprint of each browser is stored, never its full details.
                    </p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                            <tr>
                                <th>Device</th>
                                <th>Signed in</th>
                                <th>Expires</th>
                                <th class="text-end">Actions</th>
                            </tr>
                            </thead>
                            <tbody id="accDeviceRows">
                            <tr><td colspan="4" class="text-center text-body-secondary">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
