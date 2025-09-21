/**
 * Add Permission Modal JS
 */

'use strict';

document.addEventListener('DOMContentLoaded', function () {
    // Add permission form validation
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

    // check all checkbox.
    document.querySelectorAll('#addPermissionModal thead th').forEach(function (th, index) {
        // Bỏ qua cột đầu tiên (Module)
        if (index === 0) return;

        th.style.cursor = 'pointer'; // để người dùng biết có thể click

        th.addEventListener('click', function () {
            // Lấy tất cả checkbox trong cột này
            const checkboxes = document.querySelectorAll(
                'tbody tr td:nth-child(' + (index + 1) + ') input[type="checkbox"]:not(:disabled)'
            );

            if (checkboxes.length === 0) return;

            // Kiểm tra xem có bao nhiêu checkbox đang được check
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);

            // Nếu tất cả đã check thì bỏ check, ngược lại thì check hết
            checkboxes.forEach(cb => cb.checked = !allChecked);
        });
    });

    // hide modal reset all form.
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
        $('#addPermissionForm input[type="checkbox"][name^="permissions["]:checked')
        .each(function () {
            const name = $(this).attr('name');
            if (!name) return;

            // Trường hợp chỉ có l1 + role
            let match2 = name.match(/^permissions\[([^\]]+)\]\[([^\]]+)\]$/);
            if (match2) {
                const l1 = match2[1];
                const role = match2[2];

                if (!permissions[l1]) {
                    permissions[l1] = {};
                }
                permissions[l1][role] = 1; // luôn là 1 vì đã lọc :checked
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
                    if ($('#addPermissionModal').data('type') !== 'edit') {
                        $('#addPermissionModal').modal('hide');
                        formEl.reset();
                        fv.resetForm(true);
                        // Reload DataTable
                        $('.datatables-permissions').DataTable().ajax.reload(null, false);
                    } else {
                        showAlert('alertPermissionModal', result.message, 'success');
                    }
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
