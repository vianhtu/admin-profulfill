/**
 * App eCommerce Category Add / Edit
 */
'use strict';

function init() {
    const fv = formValidate();
    initSelects();
    saveCategory(fv);
}

function initSelects() {
    // Team: select2 đơn (chỉ admin mới có ô này — category không dùng chung giữa các team)
    const $team = $('#category_team');
    if ($team.length) {
        $team.wrap('<div class="position-relative"></div>').select2({
            dropdownParent: $team.parent()
        });
    }
}

function getTeamId() {
    return $('#category_team').length ? ($('#category_team').val() || '') : '';
}

function formValidate() {
    const formEl = document.getElementById('categoryForm');
    if (!formEl) return null;

    return FormValidation.formValidation(formEl, {
        fields: {
            category_name: {
                validators: { notEmpty: { message: 'Please enter the category name.' } }
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
}

function saveCategory(fv) {
    if (!fv) return;

    fv.off('core.form.valid');
    fv.on('core.form.valid', function () {
        const $btn = $('#form_submit');
        const $spinner = $('#loading_spinner');
        const teamId = getTeamId();

        // Admin có ô Team thì bắt buộc chọn; user thường server tự gán team của họ
        if ($('#category_team').length && !teamId) {
            alert('Please select a team.');
            return;
        }

        $spinner.removeClass('d-none');
        $btn.prop('disabled', true);

        $.ajax({
            url: '../../ajax.php?action=save-category',
            method: 'POST',
            dataType: 'json',
            data: {
                id: $('#category_id').val() || 0,
                name: $('#category_name').val(),
                user_prompt: $('#category_prompt').val(),
                team_id: teamId,
                csrf_token: window.csrfToken
            }
        }).done(function (res) {
            if (res.status === 'inserted' || res.status === 'updated') {
                const url = new URL(window.location.href);
                url.searchParams.set('form', 'edit');
                url.searchParams.set('id', res.id);
                window.location.href = url.toString();
            } else {
                alert(res.message || 'Save failed');
            }
        }).fail(function () {
            alert('Server connection error');
        }).always(function () {
            $spinner.addClass('d-none');
            $btn.prop('disabled', false);
        });
    });
}

document.addEventListener('DOMContentLoaded', function () {
    init();
});
