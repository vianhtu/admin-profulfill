/**
 * App eCommerce Product Add / Edit
 */
'use strict';

let descEditor = null;

function init() {
    const fv = formValidate();
    repeaterOptions();
    initSelects();
    initTags();
    initDescriptionEditor();
    bindImagePreview(fv);
    saveProduct(fv);
}

// Quill Snow theme cho Description (toolbar #snow-toolbar trong fragment)
function initDescriptionEditor() {
    const el = document.getElementById('snow-editor');
    if (el && typeof Quill !== 'undefined') {
        descEditor = new Quill(el, {
            bounds: '#snow-editor',
            modules: { toolbar: '#snow-toolbar' },
            theme: 'snow'
        });
    }
}

function getDescription() {
    if (!descEditor) {
        return '';
    }
    // Trống thì lưu chuỗi rỗng thay vì '<p><br></p>' của Quill
    return descEditor.getText().trim() === '' ? '' : descEditor.root.innerHTML;
}

function initSelects() {
    // Select tĩnh: dùng select2 cho đồng bộ UI template
    $('#product_status,#product_type,#product_site').each(function () {
        const $this = $(this);
        $this.wrap('<div class="position-relative"></div>').select2({ dropdownParent: $this.parent() });
    });

    // Store: select2 ajax (danh sách lớn, đã lọc theo team ở server)
    ajaxSelect2('product_store', 'filter-stores', false, null, 0);
    // Manager: chỉ có với admin/manager, danh sách đã scope theo quyền ở server
    if (document.getElementById('product_author')) {
        ajaxSelect2('product_author', 'filter-authors', false, null, 0);
    }
}

function initTags() {
    const el = document.getElementById('product_tags');
    if (el && typeof Tagify !== 'undefined') {
        window.productTagify = new Tagify(el);
    }
}

// Hiện ảnh xem trước ngay khi dán URL + cập nhật trạng thái validate ảnh
function bindImagePreview(fv) {
    $(document).on('input change', '.image_url', function () {
        const $img = $(this).closest('[data-repeater-item]').find('.img-preview');
        const url = ($(this).val() || '').trim();
        if (url) {
            $img.attr('src', url).removeClass('d-none');
        } else {
            $img.addClass('d-none');
        }
        syncImagesState(fv);
    });
    // Thêm/xóa dòng ảnh cũng phải revalidate
    $(document).on('click', '[data-repeater-create], [data-repeater-delete]', function () {
        setTimeout(() => syncImagesState(fv), 300);
    });
}

function syncImagesState(fv) {
    $('#images_state').val(getImageUrls().length ? '1' : '');
    if (fv) {
        fv.revalidateField('images_state');
    }
}

function repeaterOptions() {
    // Repeater ảnh (phẳng)
    const $images = $('#imageRepeater');
    if ($images.length) {
        $images.repeater({
            show: function () {
                $(this).find('input').val('');
                $(this).find('.img-preview').addClass('d-none').attr('src', '');
                $(this).slideDown();
            },
            hide: function (deleteElement) {
                if (confirm('Are you sure you want to delete this image?')) {
                    $(this).slideUp(deleteElement);
                }
            },
            isFirstItemUndeletable: false
        });
    }

}

function getImageUrls() {
    const urls = [];
    $('#imageRepeater').find('[data-repeater-item]').each(function () {
        const v = ($(this).find('.image_url').val() || '').trim();
        if (v) {
            urls.push(v);
        }
    });
    return urls;
}

function getTags() {
    const el = document.getElementById('product_tags');
    if (!el) {
        return [];
    }
    // Tagify lưu JSON [{value:...}]; chưa init thì đọc chuỗi phân tách bằng dấu phẩy
    try {
        const parsed = JSON.parse(el.value);
        if (Array.isArray(parsed)) {
            return parsed.map(t => (typeof t === 'string' ? t : t.value)).filter(Boolean);
        }
    } catch (e) { /* không phải JSON */ }
    return (el.value || '').split(',').map(s => s.trim()).filter(Boolean);
}

function formValidate() {
    const formEl = document.getElementById('productForm');
    if (!formEl) return null;

    return FormValidation.formValidation(formEl, {
        fields: {
            product_title: {
                validators: { notEmpty: { message: 'Please enter the product title.' } }
            },
            product_sku: {
                validators: { notEmpty: { message: 'Please enter the SKU.' } }
            },
            product_type: {
                validators: { notEmpty: { message: 'Please select a category.' } }
            },
            product_site: {
                validators: { notEmpty: { message: 'Please select a site.' } }
            },
            product_store: {
                validators: { notEmpty: { message: 'Please select a store.' } }
            },
            product_status: {
                validators: { notEmpty: { message: 'Please select a status.' } }
            },
            images_state: {
                validators: { notEmpty: { message: 'Please add at least one image.' } }
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

function saveProduct(fv) {
    if (!fv) return;

    // Select2 không tự bắn event cho FormValidation
    $('#product_type,#product_site,#product_store,#product_status').on('change', function () {
        fv.revalidateField($(this).attr('id'));
    });

    fv.off('core.form.valid');
    fv.on('core.form.valid', function () {
        const $btn = $('#form_submit');
        const $spinner = $('#loading_spinner');
        const images = getImageUrls();

        $spinner.removeClass('d-none');
        $btn.prop('disabled', true);

        $.ajax({
            url: '../../ajax.php?action=save-product',
            method: 'POST',
            dataType: 'json',
            data: {
                id: $('#product_id').val() || 0,
                title: $('#product_title').val(),
                sku: $('#product_sku').val(),
                badge: $('#product_badge').val(),
                description: getDescription(),
                status: $('#product_status').val(),
                type_id: $('#product_type').val(),
                site_id: $('#product_site').val(),
                store_id: $('#product_store').val(),
                author_id: $('#product_author').val() || '',
                images: JSON.stringify(images),
                tags: JSON.stringify(getTags()),
                csrf_token: window.csrfToken
            }
        }).done(function (res) {
            if (res.status === 'inserted' || res.status === 'updated') {
                // Sau khi lưu chuyển sang chế độ Edit của chính sản phẩm đó
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
