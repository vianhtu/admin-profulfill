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

function ajaxSelectV2(select_id, folow_id, type, multiple = false) {
    $('#' + select_id).select2({
        placeholder: 'Tìm và chọn...',
        multiple: multiple,
        ajax: {
            // URL nên giữ action cố định, 'type' sẽ được gửi trong POST data
            url: '../../ajax.php?action=get-common-filter',
            dataType: 'json',
            type: 'POST',
            delay: 300,
            data: function (params) {
                return {
                    q: params.term || '',
                    page: params.page || 1,
                    type: type,
                    folow_val: folow_id ? $('#' + folow_id).val() : null
                };
            },
            processResults: function (data, params) {
                // PHP bản mới trả về { results: [...], pagination: { more: true/false } }
                // Nếu PHP đã format đúng 'text' và 'id', bạn không cần .map() lại
                return {
                    results: data.results || [],
                    pagination: {
                        more: data.pagination ? data.pagination.more : false
                    }
                };
            },
            cache: true
        },
        minimumInputLength: 0, // Nên để 0 để khi click vào là hiện danh sách ngay (nếu muốn)
        language: {
            inputTooShort: () => 'Gõ ít nhất 1 ký tự',
            searching: () => 'Đang tìm...',
            noResults: () => 'Không có kết quả',
            errorLoading: () => 'Lỗi tải dữ liệu'
        }
    });
}

// ---- URL helpers (tái sử dụng cho mọi input) ----
function getUrlParam(name) {
    return new URLSearchParams(window.location.search).get(name);
}
function setUrlParam(name, value, useReplace = false) {
    const url = new URL(window.location.href);
    const params = url.searchParams;
    if (value === '' || value == null) params.delete(name); else params.set(name, value);
    const newUrl = `${url.pathname}?${params.toString()}`;
    if (useReplace) window.history.replaceState({}, '', newUrl); else window.history.pushState({}, '', newUrl);
}
function deleteUrlParam(name, useReplace = false) {
    const url = new URL(window.location.href);
    const params = url.searchParams;
    params.delete(name);
    const newUrl = `${url.pathname}?${params.toString()}`;
    if (useReplace) window.history.replaceState({}, '', newUrl); else window.history.pushState({}, '', newUrl);
}
function onPopState(cb) {
    window.addEventListener('popstate', cb);
}

// ---- Generic binder: gắn bất kỳ input -> URL + reload DataTables ----
// optionsBinder: { element: jQuery|DOM, paramName: string, readValue?: fn, writeValue?: fn, defaultValue?: string, useReplace?: bool, debounceMs?: number, resetPagingOnChange?: bool }
function bindInputToUrlAndTable(table_api,optionsBinder) {
    const {
        element,
        paramName,
        readValue,
        writeValue,
        defaultValue = '',
        useReplace = false,
        debounceMs = 150,
        resetPagingOnChange = true
    } = optionsBinder;

    const $el = element.jquery ? element : $(element);

    const _read = typeof readValue === 'function'
        ? () => readValue($el)
        : () => ($el.val && $el.val() !== undefined ? $el.val() : ($el[0] ? $el[0].value : ''));

    const _write = typeof writeValue === 'function'
        ? (v) => writeValue($el, v)
        : (v) => { if ($el.val) { $el.val(v); $el.trigger('change'); } else if ($el[0]) { $el[0].value = v; } };

    // Initial value: URL wins over provided default
    const urlVal = getUrlParam(paramName);
    const initial = (urlVal !== null) ? urlVal : (defaultValue || '');

    if (initial !== null && initial !== undefined && initial !== '') {
        _write(initial);
    } else if (defaultValue) {
        _write(defaultValue);
        // optionally reflect default into URL without creating history entry
        setUrlParam(paramName, defaultValue, useReplace);
    }

    // debounce reload helper
    let timer = null;
    function scheduleReload(resetPaging = true) {
        if (timer) clearTimeout(timer);
        timer = setTimeout(() => {
            if (typeof table_api.ajax === 'function') {
                table_api.ajax.reload(null, resetPagingOnChange && resetPaging);
            } else if (typeof table_api.draw === 'function') {
                table_api.draw(false);
            }
            timer = null;
        }, debounceMs);
    }

    // input change -> update URL -> reload
    $el.on('change input', () => {
        const v = _read() ?? '';
        if (v === '' || v == null) deleteUrlParam(paramName);
        else setUrlParam(paramName, String(v));
        scheduleReload(true);
    });

    // popstate -> sync UI from URL and reload
    onPopState(() => {
        const v = getUrlParam(paramName) || '';
        _write(v);
        scheduleReload(true);
    });

    // return control handle if caller needs it
    return {
        read: () => _read(),
        write: (v) => _write(v),
        syncFromUrl: () => { const v = getUrlParam(paramName) || ''; _write(v); }
    };
}

function getSelect2filterTable(api, id, html_class, col, label, options = {}, selected = '') {
    // ---- Original DataTable column setup, now using the binder ----
    api.columns(col).every(function () {
        const column = this;
        const $container = $(html_class).empty();

        // Label
        $container.append(`<label class="form-label" for="${id}">${label}</label>`);

        // Select element
        const $select = $(`<select id="${id}" class="form-select text-capitalize">
                         <option value="">All</option>
                       </select>`).appendTo($container);

        // Options
        $.each(options, (key, val) => {
            const $opt = new Option(val.title, key, false, false);
            $select.append($opt);
        });

        // Init select2
        $select.select2({ dropdownParent: $container });

        // Decide initial UI value: URL wins over selected
        const urlValue = getUrlParam(id);
        const initialValue = (urlValue !== null) ? urlValue : (selected || '');

        if (initialValue) {
            $select.val(initialValue).trigger('change.select2');
        } else if (selected) {
            $select.val(selected).trigger('change.select2');
        }

        // Bind using generic binder (default read/write works for select2 single)
        bindInputToUrlAndTable(api,{
            element: $select,
            paramName: id,
            defaultValue: selected || '',
            useReplace: true,
            debounceMs: 150,
            resetPagingOnChange: true
        });
    });
}

async function fetchTableFilter(action = 'get-products-table-filter', data = {}){
    const res = await fetch('../../ajax.php?action='+ action, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams(data)
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

function toLocalDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        timeZone: 'Asia/Ho_Chi_Minh'
    });
}

function formatNumberVN(number) {
    return number.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
}