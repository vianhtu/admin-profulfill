/**
 * App eCommerce Category Add / Edit
 */
'use strict';

function init() {
    const fv = formValidate();
    saveCategory(fv);
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
