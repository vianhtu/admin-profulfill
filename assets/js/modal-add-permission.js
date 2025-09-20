/**
 * Add Permission Modal JS
 */

'use strict';

// Add permission form validation
document.addEventListener('DOMContentLoaded', function () {
    const formEl = document.getElementById('addPermissionForm');
    const fv = FormValidation.formValidation(formEl, {
        fields: {
            modalPermissionName: {
                validators: {
                    notEmpty: {
                        message: 'Please enter permission name'
                    }
                }
            }
        },
        plugins: {
            trigger: new FormValidation.plugins.Trigger(),
            bootstrap5: new FormValidation.plugins.Bootstrap5({
                eleValidClass: '',
                rowSelector: '.form-control-validation'
            }),
            submitButton: new FormValidation.plugins.SubmitButton(),
            autoFocus: new FormValidation.plugins.AutoFocus()
        }
    });
    // reset.
    $('#addPermissionModal').on('hidden.bs.modal', function () {
        // Reset form HTML
        this.querySelector('form').reset();

        // Nếu dùng FormValidation plugin thì reset luôn trạng thái validate
        if (typeof fv !== 'undefined') {
            fv.resetForm(true);
        }

        // Bỏ tick tất cả checkbox (nếu cần)
        $(this).find('input[type="checkbox"]').prop('checked', false);

        // Xóa hidden role_id (nếu là form edit)
        $(this).find('input[name="role_id"]').remove();

        // Đổi tiêu đề và nút submit về mặc định
        $(this).find('h3').text('Add New Role');
        $(this).find('button[type="submit"]').text('Create Role');
    });

    // Sự kiện khi form hợp lệ
    fv.on('core.form.valid', function () {
        // Lấy Role Name
        let roleName = $('#modalPermissionName').val().trim();

        // Lấy toàn bộ checkbox permission
        let permissions = {};
        $('#addPermissionForm input[type="checkbox"][name^="permissions["]').each(function () {
            const name = $(this).attr('name');
            if (!name) return;

            const checked = $(this).is(':checked') ? 1 : 0;

            // Trường hợp chỉ có l1 + role
            let match2 = name.match(/^permissions\[([^\]]+)\]\[([^\]]+)\]$/);
            if (match2) {
                const l1 = match2[1];
                const role = match2[2];

                if (!permissions[l1]) {
                    permissions[l1] = {};
                }
                permissions[l1][role] = checked;
            }
        });

        // Gửi AJAX
        $.ajax({
            url: '../../ajax.php?action=add-roles-permissions',
            type: 'POST',
            data: {
                role_name: roleName,
                permissions: permissions
            },
            success: function (res) {
                // Nếu server trả về JSON
                let result;
                try {
                    result = typeof res === 'string' ? JSON.parse(res) : res;
                } catch (e) {
                    showAlert( 'alertPermissionModal','Lỗi không xác định từ server', 'danger');
                    return;
                }

                if (result.status === 'success') {
                    showAlert( 'alertPermissionModal', result.message, 'success');
                    $('#addPermissionModal').modal('hide');
                    formEl.reset();
                    fv.resetForm(true);
                    // Reload DataTable
                    $('.datatables-permissions').DataTable().ajax.reload(null, false);
                } else {
                    showAlert('alertPermissionModal', result.message, 'danger');
                }
            },
            error: function () {
                showAlert('alertPermissionModal','Không thể kết nối đến máy chủ', 'danger');
            }
        });
    });
});
