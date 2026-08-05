/**
 * App eCommerce Store Add / Edit
 */
'use strict';

function init() {
    const fv = formValidate();
    initSelects();
    initSlugAutoFill();
    saveStore(fv);
}

function initSelects() {
    // #store_team chỉ tồn tại với admin — role khác store luôn thuộc team của họ
    $('#store_site, #store_status, #store_team').each(function () {
        const $this = $(this);
        $this.wrap('<div class="position-relative"></div>').select2({ dropdownParent: $this.parent() });
    });
}

// Slug tự sinh theo Name, dừng khi người dùng tự sửa slug
function initSlugAutoFill() {
    const $name = $('#store_name');
    const $slug = $('#store_slug');
    if (!$name.length || !$slug.length) {
        return;
    }
    let auto = $slug.val().trim() === '';

    $slug.on('input', function () {
        auto = $(this).val().trim() === '';
    });
    $name.on('input', function () {
        if (auto) {
            $slug.val(slugify($(this).val()));
        }
    });
}

// Khớp make_slug() phía server: slug shop = tên viết thường (giống cách extension sinh)
function slugify(text) {
    return (text || '').toLowerCase().trim();
}

function formValidate() {
    const formEl = document.getElementById('storeForm');
    if (!formEl) return null;

    return FormValidation.formValidation(formEl, {
        fields: {
            store_name: { validators: { notEmpty: { message: 'Please enter the store name.' } } },
            store_site: { validators: { notEmpty: { message: 'Please select a site.' } } }
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

function saveStore(fv) {
    if (!fv) return;

    // Select2 không tự bắn event cho FormValidation
    $('#store_site, #store_status').on('change', function () {
        fv.revalidateField($(this).attr('id'));
    });

    fv.off('core.form.valid');
    fv.on('core.form.valid', function () {
        const $btn = $('#form_submit');
        const $spinner = $('#loading_spinner');

        $spinner.removeClass('d-none');
        $btn.prop('disabled', true);

        $.ajax({
            url: '../../ajax.php?action=save-store',
            method: 'POST',
            dataType: 'json',
            data: {
                id: $('#store_id').val() || 0,
                name: $('#store_name').val(),
                slug: $('#store_slug').val(),
                site_id: $('#store_site').val(),
                status: $('#store_status').val(),
                team_id: $('#store_team').val() || 0,
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
