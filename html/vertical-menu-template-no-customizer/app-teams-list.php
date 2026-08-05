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
        <table class="datatables-teams table">
            <thead class="border-top">
            <tr>
                <th></th>
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
                    <button type="button" class="btn btn-label-primary" id="copyKeyBtn">Copy</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal xác nhận xóa (dùng chung cho xóa 1 dòng và Delete Selected) -->
<div class="modal fade" id="deleteTeamModal" tabindex="-1" aria-hidden="true" role="dialog">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm delete!</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to delete <strong id="deleteTeamCount">1</strong> team(s)?
                Teams that still have members, accounts or stores cannot be deleted.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary waves-effect" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary waves-effect waves-light" id="deleteTeamConfirm">Delete</button>
            </div>
        </div>
    </div>
</div>
