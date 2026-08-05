<?php
if (!checkRoles('view', 'store')) {
    return;
}
?>
<!-- Filter -->
<div class="card card-action mb-6" id="filterCard">
    <div class="card-header">
        <h5 class="card-action-title mb-0">
            Filter
            <span id="activeFilterCount" class="badge bg-label-primary ms-2 d-none">0</span>
        </h5>
        <div class="card-action-element">
            <ul class="list-inline mb-0 d-flex align-items-center gap-2">
                <li class="list-inline-item me-0">
                    <button type="button" class="btn btn-label-secondary btn-sm" id="clearFilters" disabled>
                        <i class="icon-base ti tabler-filter-off icon-xs me-1"></i>Clear Filters
                    </button>
                </li>
                <li class="list-inline-item">
                    <a href="javascript:void(0);" class="card-collapsible"><i class="icon-base ti tabler-chevron-up"></i></a>
                </li>
            </ul>
        </div>
    </div>
    <div class="collapse show" id="filterBody">
        <div class="card-body pt-0">
            <div class="row g-4 pt-4">
                <div class="col-md-3 store_site"></div>
                <div class="col-md-3 store_status"></div>
                <?php if (is_admin()): // lọc theo team chỉ dành cho admin ?>
                    <div class="col-md-3 store_team"></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!is_admin()): ?>
    <div class="alert alert-info d-flex align-items-center mb-6" role="alert">
        <i class="icon-base ti tabler-info-circle icon-22px me-3"></i>
        <span><strong>Shared</strong> stores belong to every team so the same shop is never entered twice — only an admin can change those. Stores you add belong to your team, and only your team can see and change them.</span>
    </div>
<?php endif; ?>

<!-- Delete Store Modal: xóa nhiều store / store nhiều sản phẩm nên phải chạy theo lô có tiến trình -->
<div class="modal fade" id="deleteStoreModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete stores</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Delete <span id="deleteStoreCount" class="fw-bold">0</span> store(s)?</p>
                <div id="deleteStoreWarning" class="alert alert-warning mb-0 d-none" role="alert">
                    <span id="deleteStoreProducts" class="fw-bold">0</span> products still use them. They will be set to
                    <strong>Inactive</strong> and unlinked from the store. This cannot be undone.
                </div>
                <div id="deleteStoreProgress" class="d-none mt-4">
                    <div class="progress mb-2" style="height: 8px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" id="deleteStoreProgressBar" style="width: 0%"></div>
                    </div>
                    <small id="deleteStoreProgressText" class="text-body-secondary"></small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal" id="deleteStoreCancel">Cancel</button>
                <button type="button" class="btn btn-danger" id="deleteStoreConfirm">
                    <span class="spinner-border spinner-border-sm me-2 d-none" id="deleteStoreSpinner" role="status"></span>Delete
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Store List Table -->
<div class="card">
    <div class="card-datatable">
        <table class="datatables-stores table">
            <thead class="border-top">
            <tr>
                <th></th>
                <th></th>
                <th>store</th>
                <th>slug</th>
                <th>site</th>
                <th>owner</th>
                <th>products</th>
                <th>status</th>
                <th>actions</th>
            </tr>
            </thead>
        </table>
    </div>
</div>
