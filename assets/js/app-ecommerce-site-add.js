/**
 * App eCommerce Site Add / Edit
 */
'use strict';

function init() {
    const fv = formValidate();
    repeaterOptions();
    initLogoDropzone();
    initSlugAutoFill();
    saveSite(fv);
}

// Logo: dropzone hiển thị luôn ảnh (previewTemplate + displayExistingFile),
// cùng kiểu với dropzone ở form Store
function initLogoDropzone() {
    const el = document.querySelector('#dropzone-logo');
    if (!el || typeof Dropzone === 'undefined') {
        return;
    }

    const previewTemplate = `<div class="dz-preview dz-file-preview">
    <div class="dz-details">
      <div class="dz-thumbnail">
        <img data-dz-thumbnail>
        <span class="dz-nopreview">No preview</span>
        <div class="dz-success-mark"></div>
        <div class="dz-error-mark"></div>
        <div class="dz-error-message"><span data-dz-errormessage></span></div>
        <div class="progress">
          <div class="progress-bar progress-bar-primary" role="progressbar" aria-valuemin="0" aria-valuemax="100" data-dz-uploadprogress></div>
        </div>
      </div>
      <div class="dz-filename" data-dz-name></div>
      <div class="dz-size" data-dz-size></div>
    </div>
    </div>`;

    // QUAN TRỌNG: bản Dropzone của Vuexy ghi đè uploadFiles() bằng animation tiến trình
    // GIẢ (không POST lên server, tự emit success với res = chuỗi 'success'). Nếu để
    // Dropzone tự upload thì logo không bao giờ lưu được. Vì vậy tắt autoProcessQueue và
    // TỰ upload bằng fetch (đúng cách đã kiểm chứng chạy được).
    new Dropzone(el, {
        url: '../../ajax.php?action=upload-site-logo',
        previewTemplate: previewTemplate,
        paramName: 'file',
        maxFilesize: 2,
        acceptedFiles: '.png, .jpg, .jpeg',
        addRemoveLinks: true,
        autoProcessQueue: false, // không dùng bộ upload (giả) của Dropzone
        init: function () {
            const dz = this;

            // Chỉ giữ 1 logo + tự upload file mới bằng fetch
            this.on('addedfile', function (file) {
                dz.files.filter(f => f !== file).forEach(f => dz.removeFile(f));
                if (!file._mock) {
                    uploadSiteLogo(dz, file);
                }
            });
            // Lỗi phía client (sai định dạng / quá lớn) — Dropzone tự bắt trước khi upload
            this.on('error', function (file, msg) {
                if (!file._mock) {
                    alert(typeof msg === 'string' ? msg : (msg?.message || 'Invalid file'));
                }
                dz.removeFile(file);
            });
            // Gỡ ảnh khỏi khung = bỏ logo của site
            this.on('removedfile', function () {
                if (dz.files.length === 0) {
                    $('#site_logo').val('');
                }
            });

            // Đang sửa và đã có logo: hiện sẵn ảnh hiện tại (đánh dấu _mock để không upload lại)
            const current = $('#site_logo').val();
            if (current) {
                const mockFile = { name: current.split('/').pop(), size: 0, accepted: true, _mock: true };
                dz.displayExistingFile(mockFile, siteLogoUrl(current));
                dz.files.push(mockFile);
            }
        }
    });
}

// Tự POST logo lên server bằng fetch, rồi ghi đường dẫn vào hidden #site_logo.
function uploadSiteLogo(dz, file) {
    const fd = new FormData();
    fd.append('file', file);
    fd.append('csrf_token', window.csrfToken);
    fetch('../../ajax.php?action=upload-site-logo', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res && res.status === 'success') {
                $('#site_logo').val(res.logo);
                // Đánh dấu thành công trên khung ảnh (dấu tích xanh)
                if (file.previewElement) {
                    file.previewElement.classList.add('dz-success', 'dz-complete');
                }
            } else {
                alert(res && res.message ? res.message : 'Upload failed');
                dz.removeFile(file);
            }
        })
        .catch(() => {
            alert('Server connection error');
            dz.removeFile(file);
        });
}

// logo lưu 2 dạng: 'uploads/...' (user tải lên) hoặc tên file trong assets/img/icons/brands/
function siteLogoUrl(logo) {
    return logo.includes('/') ? '../../' + logo : '../../assets/img/icons/brands/' + logo;
}

// Slug tự sinh theo Name, dừng tự sinh khi người dùng tự sửa slug
function initSlugAutoFill() {
    const $name = $('#site_name');
    const $slug = $('#site_slug');
    if (!$name.length || !$slug.length) {
        return;
    }
    // Form Edit đã có slug => coi như user đã đặt, không ghi đè
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

// Cùng quy tắc với make_slug() phía server
function slugify(text) {
    return (text || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
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
