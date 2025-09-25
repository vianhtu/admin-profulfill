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
    // URL helpers
    function getUrlParam(name) { return new URLSearchParams(window.location.search).get(name); }
    function setUrlParam(name, value, useReplace = false) {
        const url = new URL(window.location.href);
        const params = url.searchParams;
        if (value === '' || value == null) params.delete(name); else params.set(name, value);
        const newUrl = `${url.pathname}${params.toString() ? '?' + params.toString() : ''}`;
        if (useReplace) window.history.replaceState({}, '', newUrl); else window.history.pushState({}, '', newUrl);
    }
    function deleteUrlParam(name, useReplace = false) {
        const url = new URL(window.location.href);
        url.searchParams.delete(name);
        const newUrl = `${url.pathname}${url.searchParams.toString() ? '?' + url.searchParams.toString() : ''}`;
        if (useReplace) window.history.replaceState({}, '', newUrl); else window.history.pushState({}, '', newUrl);
    }
    function onPopState(cb) { window.addEventListener('popstate', cb); }

    // Generic binder with select2-multiple support
    function bindInputToUrlAndTable(opts) {
        const {
            element,
            paramName,
            defaultValue = '',
            useReplace = false,
            debounceMs = 150,
            resetPagingOnChange = true,
            readValue,
            writeValue
        } = opts;
        const $el = element.jquery ? element : $(element);

        // detect select2 multiple or native multiple
        const el = $el[0];
        const isSelect = el && el.tagName === 'SELECT';
        const isMultiple = isSelect && (el.multiple === true || $el.prop('multiple') === true);

        // Defaults for read/write
        const detect = {
            read: () => {
                if (typeof readValue === 'function') return readValue($el);
                if (isSelect && isMultiple) {
                    const val = $el.val() || [];
                    return Array.isArray(val) ? val.join(',') : String(val || '');
                }
                if (isSelect) return $el.val() ?? '';
                // fallback for other inputs
                return $el.val && $el.val() !== undefined ? $el.val() : (el ? el.value : '');
            },
            write: (v) => {
                if (typeof writeValue === 'function') return writeValue($el, v);
                if (isSelect && isMultiple) {
                    const arr = v ? String(v).split(',') : [];
                    // set value array for select2 / native select multiple
                    $el.val(arr).trigger('change.select2 change');
                    return;
                }
                if (isSelect) { $el.val(v).trigger('change.select2 change'); return; }
                if ($el.val) { $el.val(v); $el.trigger('input change'); } else if (el) { el.value = v; }
            }
        };

        // Initial value: URL wins
        const urlVal = getUrlParam(paramName);
        const initial = (urlVal !== null) ? urlVal : (defaultValue || '');
        if (initial !== null && initial !== undefined && initial !== '') detect.write(initial);
        else if (defaultValue) { detect.write(defaultValue); setUrlParam(paramName, defaultValue, useReplace); }

        // debounce reload
        let timer = null;
        function scheduleReload(resetPaging = true) {
            if (timer) clearTimeout(timer);
            timer = setTimeout(() => {
                if (typeof api.ajax === 'function') api.ajax.reload(null, resetPagingOnChange && resetPaging);
                else if (typeof api.draw === 'function') api.draw(false);
                timer = null;
            }, debounceMs);
        }

        // attach events
        if (isSelect && isMultiple) {
            // Select2 multiple and native multiple emit change; read returns CSV
            $el.on('change', () => {
                const v = detect.read() ?? '';
                if (v === '' || v == null) deleteUrlParam(paramName); else setUrlParam(paramName, String(v));
                scheduleReload(true);
            });
        } else if (isSelect) {
            $el.on('change', () => {
                const v = detect.read() ?? '';
                if (v === '' || v == null) deleteUrlParam(paramName); else setUrlParam(paramName, String(v));
                scheduleReload(true);
            });
        } else {
            // generic inputs
            $el.on('input change', () => {
                const v = detect.read() ?? '';
                if (v === '' || v == null) deleteUrlParam(paramName); else setUrlParam(paramName, String(v));
                scheduleReload(true);
            });
        }

        // popstate: sync UI from URL
        onPopState(() => {
            const v = getUrlParam(paramName) || '';
            detect.write(v);
            scheduleReload(true);
        });

        return {
            read: () => detect.read(),
            write: (v) => detect.write(v),
            syncFromUrl: () => { detect.write(getUrlParam(paramName) || ''); }
        };
    }

    // DataTable column setup using binder
    api.columns(col).every(function () {
        const column = this;
        const $container = $(html_class).empty();

        // Label
        $container.append(`<label class="form-label" for="${id}">${label}</label>`);

        // If options provided, create select (support multiple via options.multiple=true)
        if (options && Object.keys(options).length > 0) {
            const multipleAttr = options.multiple ? ' multiple' : '';
            const $select = $(`<select id="${id}" class="form-select text-capitalize"${multipleAttr}>
                           <option value="">All</option>
                         </select>`).appendTo($container);
            $.each(options, (key, val) => {
                const $opt = new Option(val.title, key, false, false);
                $select.append($opt);
            });
            // init select2; let caller pass select2 options in options.select2Config
            const s2conf = options.select2Config || { dropdownParent: $container };
            $select.select2(s2conf);

            // bind; for multiple select2, binder will detect and handle CSV
            bindInputToUrlAndTable({
                element: $select,
                paramName: id,
                defaultValue: selected || '',
                useReplace: true,
                debounceMs: 150,
                resetPagingOnChange: true
            });
            return;
        }

        // otherwise create a text input by default
        const $input = $(`<input id="${id}" class="form-control" type="text" value="${selected || ''}" />`).appendTo($container);
        bindInputToUrlAndTable({
            element: $input,
            paramName: id,
            defaultValue: selected || '',
            useReplace: true,
            debounceMs: 150,
            resetPagingOnChange: true
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