<?php
if (!checkRoles('view', 'sites')) {
    return;
}
// Site là dữ liệu dùng chung toàn hệ thống nên không có bộ lọc theo team
?>
<?php if (!is_admin()): ?>
    <div class="alert alert-info d-flex align-items-center mb-6" role="alert">
        <i class="icon-base ti tabler-info-circle icon-22px me-3"></i>
        <span>Sites are shared by the whole system. You can add a marketplace that is missing and change the ones you added yourself; only an admin can change a site someone else added, or delete one.</span>
    </div>
<?php endif; ?>

<!-- Site List Table -->
<div class="card">
    <div class="card-datatable">
        <table class="datatables-sites table">
            <thead class="border-top">
            <tr>
                <th></th>
                <th></th>
                <th>site</th>
                <th>slug</th>
                <th>products</th>
                <th>accounts</th>
                <th>stores</th>
                <th>ai prompt</th>
                <th>custom fields</th>
                <th>actions</th>
            </tr>
            </thead>
        </table>
    </div>
</div>
