/**
 * App eCommerce Add Product Script
 */
'use strict';
//Jquery to handle the e-commerce product add page
function init(){
    const fv = formValidate();
    const dz = dropzoneFileUpload();
    repeaterOptions();
    addAccount(fv, dz);

    // Select2
    var select2 = $('#account_team,#account_site,#accounts_status');
    if (select2.length) {
        select2.each(function () {
            var $this = $(this);
            $this.wrap('<div class="position-relative"></div>').select2({
                dropdownParent: $this.parent(),
            });
        });
    }

    ajaxSelectV2('account_author', 'authors', false);
    ajaxSelectV2('link_accounts', 'accounts', true);
}

function repeaterOptions() {
    var $formRepeater = $('.form-repeater');

    if ($formRepeater.length) {
        $formRepeater.repeater({
            show: function () {
                // Reset các input về trống khi add row mới
                $(this).find('input').val('');

                // Hiệu ứng hiển thị
                $(this).slideDown();
            },
            hide: function (deleteElement) {
                if (confirm('Are you sure you want to delete this element?')) {
                    $(this).slideUp(deleteElement);
                }
            },
            isFirstItemUndeletable: false // Cho phép xóa cả hàng đầu tiên nếu cần
        });
    }
}

function formValidate(){
    // form validation
    const formEl = document.getElementById('addXlsxFile');
    let valid_fields = {
        owner_name: {
            validators: {
                notEmpty: {
                    message: 'Please enter owner name.'
                }
            }
        },
        account_email: {
            validators: {
                notEmpty: {
                    message: 'Please enter account email.'
                }
            }
        },
        accounts_status: {
            validators: {
                notEmpty: {
                    message: 'Please select account status.'
                }
            }
        },
        account_site: {
            validators: {
                notEmpty: {
                    message: 'Please select account site.'
                }
            }
        }
    };
    const fv = FormValidation.formValidation(formEl, {
        fields: valid_fields,
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
    return fv;
}

function dropzoneFileUpload(){
    // previewTemplate: Updated Dropzone default previewTemplate
    // ! Don't change it unless you really know what you are doing
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

    // Basic Dropzone
    let myDropzone;
    const dropzoneBasic = document.querySelector('#dropzone-basic');
    if (dropzoneBasic) {
        myDropzone = new Dropzone(dropzoneBasic, {
            url: '/upload',
            previewTemplate: previewTemplate,
            parallelUploads: 5,
            maxFilesize: 5,
            acceptedFiles: '.xlsx, .png, .pdf, .jpg, .jpeg, .txt',
            addRemoveLinks: true,
            uploadMultiple: true,
            maxFiles: 10
        });
    }
    return myDropzone;
}

function getRepeaterData() {
    var data = [];
    // Mỗi row trong repeater có data-repeater-item
    $('.form-repeater').find('[data-repeater-item]').each(function () {
        var selectVal = $(this).find('.form-select').val();
        var inputVal  = $(this).find('input[type="text"]').val();
        var selectedText = $(this).find('.form-select option:selected').text();
        if (selectVal !== null && selectVal !== '' && selectVal !== undefined) {
            data.push({
                location: selectVal,
                text :selectedText,
                value: inputVal
            });
        }
    });

    return data;
}

function addAccount(fv, dz) {
    // Khi FormValidation validate thành công
    fv.on('core.form.valid', function() {
        const $btn = $('#form_submit');
        const $spinner = $('#loading_spinner');

        // Hiển thị spinner và disable nút
        $spinner.removeClass('d-none');
        $btn.prop('disabled', true);

        let id = $('#export_id').val();
        const formData = new FormData();
        formData.append('name', $('#owner_name').val());
        formData.append('address', $('#owner_address').val());
        formData.append('dob', $('#owner_dob').val());
        formData.append('ssn', $('#owner_ssn').val());
        formData.append('phone', $('#owner_phone').val());

        formData.append('email', $('#account_email').val());
        formData.append('sku', $('#account_sku').val());
        formData.append('id', $('#account_id').val());
        formData.append('password', $('#account_password').val());
        formData.append('2fa', $('#account_2fa').val());

        formData.append('file', dz.files[0]);

        formData.append('options', JSON.stringify(getRepeaterData()));

        formData.append('status', $('#accounts_status').val());
        formData.append('site', $('#account_site').val());
        formData.append('team', $('#account_team').val());
        formData.append('author', $('#account_author').val());

        formData.append('accounts', $('#link_accounts').val());

        formData.append('_id', id);

        formData.append('note', $('#account_note').val());

        formData.append('csrf_token', window.csrfToken);

        console.log(formData); return;

        $.ajax({
            url: '../../ajax.php?action=add-xlsx',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function (response) {
                if (response.status === 'inserted' || response.status === 'updated') {
                    const newId = response.id;

                    // Lấy URL hiện tại và thêm id
                    const url = new URL(window.location.href);
                    url.searchParams.set('id', newId);

                    // Reload lại với URL mới
                    window.location.href = url.toString();
                } else {
                    alert('Upload thất bại: ' + response.message);
                }
            },
            error: function (xhr) {
                console.error('Lỗi:', xhr.responseText);
                alert('Upload thất bại!');
            },
            complete: function () {
                // Ẩn spinner và bật lại nút
                $spinner.addClass('d-none');
                $btn.prop('disabled', false);
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', function (e) {
    init();
});
