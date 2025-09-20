/**
 * Add Permission Modal JS
 */

'use strict';

function showAlert(message, type = 'danger') {
    const alertBox = document.querySelector('#addPermissionForm .alert');
    if (!alertBox) return;

    alertBox.classList.remove('d-none', 'alert-success', 'alert-danger', 'alert-warning');
    alertBox.classList.add('alert-' + type);
    alertBox.innerText = message;
}

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

    // Sự kiện khi form hợp lệ
    fv.on('core.form.valid', function () {
        // Lấy Role Name
        let from = $('#modalPermissionName');
        let roleName = from.val().trim();

        // Lấy toàn bộ checkbox permission
        let permissions = {};
        from.find('input[type="checkbox"]').each(function () {
            let name = $(this).attr('name');
            let checked = $(this).is(':checked') ? 1 : 0;

            let match = name.match(/permissions\[(.+?)\]\[(.+?)\]/);
            if (match) {
                let module = match[1];
                let role = match[2];

                if (!permissions[module]) {
                    permissions[module] = {};
                }
                permissions[module][role] = checked;
            }
        });

        console.log(permissions);

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
                    showAlert('Lỗi không xác định từ server', 'danger');
                    return;
                }

                if (result.status === 'success') {
                    showAlert(result.message, 'success');
                    $('#addPermissionModal').modal('hide');
                    formEl.reset();
                    fv.resetForm(true);
                } else {
                    showAlert(result.message, 'danger');
                }
            },
            error: function () {
                showAlert('Không thể kết nối đến máy chủ', 'danger');
            }
        });
    });
});
