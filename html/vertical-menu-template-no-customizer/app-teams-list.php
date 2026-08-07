<?php
// Teams là menu ADMIN-ONLY (chốt 05/08/2026) — không đi qua roles_permissions.
// Lớp UI: không phải admin thì không render gì; endpoint vẫn tự kiểm lại.
if (!is_admin()) {
    return;
}
?>
<!-- Teams List Table -->
<div class="card">
    <div class="card-datatable">
        <!-- Không có cột checkbox: Teams chỉ xóa từng dòng, không có thao tác hàng loạt -->
        <table class="datatables-teams table">
            <thead class="border-top">
            <tr>
                <th></th>
                <th>Team</th>
                <th>Members</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
            </thead>
        </table>
    </div>

    <!-- Offcanvas Add/Edit team: form chỉ gồm Name + Status; cột `key` server tự sinh, không hiển thị -->
    <!-- data-bs-scroll: giữ scrollbar của body khi mở -> bảng không bị xô ngang -->
    <div class="offcanvas offcanvas-end" data-bs-scroll="true" tabindex="-1" id="offcanvasTeam" aria-labelledby="offcanvasTeamLabel">
        <div class="offcanvas-header border-bottom">
            <h5 id="offcanvasTeamLabel" class="offcanvas-title">Add New Team</h5>
            <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body mx-0 flex-grow-0 p-6 h-100">
            <form class="pt-0" id="teamForm" onsubmit="return false">
                <input type="hidden" id="team-id" value="0">
                <div class="mb-6 form-control-validation">
                    <label class="form-label" for="team-name">Team Name</label>
                    <input type="text" class="form-control" id="team-name" placeholder="Team name" maxlength="100" />
                    <div class="invalid-feedback">Please enter a team name.</div>
                </div>
                <div class="mb-6">
                    <label class="form-label" for="team-status">Status</label>
                    <select id="team-status" class="form-select">
                        <option value="1">Active</option>
                        <option value="0">Inactive</option>
                    </select>
                    <div class="form-text text-warning">
                        <i class="icon-base ti tabler-alert-triangle icon-14px me-1"></i>
                        Setting a team to Inactive blocks everything for its members: they cannot
                        sign in, active sessions are ended, and their extension API keys stop working.
                    </div>
                </div>
                <button type="button" class="btn btn-primary me-3" id="teamSubmit">Save</button>
                <button type="reset" class="btn btn-label-danger" data-bs-dismiss="offcanvas">Cancel</button>
            </form>
        </div>
    </div>
</div>

<!-- Modal xem key của team (credential extension — chỉ admin thấy trang này) -->
<div class="modal fade" id="viewKeyModal" tabindex="-1" aria-hidden="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Team Key — <span id="viewKeyTeamName"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="input-group">
                    <input type="text" class="form-control font-monospace" id="viewKeyValue" readonly>
                    <!-- Nút icon cho gọn, cùng khuôn với ô API key ở trang Account -->
                    <button type="button" class="btn btn-outline-secondary" id="copyKeyBtn"
                            title="Copy key">
                        <i class="icon-base ti tabler-copy"></i>
                    </button>
                    <button type="button" class="btn btn-outline-warning" id="newKeyBtn"
                            title="Generate new key">
                        <i class="icon-base ti tabler-refresh"></i>
                    </button>
                </div>
                <div class="form-text" id="viewKeyHint">
                    The extension authenticates with this key. Generating a new one stops the
                    old key working immediately.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal xóa MỘT team: liệt kê thứ sẽ mất, thứ được giữ, rồi chạy tiến trình theo lô -->
<div class="modal fade" id="deleteTeamModal" tabindex="-1" aria-hidden="true" role="dialog"
     data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">Delete team permanently</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" id="deleteTeamClose"></button>
            </div>
            <div class="modal-body">
                <p class="mb-4">
                    Deleting <strong id="deleteTeamName"></strong> also removes everything that
                    belongs only to this team. This cannot be undone.
                </p>

                <div id="deleteTeamLoading" class="text-center py-4">
                    <span class="spinner-border spinner-border-sm me-2" role="status"></span>Checking data...
                </div>

                <!-- Rào chắn 2 bước: team còn Active thì không cho xóa/sáp nhập -->
                <div id="deleteTeamActiveWarn" class="alert alert-warning d-flex d-none" role="alert">
                    <i class="icon-base ti tabler-alert-triangle me-2"></i>
                    <span>This team is still <strong>Active</strong>. Set it to Inactive first — its members
                          lose access immediately, so you can check nothing breaks before removing anything.</span>
                </div>

                <div id="deleteTeamSummary" class="d-none">
                    <table class="table table-sm mb-4">
                        <tbody>
                        <tr><td>Members</td><td class="text-end fw-medium" id="cntMembers">0</td></tr>
                        <tr><td>Accounts</td><td class="text-end fw-medium" id="cntAccounts">0</td></tr>
                        <tr><td>Products</td><td class="text-end fw-medium" id="cntProducts">0</td></tr>
                        <tr><td>Orders</td><td class="text-end fw-medium" id="cntOrders">0</td></tr>
                        <tr><td>Private stores</td><td class="text-end fw-medium" id="cntStores">0</td></tr>
                        <tr><td>Account files</td><td class="text-end fw-medium" id="cntFiles">0</td></tr>
                        <tr><td>API keys &amp; settings</td><td class="text-end fw-medium" id="cntSettings">0</td></tr>
                        <tr><td>Phone numbers</td><td class="text-end fw-medium" id="cntPhones">0</td></tr>
                        <tr><td>SMS messages</td><td class="text-end fw-medium" id="cntMessages">0</td></tr>
                        </tbody>
                    </table>
                    <div class="alert alert-info d-flex" role="alert">
                        <i class="icon-base ti tabler-info-circle me-2"></i>
                        <span>Shared data is kept: sites, categories and shared stores stay in place.</span>
                    </div>

                    <!-- Sáp nhập: giữ toàn bộ dữ liệu, chỉ đổi chủ sang team khác -->
                    <div id="deleteTeamMergeBox">
                        <hr class="my-4">
                        <label class="form-label" for="deleteTeamTarget">
                            Or move everything to another team instead of deleting
                        </label>
                        <select id="deleteTeamTarget" class="form-select"></select>
                        <div class="form-text">Members, accounts and private stores move over; nothing is lost.</div>
                    </div>
                </div>

                <div id="deleteTeamProgress" class="d-none mt-4">
                    <div class="progress mb-2" style="height: 8px;">
                        <div class="progress-bar bg-danger" id="deleteTeamBar" style="width: 0%"></div>
                    </div>
                    <small class="text-body-secondary" id="deleteTeamProgressText"></small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary waves-effect" data-bs-dismiss="modal" id="deleteTeamCancel">Cancel</button>
                <button type="button" class="btn btn-label-primary waves-effect" id="deleteTeamMerge" disabled>
                    <i class="icon-base ti tabler-arrow-move-right icon-xs me-1"></i>Move &amp; delete team
                </button>
                <button type="button" class="btn btn-danger waves-effect waves-light" id="deleteTeamConfirm" disabled>
                    <span class="spinner-border spinner-border-sm me-2 d-none" id="deleteTeamSpinner" role="status"></span>Delete everything
                </button>
            </div>
        </div>
    </div>
</div>
