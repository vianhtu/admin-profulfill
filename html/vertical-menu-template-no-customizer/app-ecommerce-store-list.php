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
            </div>
        </div>
    </div>
</div>

<?php if (!is_admin()): ?>
    <div class="alert alert-info d-flex align-items-center mb-6" role="alert">
        <i class="icon-base ti tabler-info-circle icon-22px me-3"></i>
        <span>Stores are shared by every team so the same shop is never entered twice. Only an admin can change them.</span>
    </div>
<?php endif; ?>

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
                <th>products</th>
                <th>status</th>
                <th>actions</th>
            </tr>
            </thead>
        </table>
    </div>
</div>
