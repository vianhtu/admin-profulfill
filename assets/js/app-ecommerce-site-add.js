/**
 * App eCommerce Site Add / Edit
 */
'use strict';

function init() {
    const fv = formValidate();
    repeaterOptions();
    saveSite(fv);
}

function repeaterOptions() {
    const $repeater = $('#fieldRepeater');
    if ($repeater.length) {
        $repeater.repeater({
            show: function () {
                $(this).find('input').val('');
                $(this).slideDown();
            },
            hide: function (deleteElement) {
                if (confirm('Are you sure you want to delete this field?')) {
                    $(this).slideUp(deleteElement);
                }
            },
            isFirstItemUndeletable: false
        });
    }
}

// Gom custom fields: [{text, value}] — bỏ dòng chưa đặt tên
function getFields() {
    const fields = [];
    $('#fieldRepeater').find('[data-repeater-item]').each(function () {
        const text = ($(this).find('.field_text').val() || '').trim();
        const value = ($(this).find('.field_value').val() || '').trim();
        if (text) {
            fields.push({ text: text, value: value });
        }
    });
    return fields;
}

function formValidate() {
    const formEl = document.getElementById('siteForm');
    if (!formEl) return null;

    return FormValidation.formValidation(formEl, {
        fields: {
            site_name: {
                validators: { notEmpty: { message: 'Please enter the site name.' } }
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

function saveSite(fv) {
    if (!fv) return;

    fv.off('core.form.valid');
    fv.on('core.form.valid', function () {
        const $btn = $('#form_submit');
        const $spinner = $('#loading_spinner');

        $spinner.removeClass('d-none');
        $btn.prop('disabled', true);

        $.ajax({
            url: '../../ajax.php?action=save-site',
            method: 'POST',
            dataType: 'json',
            data: {
                id: $('#site_id').val() || 0,
                name: $('#site_name').val(),
                slug: $('#site_slug').val(),
                logo: $('#site_logo').val(),
                system_prompt: $('#site_system_prompt').val(),
                developer_prompt: $('#site_developer_prompt').val(),
                fields: JSON.stringify(getFields()),
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
