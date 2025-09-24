'use strict';

function getAjaxSelect2HTML(div_class, select_id, select_label, action, multiple = false) {
    $('.'+div_class).html('<label class="form-label">'+select_label+'</label><select id="'+select_id+'"></select>');
    ajaxSelect2(select_id, action, multiple);
}

function ajaxSelect2(select_id, action, multiple = false){
    $('#'+select_id).select2({
        placeholder: 'Tìm và chọn...',
        multiple: multiple,
        ajax: {
            url: '../../ajax.php?action='+action,
            dataType: 'json',
            type: 'POST',
            delay: 250,                   // debounce
            data: function (params) {
                return {
                    q: params.term || '',     // từ khóa người dùng gõ
                    page: params.page || 1    // phân trang (nếu có)
                };
            },
            processResults: function (data, params) {
                // Kỳ vọng data: { items: [{id, name}], more: boolean }
                const results = (data.items || []).map(item => ({
                    id: item.id,
                    text: item.name
                }));
                return {
                    results: results,
                    pagination: { more: !!data.more }
                };
            },
            cache: true
        },
        minimumInputLength: 1,
        language: {
            inputTooShort: () => 'Gõ ít nhất 1 ký tự',
            searching: () => 'Đang tìm...',
            noResults: () => 'Không có kết quả'
        }
    });
}

function getSelect2filterTable(api, id, html_class, col, label, options = {}, selected = '') {
    api.columns(col).every(function () {
        const column = this;
        const $container = $(html_class).empty();

        // Label
        $container.append(`<label class="form-label" for="${id}">${label}</label>`);

        // Select
        const $select = $(`<select id="${id}" class="form-select text-capitalize">
                              <option value="">All</option>
                           </select>`).appendTo($container);

        // Options
        $.each(options, (key, val) => {
            const $opt = new Option(val.title, key, false, key === selected);
            $select.append($opt);
        });

        // Select2
        $select.select2({
            dropdownParent: $container
        });

        // Nếu có selected thì filter ngay
        if (selected) {
            const val = `^${selected}$`;
            column.search(val, true, false).draw();
        }

        // Event filter khi user chọn
        $select.on('change', function () {
            const val = this.value ? `^${this.value}$` : '';
            column.search(val, true, false).draw();
        });
    });
}

async function fetchTableFilter(action = 'get-products-table-filter'){
    const res = await fetch('../../ajax.php?action='+ action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }
    });
    if (!res.ok) throw new Error('Lỗi lấy danh mục');
    return await res.json();
}

function showAlert(alertId, message, type = 'danger') {
    const alertBox = document.getElementById(alertId);
    if (!alertBox) return;

    // Xóa các class trạng thái cũ
    alertBox.classList.remove('d-none', 'alert-success', 'alert-danger', 'alert-warning', 'show');

    // Thêm class trạng thái mới
    alertBox.classList.add('alert', `alert-${type}`);

    // Gán nội dung
    alertBox.innerText = message;

    alertBox.classList.remove('d-none'); // bỏ ẩn
    setTimeout(() => {
        alertBox.classList.add('show'); // fade in
    }, 10); // delay nhỏ để CSS transition hoạt động

    // Tự ẩn sau 3 giây
    setTimeout(() => {
        alertBox.classList.remove('show'); // fade out
        setTimeout(() => {
            alertBox.classList.add('d-none'); // ẩn hẳn sau khi fade xong
        }, 150); // thời gian khớp với CSS transition
    }, 3000);

}