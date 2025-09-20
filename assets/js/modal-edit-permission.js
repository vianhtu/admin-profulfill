/**
 * Edit Permission Modal JS
 */

'use strict';

// Edit permission form validation
document.addEventListener('DOMContentLoaded', function (e) {
    // edit.
    $(document).on('click', '.datatables-permissions .edit', function () {
        const roleId = $(this).data('id');
        $.ajax({
            url: '../../ajax.php?action=get-roles-permissions',
            type: 'POST',
            dataType: 'json',
            data: { id: roleId },
            success: function (res) {
                if (res.status === 'success') {
                    let form = $('#addPermissionModal');

                    // Gán dữ liệu vào form
                    form.find('#modalPermissionName').val(res.role_name);
                    form.find('input[type="checkbox"]').prop('checked', false);

                    const perms = res.permissions;
                    for (let cha in perms) {
                        for (let key in perms[cha]) {
                            if (typeof perms[cha][key] === 'object') {
                                for (let role in perms[cha][key]) {
                                    if (perms[cha][key][role] == 1) {
                                        form.find(`input[name="permissions[${cha}][${key}][${role}]"]`)
                                            .prop('checked', true);
                                    }
                                }
                            } else {
                                if (perms[cha][key] == 1) {
                                    form.find(`input[name="permissions[${cha}][${key}]"]`)
                                        .prop('checked', true);
                                }
                            }
                        }
                    }

                    // Lưu role_id
                    if (!form.find('input[name="role_id"]').length) {
                        form.find('form').append('<input type="hidden" name="role_id">');
                    }
                    form.find('input[name="role_id"]').val(roleId);

                    // Mở modal sau khi load xong
                    form.modal('show');
                } else {
                    alert(res.message);
                }
            },
            error: function () {
                alert('Không thể kết nối đến máy chủ');
            }
        });
    });
});
