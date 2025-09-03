<?php
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

<!-- Product List Table -->
<div class="card">
    <div class="card-header border-bottom">
        <div class="d-flex align-items-center justify-content-between" data-bs-toggle="collapse" data-bs-target="#filterContent" aria-expanded="false" aria-controls="filterContent" style="cursor: pointer;">
            <h5 class="card-title mb-0">Filter & Export</h5>
            <i class="fa-solid fa-filter"></i>
        </div>
        <div class="collapse" id="filterContent">
            <div class="d-flex justify-content-between align-items-center row pt-4 gap-6 gap-md-0 g-md-6">
                <div class="col-md-3 product_status"></div>
                <div class="col-md-3 product_category"></div>
                <div class="col-md-3 product_stock"></div>
                <div class="col-md-3 product_store"></div>
                <div class="col-md-2 product_from_date"></div>
                <div class="col-md-2 product_to_date"></div>
                <div class="col-md-3 product_accounts"></div>
                <div class="col-md-5 product_sites"></div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-3 export_accounts"></div>
            <div class="col-md-3 export_configs"></div>
            <div class="col-md-2 export_limited"></div>
            <div class="col-md-2 export_offset"></div>
            <div class="col-md-2 export_save mt-auto"></div>
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