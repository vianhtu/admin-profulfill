<?php
if (!checkRoles('view', 'categories')) {
    return;
}
// Category dùng chung toàn hệ thống nên không còn bộ lọc theo team
?>
<?php if (!is_admin()): ?>
    <div class="alert alert-info d-flex align-items-center mb-6" role="alert">
        <i class="icon-base ti tabler-info-circle icon-22px me-3"></i>
        <span>Categories are shared by every team. You can add one that is missing and change the ones you added yourself; only an admin can change a category someone else added, or delete one.</span>
    </div>
<?php endif; ?>

<!-- Category List Table -->
<div class="card">
    <div class="card-datatable">
        <table class="datatables-categories table">
            <thead class="border-top">
            <tr>
                <th></th>
                <th></th>
                <th>category</th>
                <th>products</th>
                <th>ai prompt</th>
                <th>actions</th>
            </tr>
            </thead>
        </table>
    </div>
</div>
