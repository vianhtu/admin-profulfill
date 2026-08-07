<?php
if(!checkRoles('view', 'phones_numbers')){
    return;
}
?>
<!-- Phone Numbers Table.
     Bỏ card "Filters" cũ: nó chỉ có 4 ô rỗng chép từ trang xlsx (xlsx_types/sites/authors/
     accounts) mà không JS nào render vào, nên hiện ra một khối trống không bấm được gì. -->
<div class="card">
    <div class="card-datatable">
        <table class="datatables-phones table">
            <thead class="border-top">
            <tr>
                <th></th>
                <th></th>
                <th>Numbers</th>
                <th>Status</th>
                <th>Carriers</th>
                <th>Notices</th>
                <th>Accounts</th>
                <th>Actions</th>
            </tr>
            </thead>
        </table>
    </div>
</div>