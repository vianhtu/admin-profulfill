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

    $('#accounts_status').on('change', function() {
        fv.revalidateField('accounts_status');
    });

    $('#account_site').on('change', function() {
        fv.revalidateField('account_site');
    });

    ajaxSelectV2('account_author', 'account_team', 'authors', true);
    ajaxSelectV2('link_accounts', 'account_team', 'accounts', true);
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

function getRepeaterData() {
    var data = [];

    // Cache lại selector để tăng hiệu suất
    var $repeaterItems = $('.form-repeater').find('[data-repeater-item]');

    $repeaterItems.each(function () {
        var $this = $(this); // Cache đối tượng hiện tại

        // .trim() để loại bỏ khoảng trắng dư thừa
        var key = ($this.find('.custom_text').val() || '').trim();
        var val = ($this.find('.custom_value').val() || '').trim();

        // Kiểm tra nếu cả hai đều có giá trị thực
        if (key && val) {
            data.push({
                text: key,
                value: val
            });
        }
    });

    return data;
}

function formValidate() {
    const formEl = document.getElementById('addXlsxFile');
    if (!formEl) return null; // Tránh lỗi nếu không tìm thấy form

    const fv = FormValidation.formValidation(formEl, {
        fields: {
            owner_name: {
                validators: {
                    notEmpty: { message: 'Please enter owner name.' }
                }
            },
            account_email: {
                validators: {
                    notEmpty: { message: 'Please enter account email.' },
                    emailAddress: { message: 'The value is not a valid email address.' }
                }
            },
            accounts_status: {
                validators: {
                    notEmpty: { message: 'Please select account status.' }
                }
            },
            account_site: {
                validators: {
                    notEmpty: { message: 'Please select account site.' }
                }
            }
        },
        plugins: {
            trigger: new FormValidation.plugins.Trigger(),
            bootstrap5: new FormValidation.plugins.Bootstrap5({
                eleValidClass: '',
                // Đảm bảo class này bao quanh input và div thông báo lỗi
                rowSelector: '.form-control-validation'
            }),
            submitButton: new FormValidation.plugins.SubmitButton(),
            autoFocus: new FormValidation.plugins.AutoFocus()
        }
    });

    return fv;
}

function dropzoneFileUpload(){
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
      <a class="dz-download" href="javascript:undefined;" data-dz-download><i class="ti ti-download"></i> Download</a>
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
            maxFiles: 10,
            // Hàm khởi tạo
            init: function() {
                const dzInstance = this;
                let accountId = $('#edit_id').val();
                // Nếu có accountId, tiến hành load file cũ
                if (accountId) {
                    $.ajax({
                        url: '../../ajax.php?action=get-account-upload-files',
                        type: 'POST',
                        data: {
                            account_id: accountId
                        },
                        dataType: 'json',
                        success: function(data) {
                            if (Array.isArray(data)) {
                                data.forEach(file => {
                                    let mockFile = {
                                        name: file.name,
                                        size: file.size,
                                        serverId: file.id,
                                        accepted: true
                                    };

                                    // 1. Hiển thị file
                                    dzInstance.displayExistingFile(mockFile, file.url);

                                    // 2. Ép Dropzone hiểu là file này đã upload thành công 100%
                                    // Điều này giúp ẩn thanh loading/progress bar đi
                                    dzInstance.emit("complete", mockFile);

                                    // 3. Đưa vào danh sách quản lý
                                    dzInstance.files.push(mockFile);
                                });
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error("Lỗi khi load file cũ:", error);
                        }
                    });
                }

                // Xử lý sự kiện xóa file
                this.on("removedfile", function(file) {
                    // Nếu là file cũ (có serverId), gửi request xóa liên kết trong DB
                    if (file.serverId) {
                        $.ajax({
                            url: '../../ajax.php?action=delete-account-upload-file',
                            type: 'POST',
                            data: {
                                file_id: file.serverId,
                                account_id: accountId // Biến accountId bạn đã lấy từ $('#edit_id').val()
                            },
                            dataType: 'json',
                            success: function(response) {
                                if (response.success) {
                                    console.log("Đã xóa liên kết file thành công.");
                                } else {
                                    console.error("Lỗi từ server:", response.message);
                                    // Tùy chọn: Thông báo cho người dùng nếu xóa thất bại
                                }
                            },
                            error: function(xhr, status, error) {
                                console.error("Lỗi kết nối khi xóa file:", error);
                            }
                        });
                    }
                });
            }
        });
    }
    return myDropzone;
}

function addAccount(fv, dz) {
    // Xóa event cũ trước khi gán mới để tránh bị lặp (nếu hàm này bị gọi lại)
    fv.off('core.form.valid');

    fv.on('core.form.valid', function() {
        const $btn = $('#form_submit');
        const $spinner = $('#loading_spinner');

        // Hiển thị spinner và disable nút
        $spinner.removeClass('d-none');
        $btn.prop('disabled', true);

        const formData = new FormData();

        // Nhóm các selector lại để code gọn hơn
        const fields = {
            'name': '#owner_name',
            'address': '#owner_address',
            'dob': '#owner_dob',
            'ssn': '#owner_ssn',
            'phone': '#owner_phone',
            'email': '#account_email',
            'sku': '#account_sku',
            'id': '#account_id',
            'password': '#account_password',
            '2fa': '#account_2fa',
            'date': '#accounts_date',
            'status': '#accounts_status',
            'site': '#account_site',
            'team': '#account_team',
            'authors': '#account_author',
            'accounts': '#link_accounts',
            'note': '#account_note',
            '_id': '#edit_id'
        };

        // Duyệt qua object để append vào FormData
        for (let key in fields) {
            formData.append(key, $(fields[key]).val() || '');
        }

        // Lấy toàn bộ file (Chỉ lấy file hợp lệ)
        const acceptedFiles = dz.getAcceptedFiles();
        acceptedFiles.forEach(function(file) {
            formData.append('files[]', file);
        });

        // Thêm dữ liệu từ repeater
        formData.append('options', JSON.stringify(getRepeaterData()));

        // CSRF Token
        formData.append('csrf_token', window.csrfToken);

        $.ajax({
            url: '../../ajax.php?action=add-account',
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            dataType: 'json', // Ép kiểu dữ liệu trả về là JSON
            success: function (response) {
                if (response.status === 'inserted' || response.status === 'updated') {
                    const newId = response.id || $('#export_id').val();
                    const url = new URL(window.location.href);
                    url.searchParams.set('id', newId);
                    window.location.href = url.toString();
                } else {
                    alert('Lỗi: ' + (response.message || 'Không xác định'));
                }
            },
            error: function (xhr) {
                console.error('Lỗi hệ thống:', xhr.responseText);
                alert('Có lỗi xảy ra trong quá trình gửi dữ liệu!');
            },
            complete: function () {
                $spinner.addClass('d-none');
                $btn.prop('disabled', false);
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', function (e) {
    init();
});
