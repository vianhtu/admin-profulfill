/**
 * Edit Permission Modal JS
 */

'use strict';

// Edit permission form validation
document.addEventListener('DOMContentLoaded', function (e) {
    // edit.
    $(document).on('click', '.datatables-permissions .edit', function () {
        const roleId = $(this).data('id');
        let form = $('#addPermissionModal');
        // Đổi tiêu đề và nút submit sang chế độ Edit
        form.data('type', 'edit');
        form.find('h3').text('Edit Role');
        form.find('button[type="submit"]').text('Edit Role');
        $.ajax({
            url: '../../ajax.php?action=get-roles-permissions',
            type: 'POST',
            dataType: 'json',
            data: { id: roleId },
            success: function (res) {
                if (res.status === 'success') {

                    // Gán dữ liệu vào form
                    form.find('#modalPermissionName').val(res.role_name);

                    // Bỏ check tất cả trước
                    form.find('input[type="checkbox"]').prop('checked', false);

                    const perms = res.permissions;

                    // perms dạng: { l1: { role: 1, role2: 0, ... }, ... }
                    for (let l1 in perms) {
                        for (let role in perms[l1]) {
                            if (perms[l1][role] == 1) {
                                form.find(`input[name="permissions[${l1}][${role}]"]`)
                                    .prop('checked', true);
                            }
                        }
                    }

                    // Lưu role_id
                    if (!form.find('input[name="role_id"]').length) {
                        form.find('form').append('<input type="hidden" name="role_id">');
                    }
                    form.find('input[name="role_id"]').val(roleId);

                } else {
                    showAlert('alertPermissionModal', res.message, 'danger');
                }
                // Mở modal sau khi load xong
                form.modal('show');
            },
            error: function () {
                showAlert('alertPermissionModal', 'Không thể kết nối đến máy chủ', 'danger');
                form.modal('show');
            }
        });
    });
});
