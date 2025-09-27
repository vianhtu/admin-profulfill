/**
 * App eCommerce Add Product Script
 */
'use strict';
//Jquery to handle the e-commerce product add page
function init(){
    // get custom header.
    dropzoneFileUpload();
    repeaterOptions();
    const fv = formValidate();

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
        formData.append('file', myDropzone.files[0]);
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

function repeaterOptions(){

    var formRepeater = $('.form-repeater');

    if (formRepeater.length) {
        var row = 2;
        var col = 1;

        // Hàm cập nhật option disable để tránh trùng
        function updateSelectOptions($container) {
            var selectedValues = [];
            $container.find('.form-select').each(function () {
                var val = $(this).val();
                if (val) selectedValues.push(val);
            });

            $container.find('.form-select').each(function () {
                var $select = $(this);
                $select.find('option').each(function () {
                    var optionVal = $(this).val();
                    if (
                        optionVal &&
                        selectedValues.includes(optionVal) &&
                        optionVal !== $select.val()
                    ) {
                        $(this).attr('disabled', true);
                    } else {
                        $(this).attr('disabled', false);
                    }
                });
            });

            // Refresh lại select2
            $container.find('.form-select').select2();
        }

        formRepeater.repeater({
            show: function () {
                var fromControl = $(this).find('.form-control, .form-select');
                var formLabel = $(this).find('.form-label');

                fromControl.each(function (i) {
                    var id = 'form-repeater-' + row + '-' + col;
                    $(fromControl[i]).attr('id', id);
                    $(formLabel[i]).attr('for', id);
                    col++;
                });

                $(this).find('input').val('');

                row++;
                $(this).slideDown();

                // Khởi tạo lại select2
                $('.select2-container').remove();
                $('.select2.form-select').select2({
                    placeholder: 'Placeholder text'
                });
                $('.select2-container').css('width', '100%');
                $('.form-repeater:first .form-select').select2({
                    dropdownParent: $(this).parent(),
                    placeholder: 'Placeholder text'
                });
                $('.position-relative .select2').each(function () {
                    $(this).select2({
                        dropdownParent: $(this).closest('.position-relative')
                    });
                });

                updateSelectOptions($(this).closest('.form-repeater'));
            },
            hide: function (deleteElement) {
                $(this).slideUp(deleteElement);
                setTimeout(() => {
                    updateSelectOptions($(this).closest('.form-repeater'));
                }, 300);
            }
        });

        // Xử lý click nút xóa
        $(document).on('click', '.btn-delete-row', function (e) {
            e.preventDefault();
            var $row = $(this).closest('[data-repeater-item]');
            $row.slideUp(function () {
                $row.remove();
                updateSelectOptions(formRepeater);
            });
        });

        // Cập nhật khi thay đổi lựa chọn
        $(document).on('change', '.form-repeater .form-select', function () {
            updateSelectOptions($(this).closest('.form-repeater'));
        });

        updateSelectOptions(formRepeater);
    }
}

function repeaterSelect2Validate($select, fv){
    // Lấy name sau khi repeater đã gán
    const fieldName = $select.prop('name');

    // Nếu cần validate giá trị selected không nằm trong selectOptions
    const currentVal = $select.val();
    if (selectOptions.length > 0 && currentVal) {
        const isValid = selectOptions.some(opt => opt.id === currentVal);
        if (!isValid) {
            fv.addField(fieldName, {
                validators: {
                    callback: {
                        message: 'Giá trị không tồn tại trong danh sách',
                        callback: () => false
                    }
                }
            });
            fv.validateField(fieldName);
        }
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
        },
    };
    return FormValidation.formValidation(formEl, {
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
}

function dropzoneFileUpload(){
    const hiddenInput = document.getElementById('xlsxFilePresent');
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
    // Đồng bộ trạng thái Dropzone <-> hidden input và revalidate
    if (myDropzone) {
        myDropzone.on('addedfile', function(file) {
            hiddenInput.value = file.name;
            fv.revalidateField('xlsxFilePresent');
            // Lấy tên file
            document.getElementById('fileTitle').textContent = file.name;
        });
        myDropzone.on('removedfile', function(file) {
            if (myDropzone.files.length === 0) {
                hiddenInput.value = '';
                fv.revalidateField('xlsxFilePresent');
                document.getElementById('fileTitle').textContent = '';
            }
        });
        myDropzone.on('success', function(file) {
            if ($('#export_id').val() !== '') {
                setTimeout(function() {
                    $('#export_submit').trigger('click');
                }, 1000); // 2000 milliseconds = 2 seconds
            }
        });
    }
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

document.addEventListener('DOMContentLoaded', function (e) {
    init();
});
