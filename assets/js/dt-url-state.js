/**
 * dt-url-state — đồng bộ trạng thái xem của DataTables với URL (CLAUDE.md mục 6).
 *
 * Mọi bảng danh sách phải đẩy filter / search / sắp xếp / phân trang / số dòng lên query
 * string và dựng lại được từ đó. URL là thứ duy nhất chia sẻ, bookmark, Back và F5 giữ được.
 *
 * Cách dùng ở mỗi trang:
 *
 *   const urlState = dtUrlState({ UserRole: '#UserRole', UserStatus: '#UserStatus' });
 *   const dt = new DataTable(el, {
 *       ...urlState.tableOptions(COLUMN_KEYS),   // order/displayStart/pageLength/search
 *       ...
 *   });
 *   urlState.bind(dt);                            // ghi URL mỗi lần bảng vẽ lại
 *   urlState.applyFilters();                      // đổ giá trị URL vào các ô lọc
 *
 * `COLUMN_KEYS` = mảng `data` của từng cột theo đúng thứ tự khai trong `columns`, để đổi
 * qua lại giữa tên cột trên URL và chỉ số cột của DataTables.
 */

'use strict';

/**
 * `dt.order()` trả về BA dạng khác nhau tuỳ cách sắp xếp được đặt:
 *   [2, 'desc']              — khi gọi .order([2,'desc'])
 *   [[2, 'desc']]            — dạng lồng (config `order`)
 *   [{ idx: 2, dir: 'desc' }] — DataTables 2.x khi người dùng bấm vào tiêu đề cột
 * Gom cả ba về [idx, dir]. Bỏ sót dạng nào là sort lặng lẽ không lên URL.
 *
 * @return {[number,string]|null}
 */
function normalizeOrder(ord) {
    if (!ord || !ord.length) {
        return null;
    }
    if (typeof ord[0] === 'number') {
        return [ord[0], String(ord[1] || 'asc')];
    }
    const first = ord[0];
    if (Array.isArray(first)) {
        return [first[0], String(first[1] || 'asc')];
    }
    if (first && typeof first === 'object' && 'idx' in first) {
        return [first.idx, String(first.dir || 'asc')];
    }
    return null;
}

function dtUrlState(filterMap, defaultLength) {
    const map = filterMap || {};
    // Mặc định số dòng/trang của CHÍNH trang đó (Roles 25, Users 10...). Cần để tính đúng
    // displayStart khi URL có `page` mà không có `len`, và để bỏ `len` khi nó bằng mặc định.
    const defLen = defaultLength || 10;
    const params = new URLSearchParams(window.location.search);

    // `menu` (và `form`) là tham số định tuyến của index.php — luôn phải giữ lại
    const KEEP = ['menu', 'form', 'id'];

    return {
        /** Giá trị đang có trên URL cho một ô lọc (rỗng nếu không có). */
        get(name) {
            return params.get(name) || '';
        },

        /**
         * Phần config nhét thẳng vào `new DataTable(...)` để bảng vẽ ĐÚNG NGAY LẦN ĐẦU —
         * đọc URL trước khi khởi tạo thì không phải draw lần hai.
         */
        tableOptions(columnKeys) {
            const opt = {};
            const sort = params.get('sort');
            const dir = (params.get('dir') || '').toLowerCase() === 'desc' ? 'desc' : 'asc';
            if (sort && Array.isArray(columnKeys)) {
                const idx = columnKeys.indexOf(sort);
                if (idx >= 0) {
                    opt.order = [[idx, dir]];
                }
            }
            const len = parseInt(params.get('len'), 10);
            if ([10, 25, 50, 100].includes(len)) {
                opt.displayLength = len;
            }
            const page = parseInt(params.get('page'), 10);
            if (page > 1) {
                // displayStart tính theo số dòng, phải khớp pageLength thực sự dùng
                opt.displayStart = (page - 1) * (opt.displayLength || defLen);
            }
            const q = params.get('q');
            if (q) {
                opt.search = { search: q };
            }
            return opt;
        },

        /** Đổ giá trị từ URL vào các ô lọc. Ô không tồn tại (tuỳ quyền) thì bỏ qua. */
        applyFilters() {
            let any = false;
            Object.entries(map).forEach(([name, sel]) => {
                const v = params.get(name);
                const $el = window.jQuery ? jQuery(sel) : null;
                if (v && $el && $el.length) {
                    $el.val(v).trigger('change.select2');
                    any = true;
                }
            });
            return any;   // true = có lọc dựng sẵn -> trang nên mở khối Filter cho thấy
        },

        /** Ghi trạng thái hiện tại lên URL sau mỗi lần bảng vẽ lại. */
        bind(dt, columnKeys) {
            if (!dt) {
                return;
            }
            const write = () => {
                const info = dt.page.info();
                const next = new URLSearchParams(window.location.search);

                // Giữ tham số định tuyến, xoá sạch phần trạng thái cũ rồi ghi lại
                [...next.keys()].forEach(k => {
                    if (!KEEP.includes(k)) {
                        next.delete(k);
                    }
                });

                Object.keys(map).forEach(name => {
                    const $el = window.jQuery ? jQuery(map[name]) : null;
                    const v = $el && $el.length ? $el.val() : '';
                    if (v) {
                        next.set(name, v);
                    }
                });

                const q = dt.search();
                if (q) {
                    next.set('q', q);
                }

                const ord = normalizeOrder(dt.order());
                if (ord && Array.isArray(columnKeys)) {
                    const key = columnKeys[ord[0]];
                    if (key) {
                        next.set('sort', key);
                        next.set('dir', ord[1]);
                    }
                }
                if (info.page > 0) {
                    next.set('page', String(info.page + 1));
                }
                // Bỏ giá trị mặc định cho URL còn ngắn và đọc được
                if (info.length !== defLen) {
                    next.set('len', String(info.length));
                }

                const qs = next.toString();
                history.replaceState(null, '', qs ? '?' + qs : window.location.pathname);
            };

            dt.on('draw.dt', write);
        }
    };
}
