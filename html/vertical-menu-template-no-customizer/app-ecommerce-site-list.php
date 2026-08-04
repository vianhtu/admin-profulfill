<?php
if (!checkRoles('view', 'sites')) {
    return;
}
// Site là dữ liệu dùng chung toàn hệ thống nên không có bộ lọc theo team
?>
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
