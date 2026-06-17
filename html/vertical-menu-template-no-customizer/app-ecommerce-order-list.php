<?php
if(!checkRoles('view', 'orders')){
    return;
}
?>
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
                <th>ship date</th>
                <th>delivery date</th>
                <th>payment/profit</th>
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
                <div class="row align-items-center">
                    <div class="col-md-6 text-center mb-3 mb-md-0">
                        <img id="modalImage" src="" class="img-fluid rounded" alt="Preview">
                    </div>
                    <div class="col-md-6">
                        <form id="item-modal-form">
                            <input type="hidden" name="item_id" id="item_id" value="">
                            <input type="hidden" name="item_name" id="order_id" value="">
                            <input type="hidden" name="order_price" id="order_price" value="">
                            <div class="mb-3">
                                <label for="item-base-cost" class="form-label font-weight-bold">Base Cost $</label>
                                <input type="number" step="0.01" class="form-control" id="item-base-cost" placeholder="9.99">
                            </div>
                            <div class="mb-3">
                                <label for="item-note" class="form-label">Note</label>
                                <textarea class="form-control" id="item-note" rows="3"></textarea>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteConfirmModalLabel">Xác nhận xóa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Bạn có chắc chắn muốn xóa bản ghi này không? Hành động này không thể hoàn tác.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                <button type="button" class="btn btn-danger" id="btn-confirm-delete">Đồng ý xóa</button>
            </div>
        </div>
    </div>
</div>