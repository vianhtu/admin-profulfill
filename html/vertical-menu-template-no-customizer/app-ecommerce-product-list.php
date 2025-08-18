<!-- Product List Widget -->
<div class="card mb-6">
    <div class="card-widget-separator-wrapper">
        <div class="card-body card-widget-separator">
            <div class="row gy-4 gy-sm-1">
                <div class="col-sm-6 col-lg-3">
                    <div
                            class="d-flex justify-content-between align-items-start card-widget-1 border-end pb-4 pb-sm-0">
                        <div>
                            <p class="mb-1">In-store Sales</p>
                            <h4 class="mb-1">$5,345.43</h4>
                            <p class="mb-0">
                                <span class="me-2">5k orders</span><span class="badge bg-label-success">+5.7%</span>
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
                            <p class="mb-1">Website Sales</p>
                            <h4 class="mb-1">$674,347.12</h4>
                            <p class="mb-0">
                                <span class="me-2">21k orders</span><span class="badge bg-label-success">+12.4%</span>
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
                            <p class="mb-1">Discount</p>
                            <h4 class="mb-1">$14,235.12</h4>
                            <p class="mb-0">6k orders</p>
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
                            <p class="mb-1">Affiliate</p>
                            <h4 class="mb-1">$8,345.23</h4>
                            <p class="mb-0">
                                <span class="me-2">150 orders</span><span class="badge bg-label-danger">-3.5%</span>
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

<!-- Product List Table -->
<div class="card">
    <div class="card-header border-bottom">
        <h5 class="card-title">Filter</h5>
        <div class="d-flex justify-content-between align-items-center row pt-4 gap-6 gap-md-0 g-md-6">
            <div class="col-md-4 product_status">
                <label for="date_to" class="form-label">Status</label>
            </div>
            <div class="col-md-4 product_category">
                <label for="date_to" class="form-label">Category</label>
            </div>
            <div class="col-md-4 product_stock">
                <label for="date_to" class="form-label">Manager</label>
            </div>
            <div class="col-md-4 product_from_date">
                <label for="date_from" class="form-label">From</label>
                <input type="date" class="form-control" id="minDate" min="2025-01-01">
            </div>
            <div class="col-md-4 product_to_date">
                <label for="date_to" class="form-label">To</label>
                <input type="date" class="form-control" id="maxDate" min="2025-01-01">
            </div>
            <div class="col-md-4 product_store"></div>
        </div>
    </div>
    <div class="card-datatable">
        <table class="datatables-products table">
            <thead class="border-top">
            <tr>
                <th></th>
                <th></th>
                <th>product</th>
                <th>category</th>
                <th>stock</th>
                <th>sku</th>
                <th>price</th>
                <th>qty</th>
                <th>status</th>
                <th>actions</th>
            </tr>
            </thead>
        </table>
    </div>
</div>
</div>
<!-- / Content -->