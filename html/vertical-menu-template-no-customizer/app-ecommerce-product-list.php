<?php
if(!checkRoles('view', 'products')){
    return;
}
$info = getAuthorsProductInfo();
?>
<!-- Product List Widget -->
<div class="card mb-6">
    <div class="card-widget-separator-wrapper">
        <div class="card-body card-widget-separator">
            <div class="row gy-4 gy-sm-1">
                <div class="col-sm-6 col-lg-3">
                    <div
                            class="d-flex justify-content-between align-items-start card-widget-1 border-end pb-4 pb-sm-0">
                        <div>
                            <p class="mb-1">Total Products</p>
                            <h4 class="mb-1"><?php echo number_format($info['total_items']); ?></h4>
                            <p class="mb-0">
                                <span class="me-2">this month: <?php echo number_format($info['total_this_month']); ?></span>
                            </p>
                        </div>
                        <span class="avatar me-sm-6">
                            <span class="avatar-initial rounded"
                            ><i class="icon-base ti tabler-smart-home icon-28px text-heading"></i
                            ></span>
                          </span>
                    </div>
                    <hr class="d-none d-sm-block d-lg-none me-6" />
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div
                            class="d-flex justify-content-between align-items-start card-widget-2 border-end pb-4 pb-sm-0">
                        <div>
                            <p class="mb-1">Total Pending</p>
                            <h4 class="mb-1"><?php echo number_format($info['pending_items']); ?></h4>
                            <p class="mb-0">
                                <span class="me-2">this month: <?php echo number_format($info['pending_this_month']); ?></span>
                            </p>
                        </div>
                        <span class="avatar p-2 me-lg-6">
                            <span class="avatar-initial rounded"
                            ><i class="icon-base ti tabler-device-laptop icon-28px text-heading"></i
                            ></span>
                          </span>
                    </div>
                    <hr class="d-none d-sm-block d-lg-none" />
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div
                            class="d-flex justify-content-between align-items-start border-end pb-4 pb-sm-0 card-widget-3">
                        <div>
                            <p class="mb-1">Your items</p>
                            <h4 class="mb-1"><?php echo number_format($info['author_items']); ?></h4>
                            <p class="mb-0">taget: 100K</p>
                        </div>
                        <span class="avatar p-2 me-sm-6">
                            <span class="avatar-initial rounded"
                            ><i class="icon-base ti tabler-gift icon-28px text-heading"></i
                            ></span>
                          </span>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <p class="mb-1">Your Profit</p>
                            <h4 class="mb-1"><?php echo number_format($info['author_items'] * 60); ?></h4>
                            <p class="mb-0">
                                <span class="me-2">total paid: 0.0</span>
                            </p>
                        </div>
                        <span class="avatar p-2">
                            <span class="avatar-initial rounded"
                            ><i class="icon-base ti tabler-wallet icon-28px text-heading"></i
                            ></span>
                          </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filter & Export (collapsible) -->
<div class="card card-action mb-6" id="filterExportCard">
    <div class="card-header">
        <h5 class="card-action-title mb-0">
            Filter &amp; Export
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
    <div class="collapse show" id="filterExportBody">
        <div class="card-body pt-0">
            <div class="d-flex justify-content-between align-items-center row pt-4 gap-4 gap-md-0">
                <div class="col-md-3 product_status"></div>
                <div class="col-md-3 product_category"></div>
                <div class="col-md-3 product_author"></div>
                <div class="col-md-3 product_store"></div>
            </div>
            <div class="d-flex justify-content-between align-items-center row pt-4 gap-4 gap-md-0">
                <div class="col-md-3 product_from_date"></div>
                <div class="col-md-3 product_to_date"></div>
                <div class="col-md-3 product_accounts"></div>
                <div class="col-md-3 product_sites"></div>
            </div>
            <div class="d-flex justify-content-between align-items-center row pt-4 gap-4 gap-md-0">
                <div class="col-md-3 export_accounts"></div>
                <div class="col-md-3 export_file"></div>
                <div class="col-md-2 export_limited"></div>
                <div class="col-md-2 export_offset"></div>
                <div class="col-md-2 export_save mt-auto"></div>
            </div>
        </div>
    </div>
</div>

<!-- Product List Table -->
<div class="card">
    <div class="card-datatable">
        <table class="datatables-products table">
            <thead class="border-top">
            <tr>
                <th></th>
                <th></th>
                <th>product</th>
                <th>sku</th>
                <th>category</th>
                <th>author</th>
                <th>badge</th>
                <th>date</th>
                <th>status</th>
                <th>actions</th>
            </tr>
            </thead>
        </table>
    </div>
</div>

<!-- Bulk Edit Modal -->
<div class="modal fade" id="bulkEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Bulk Edit</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-4">Selected <span id="bulkEditCount" class="fw-bold">0</span> products</p>
                <div class="mb-2">
                    <label class="form-label" for="bulkTypeSelect">Type</label>
                    <select id="bulkTypeSelect" class="form-select"></select>
                </div>
                <div class="mb-2">
                    <label class="form-label" for="bulkStatusSelect">Status</label>
                    <select id="bulkStatusSelect" class="form-select"></select>
                </div>
                <div id="bulkEditProgress" class="d-none mt-4">
                    <div class="progress mb-2" style="height: 8px;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" id="bulkEditProgressBar" style="width: 0%"></div>
                    </div>
                    <small id="bulkEditProgressText" class="text-body-secondary"></small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="bulkEditApply">
                    <span class="spinner-border spinner-border-sm me-2 d-none" id="bulkEditSpinner" role="status"></span>Apply
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Product Images Modal -->
<div class="modal fade" id="productImagesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-truncate" id="productImagesTitle">Images</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="swiper" id="swiper-product-images" style="min-height: 200px;">
                    <div class="swiper-wrapper"></div>
                    <div class="swiper-pagination"></div>
                    <div class="swiper-button-next swiper-button-white"></div>
                    <div class="swiper-button-prev swiper-button-white"></div>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
<!-- / Content -->