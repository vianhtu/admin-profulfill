<?php
if (!checkRoles('view', 'categories')) {
    return;
}
?>
<!-- Filter (collapsible) -->
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
            <div class="d-flex justify-content-between align-items-center row pt-4 gap-4 gap-md-0">
                <div class="col-md-3 category_team d-none"></div>
                <div class="col-md-9"></div>
            </div>
        </div>
    </div>
</div>

<!-- Category List Table -->
<div class="card">
    <div class="card-datatable">
        <table class="datatables-categories table">
            <thead class="border-top">
            <tr>
                <th></th>
                <th></th>
                <th>category</th>
                <th>teams</th>
                <th>products</th>
                <th>ai prompt</th>
                <th>actions</th>
            </tr>
            </thead>
        </table>
    </div>
</div>
