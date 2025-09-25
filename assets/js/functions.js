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
            const $opt = new Option(val.title, key, false, false);
            $select.append($opt);
        });

        // Select2
        $select.select2({ dropdownParent: $container });

        // URL helpers
        function getUrlParam(name) {
            return new URLSearchParams(window.location.search).get(name);
        }
        function updateUrlParam(name, value, useReplace = false) {
            const url = new URL(window.location.href);
            const params = url.searchParams;
            if (value === '' || value == null) params.delete(name); else params.set(name, value);
            const newUrl = `${url.pathname}?${params.toString()}`;
            if (useReplace) window.history.replaceState({}, '', newUrl); else window.history.pushState({}, '', newUrl);
        }

        // Decide initial UI value: URL wins over selected
        const urlValue = getUrlParam(id);
        const initialValue = urlValue !== null ? urlValue : (selected || '');

        // Set select UI only (no column.search or draw here).
        if (initialValue) {
            $select.val(initialValue).trigger('change.select2');
        } else if (selected) {
            $select.val(selected).trigger('change.select2');
            // optional: reflect default selected into URL if you prefer
            // updateUrlParam(id, selected, true);
        }

        // Debounced reload to avoid multiple quick requests
        let _reloadTimer = null;
        function scheduleReload(resetPaging = true) {
            if (_reloadTimer) clearTimeout(_reloadTimer);
            _reloadTimer = setTimeout(() => {
                // api is DataTables Api instance passed into the function
                if (typeof api.ajax === 'function') {
                    // resetPaging true -> go to first page
                    api.ajax.reload(null, resetPaging);
                } else if (typeof api.draw === 'function') {
                    api.draw(false);
                }
                _reloadTimer = null;
            }, 150);
        }

        // When user changes select: update URL and reload table
        $select.on('change', function () {
            const value = this.value || '';
            updateUrlParam(id, value); // pushState; change to replaceState by passing true if desired
            scheduleReload(true); // typically reset to page 1 on filter change
        });

        // Sync when navigating back/forward
        window.addEventListener('popstate', () => {
            const v = getUrlParam(id) || '';
            $select.val(v).trigger('change.select2');
            scheduleReload(true);
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