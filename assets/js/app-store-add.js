/**
 * App eCommerce Add Product Script
 */
'use strict';
//Jquery to handle the e-commerce product add page
function init(){
    const fv = formValidate();
    const dz = dropzoneFileUpload(fv);
    repeaterOptions();
    addXlsxFile(fv, dz);

    // Select2
    var select2 = $('#export_type,#export_site,#export_author,#export_sheet_name');
    if (select2.length) {
        select2.each(function () {
            var $this = $(this);
            $this.wrap('<div class="position-relative"></div>').select2({
                dropdownParent: $this.parent(),
            });
        });
    }

    // update form.
    if($('#export_id').val() !== '') {
        $('#export_file_header, #export_sheet_name').on('change', function () {
            $('#export_submit').trigger('click');
        });
    }

    ajaxSelect2('accountsExport', 'filter-accounts', false);
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
        export_file_header: {
            validators: {
                notEmpty: {
                    message: 'Please enter header row number.'
                }
            }
        },
        export_file_start: {
            validators: {
                notEmpty: {
                    message: 'Please enter start item row number.'
                }
            }
        },
        accountsExport: {
            validators: {
                notEmpty: {
                    message: 'Please select a export account.'
                }
            }
        },
        export_type: {
            validators: {
                notEmpty: {
                    message: 'Please select a export type.'
                }
            }
        },
        export_site: {
            validators: {
                notEmpty: {
                    message: 'Please select a site export.'
                }
            }
        },
        xlsxFilePresent: {
            validators: {
                notEmpty: {
                    message: 'Please select a file .xlxs'
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

function dropzoneFileUpload(fv){
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
            parallelUploads: 1,
            maxFilesize: 5,
            acceptedFiles: '.xlsx',
            addRemoveLinks: true,
            maxFiles: 1
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

function addXlsxFile(fv, dz) {
    // Khi FormValidation validate thành công
    fv.on('core.form.valid', function() {
        const $btn = $('#export_submit');
        const $spinner = $('#loading_spinner');

        // Hiển thị spinner và disable nút
        $spinner.removeClass('d-none');
        $btn.prop('disabled', true);

        let id = $('#export_id').val();
        const formData = new FormData();
        formData.append('author', $('#export_author').val());
        formData.append('site', $('#export_site').val());
        formData.append('type', $('#export_type').val());
        formData.append('account', $('#accountsExport').val());
        formData.append('id', id);
        formData.append('options', JSON.stringify(getRepeaterData()));
        formData.append('header', $('#export_file_header').val());
        formData.append('startRow', $('#export_file_start').val());
        formData.append('sheet_name', $('#export_sheet_name').length ? $('#export_sheet_name').val() : '');
        formData.append('file', dz.files[0]);
        formData.append('csrf_token', window.csrfToken);

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
