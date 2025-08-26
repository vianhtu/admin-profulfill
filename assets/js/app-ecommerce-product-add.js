/**
 * App eCommerce Add Product Script
 */
'use strict';
//Jquery to handle the e-commerce product add page

$(function () {
  var formRepeater = $('.form-repeater');

  // Form Repeater
  // ! Using jQuery each loop to add dynamic id and class for inputs. You may need to improve it based on form fields.
  // -----------------------------------------------------------------------------------------------------------------

  if (formRepeater.length) {
    var row = 2;
    var col = 1;
    formRepeater.on('submit', function (e) {
      e.preventDefault();
    });
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

        row++;
        $(this).slideDown();
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
      }
    });
  }
});

document.addEventListener('DOMContentLoaded', function (e) {
    // Select2
    var select2 = $('#export_type,#export_site,#export_author');
    if (select2.length) {
        select2.each(function () {
            var $this = $(this);
            $this.wrap('<div class="position-relative"></div>').select2({
                dropdownParent: $this.parent(),
                //placeholder: $this.data('placeholder') // for dynamic placeholder
            });
        });
    }
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

    // ? Start your code from here

    // Basic Dropzone
    let myDropzone;
    const dropzoneBasic = document.querySelector('#dropzone-basic');
    if (dropzoneBasic) {
        myDropzone = new Dropzone(dropzoneBasic, {
            previewTemplate: previewTemplate,
            parallelUploads: 1,
            maxFilesize: 5,
            acceptedFiles: '.xlsx',
            addRemoveLinks: true,
            maxFiles: 1
        });
    }

    //getMultipleSelect('export_accounts', 'accountsExport', 'Select Account', 'filter-accounts', false);

    $('#export_submit').on('click', function (e) {
        e.preventDefault();

        let isValid = true;

        $('#export-name, #accountsExport, #export_type, #export_site, #export_author').each(function () {
            const value = ($(this).val() || '').trim();
            if (!value) {
                $(this).addClass('is-invalid').removeClass('is-valid');
                isValid = false;
            } else {
                $(this).removeClass('is-invalid').addClass('is-valid');
            }
        });

        if (isValid) {
            const $btn = $(this);
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
            formData.append('name', $('#export-name').val());
            formData.append('id', id);

            if (myDropzone && myDropzone.files.length > 0) {
                formData.append('file', myDropzone.files[0]);
            } else if(id === '' || id === null || id === undefined) {
                alert('Vui lòng chọn một file.');
                return;
            }

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
        }
    });
});
