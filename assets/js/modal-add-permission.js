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

    function showAlert(message, type = 'danger') {
        const alertBox = document.querySelector('#addPermissionForm .alert');
        if (!alertBox) return;

        alertBox.classList.remove('d-none', 'alert-success', 'alert-danger', 'alert-warning');
        alertBox.classList.add('alert-' + type);
        alertBox.innerText = message;
    }

    // Khi form hợp lệ và được submit
    formEl.addEventListener('submit', function (e) {
        e.preventDefault();
        console.log(fv.validate());
        fv.validate().then(function (status) {
            if (status === 'Valid') {
                // Lấy Role Name
                let roleName = $('#modalPermissionName').val().trim();

                // Lấy toàn bộ checkbox permission
                let permissions = {};
                $('input[type="checkbox"]').each(function () {
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

                // Gửi AJAX
                $.ajax({
                    url: '../../ajax.php?action=get-roles-permissions-table',
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
            }
        });
    });
});
