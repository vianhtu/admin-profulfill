<?php
/**
 * Trang Dashboards — trang đáp mặc định sau khi đăng nhập (`?menu=` rỗng).
 *
 * Trang mở cho MỌI người đã đăng nhập, giống trang Account: nó chỉ hiển thị
 * đúng phần việc của chính người đang xem. Nhưng TỪNG KHỐI vẫn phải qua
 * `checkRoles()` / kiểm cấp — thiếu quyền thì KHÔNG render ra HTML, chứ không
 * phải render rồi để JS giấu đi.
 *
 * Ô số để trống, `app-dashboard.js` gọi `dashboard-stats` rồi điền — với bảng
 * `posts` hơn một triệu dòng thì không được chặn lần vẽ đầu tiên.
 */

$dash_can_products = checkRoles('view', 'products');
$dash_can_orders   = checkRoles('view', 'orders');
$dash_is_admin     = is_admin();
$dash_can_members  = $dash_is_admin || is_manager();
?>

<div class="row g-4 mb-4" id="dashboard-page">

    <div class="col-12">
        <h4 class="mb-1">Xin chào, <span id="dash-username"><?= htmlspecialchars((string) ($_SESSION['auth']['user'] ?? ''), ENT_QUOTES) ?></span></h4>
        <p class="text-muted mb-0" id="dash-scope-note">Đang tải số liệu…</p>
    </div>

    <?php if ($dash_can_products): ?>
        <!-- ---------- KPI sản phẩm ---------- -->
        <div class="col-sm-6 col-xl-3">
            <div class="card h-100"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted d-block mb-1">Hôm nay</span>
                        <h4 class="mb-1 dash-num" id="kpi-today">–</h4>
                        <small id="kpi-today-delta" class="text-muted">so với hôm qua</small>
                    </div>
                    <div class="avatar"><span class="avatar-initial rounded bg-label-primary"><i class="icon-base ti tabler-package"></i></span></div>
                </div>
            </div></div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card h-100"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted d-block mb-1">7 ngày</span>
                        <h4 class="mb-1 dash-num" id="kpi-d7">–</h4>
                        <small id="kpi-d7-delta" class="text-muted">so với 7 ngày trước</small>
                    </div>
                    <div class="avatar"><span class="avatar-initial rounded bg-label-info"><i class="icon-base ti tabler-calendar-week"></i></span></div>
                </div>
            </div></div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card h-100"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted d-block mb-1">30 ngày</span>
                        <h4 class="mb-1 dash-num" id="kpi-d30">–</h4>
                        <small class="text-muted">sản phẩm thêm mới</small>
                    </div>
                    <div class="avatar"><span class="avatar-initial rounded bg-label-secondary"><i class="icon-base ti tabler-chart-bar"></i></span></div>
                </div>
            </div></div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card h-100"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="text-muted d-block mb-1"><?= $dash_is_admin ? 'Toàn hệ thống' : 'Tổng cộng' ?></span>
                        <h4 class="mb-1 dash-num" id="kpi-total">–</h4>
                        <small class="text-muted">sản phẩm trong phạm vi của bạn</small>
                    </div>
                    <div class="avatar"><span class="avatar-initial rounded bg-label-success"><i class="icon-base ti tabler-database"></i></span></div>
                </div>
            </div></div>
        </div>

        <!-- ---------- biểu đồ 14 ngày ---------- -->
        <div class="col-12 col-xl-8">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title mb-0">Nhịp nhập 14 ngày</h5>
                        <small class="text-muted">bấm vào cột để mở Products đã lọc sẵn ngày đó</small>
                    </div>
                </div>
                <div class="card-body"><div id="chart-daily" style="min-height:260px"></div></div>
            </div>
        </div>

        <!-- ---------- cảnh báo ---------- -->
        <div class="col-12 col-xl-4">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-title mb-0">Cần chú ý</h5></div>
                <div class="card-body"><ul class="list-unstyled mb-0" id="dash-alerts">
                    <li class="text-muted">Đang tải…</li>
                </ul></div>
            </div>
        </div>

        <!-- ---------- pipeline ---------- -->
        <div class="col-12 col-xl-<?= $dash_can_orders ? '7' : '12' ?>">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0">Pipeline sản phẩm</h5>
                    <small class="text-muted">gom không phân biệt hoa thường</small>
                </div>
                <div class="card-body"><div id="chart-pipeline" style="min-height:260px"></div></div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($dash_can_orders): ?>
        <div class="col-12 col-xl-<?= $dash_can_products ? '5' : '12' ?>">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-title mb-0">Đơn hàng</h5></div>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Trạng thái</th><th class="text-end">Đơn</th><th class="text-end">Giá trị</th></tr></thead>
                        <tbody id="dash-orders"><tr><td colspan="3" class="text-muted">Đang tải…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($dash_can_members): ?>
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">Thành viên<?= $dash_is_admin ? '' : ' trong team' ?></h5>
                    <small class="text-muted">cột “lần cuối” cho thấy ai đã ngừng làm</small>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr>
                            <th>Thành viên</th><th>Vai</th>
                            <th class="text-end">Hôm nay</th><th class="text-end">7 ngày</th>
                            <th class="text-end">30 ngày</th><th class="text-end">Tổng</th>
                            <th>Lần cuối</th><th>Trạng thái</th>
                        </tr></thead>
                        <tbody id="dash-members"><tr><td colspan="8" class="text-muted">Đang tải…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($dash_is_admin): ?>
        <div class="col-12 col-xl-6">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-title mb-0">So sánh giữa các team</h5></div>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Team</th><th class="text-end">Người</th><th class="text-end">Sản phẩm</th><th>Tình trạng</th></tr></thead>
                        <tbody id="dash-teams"><tr><td colspan="4" class="text-muted">Đang tải…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-6">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0">An ninh &amp; truy cập API</h5>
                    <small class="text-muted">mọi chỉ số dưới đây phải bằng 0</small>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Chỉ số</th><th class="text-end">Giá trị</th></tr></thead>
                        <tbody id="dash-security"><tr><td colspan="2" class="text-muted">Đang tải…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>
