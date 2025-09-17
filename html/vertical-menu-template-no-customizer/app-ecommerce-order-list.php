<div class="card mb-6">
    <div class="card-widget-separator-wrapper">
        <div class="card-body card-widget-separator">
            <div class="row gy-4 gy-sm-1">
                <div class="col-sm-6 col-lg-3">
                    <div
                            class="d-flex justify-content-between align-items-start card-widget-1 border-end pb-4 pb-sm-0">
                        <div>
                            <h4 class="mb-0">56</h4>
                            <p class="mb-0">Pending Payment</p>
                        </div>
                        <span class="avatar me-sm-6">
                        <span class="avatar-initial bg-label-secondary rounded text-heading">
                          <i class="icon-base ti tabler-calendar-stats icon-26px text-heading"></i>
                        </span>
                      </span>
                    </div>
                    <hr class="d-none d-sm-block d-lg-none me-6" />
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div
                            class="d-flex justify-content-between align-items-start card-widget-2 border-end pb-4 pb-sm-0">
                        <div>
                            <h4 class="mb-0">12,689</h4>
                            <p class="mb-0">Completed</p>
                        </div>
                        <span class="avatar p-2 me-lg-6">
                        <span class="avatar-initial bg-label-secondary rounded"
                        ><i class="icon-base ti tabler-checks icon-26px text-heading"></i
                            ></span>
                      </span>
                    </div>
                    <hr class="d-none d-sm-block d-lg-none" />
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div
                            class="d-flex justify-content-between align-items-start border-end pb-4 pb-sm-0 card-widget-3">
                        <div>
                            <h4 class="mb-0">124</h4>
                            <p class="mb-0">Refunded</p>
                        </div>
                        <span class="avatar p-2 me-sm-6">
                        <span class="avatar-initial bg-label-secondary rounded"
                        ><i class="icon-base ti tabler-wallet icon-26px text-heading"></i
                            ></span>
                      </span>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h4 class="mb-0">32</h4>
                            <p class="mb-0">Failed</p>
                        </div>
                        <span class="avatar p-2">
                        <span class="avatar-initial bg-label-secondary rounded"
                        ><i class="icon-base ti tabler-alert-octagon icon-26px text-heading"></i
                            ></span>
                      </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Order List Table -->
<div class="card">
    <div class="card-datatable table-responsive">
        <table class="datatables-order table border-top">
            <thead>
            <tr>
                <th></th>
                <th></th>
                <th>order</th>
                <th>purchase date</th>
                <th>payment</th>
                <th>ship date</th>
                <th>delivery date</th>
                <th>status</th>
                <th>customers</th>
                <th>actions</th>
            </tr>
            </thead>
        </table>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="imageModal" tabindex="-1" aria-labelledby="imageModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-body text-center">
                <img id="modalImage" src="" class="img-fluid rounded" alt="Preview">
            </div>
        </div>
    </div>
</div>