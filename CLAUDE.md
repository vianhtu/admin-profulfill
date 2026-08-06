# CLAUDE.md — admin-profulfill

Hướng dẫn cho AI (Claude Code) làm việc trong repo này. **Đọc kỹ trước khi sửa code.**
File này được git theo dõi nên là nguồn kiến thức chung, đi theo code sang mọi máy/phiên.
Người dùng đọc tiếng Việt — mọi trao đổi bằng tiếng Việt; nhưng **chuỗi hiển thị trong app
(label, alert, message JSON) phải bằng tiếng Anh**.

> ⚠️ **KHÔNG đưa secret vào file này** (repo từng bị lộ lên GitHub public). Không viết mật khẩu
> DB, key mã hoá, token, đường dẫn SSH key vào đây hay bất kỳ file được git theo dõi nào.

## 1. Đây là gì

Backend PHP của `profulfill.io` — bảng quản trị dựng trên template **Vuexy** (Bootstrap 5,
HTML admin). Chạy PHP 8.4 + mysqli (bật exception), MariaDB, nginx + php8.4-fpm trên Ubuntu
(Vultr, RAM thấp ~955MB — cẩn thận thao tác ngốn bộ nhớ). Extension Chrome (repo khác) đẩy dữ
liệu listing Etsy vào đây qua `ajax.php?action=extension-*` (xác thực bằng key/email, không
theo session admin).

## 2. Triển khai & môi trường

- **Deploy = git push.** Webhook trên server tự pull. Sau `git push`, **chờ ~10s** rồi kiểm:
  `ssh ... "git -c safe.directory=<path> -C <path> log --oneline -1"` khớp commit vừa push mới
  chạy/kiểm thử — webhook trễ vài giây, chạy sớm ra kết quả code cũ.
- **Server**: `45.76.185.106`, code tại `/var/www/html/admin-profulfill`. SSH key + DB cred do
  người dùng cấu hình cục bộ — **hỏi người dùng**, đừng ghi vào repo.
- **PHP chạy dưới `www-data`.** Thao tác file `uploads/` qua SSH bằng root rồi để nguyên sẽ làm
  PHP không ghi được → luôn `chown www-data:www-data` sau, hoặc `sudo -u www-data`.
- **Cache-bust JS**: Cloudflare cache JS ~4h. Trang nạp page-JS kèm `?v=<?= filemtime(...) ?>`;
  giữ đúng để sửa JS có hiệu lực ngay.
- **THỨ TỰ đổi schema + code: deploy code TRƯỚC, đổi DB SAU.** Đổi bảng trước khi code kịp chạy
  sẽ làm code cũ văng lỗi (đã dính vụ đổi tên `type_teams` khi code chưa deploy). Code mới phải
  khoan dung với cả schema cũ lẫn mới trong lúc chuyển.

## 3. Kiến trúc

- **Router**: `html/vertical-menu-template-no-customizer/index.php` — 1 layout, chọn fragment
  theo `?menu=` và `?form=`. `ajax.php` — mọi call POST `?action=...`.
- **Class theo cặp** (giống `Orders`/`Order`): số nhiều = tập hợp (bảng danh sách, lọc, thao tác
  hàng loạt) — vd `class.products.php`; số ít = một bản ghi (form Add/Edit) — vd `class.product.php`.
  Đã có: Products/Product, Categories/Category, Sites/Site, Stores/Store, Orders/Order.
- **Mỗi trang gồm 4 mảnh**: class(es) trong `class/` + fragment `html/.../app-ecommerce-*-*.php`
  + JS `assets/js/app-ecommerce-*-*.js` + route trong `index.php` & `ajax.php`.
- **Helper dùng lại — TÌM TRƯỚC KHI VIẾT MỚI** (đừng tạo trùng): `handleFileUploads()` (upload),
  `build_order_by()` + `get_datatable_params()` (ORDER BY), `render_select()`, `get_all_*()`,
  `check_csrf()`, `get_current_team_scope_id()`. Cần tính năng tương đương thì **mở rộng helper
  sẵn có**, không viết bản song song.
- **Vuexy**: đọc tài liệu/demo template TRƯỚC khi code UI, theo đúng cấu trúc file, dùng widget có
  sẵn (mọi `<select>` = **select2**; flatpickr, tagify, dropzone, Quill...).

## 4. Mô hình sở hữu dữ liệu (quan trọng nhất — quyết định phân quyền)

Mỗi bảng lookup có kiểu sở hữu riêng, **hỏi người dùng nếu chưa rõ**:

| Kiểu | Bảng | Luật |
|---|---|---|
| **Theo team** | *(hiện không còn)* | mỗi dòng có `team_id` thật; team chỉ thấy dòng của mình; unique theo team. `type` từng dùng (04–05/08/2026) rồi bỏ vì team 2/3 có 0 category → không import được. **Kiểm phân bố dữ liệu trước khi đề xuất kiểu này.** |
| **Chung + riêng (lai)** | `store` (Stores) | `team_id = 0` = dùng chung (mọi team thấy; **chỉ admin** sửa/xoá); `team_id = N` = riêng của team N (team đó tự sửa/xoá theo role). Mỗi dòng thuộc tối đa 1 team. `slug` UNIQUE toàn bảng chống lặp. Extension tạo dòng dùng chung. |
| **Dùng chung + người tạo** | `site` (Sites), `type` (Category) | không phân team (`type.team_id` giữ nhưng luôn 0); **thêm** = ai có role `add`; **sửa** = admin HOẶC chính người đã thêm (`created_by`, vẫn cần role `edit`); **xoá** = chỉ admin. Dòng cũ `created_by = 0` → chỉ admin. Reach for kiểu này khi dữ liệu dùng chung cần cho non-admin ghi an toàn. |
| **Theo user** | `posts` (Products) | admin=toàn hệ thống; manager=toàn team; user=chỉ dòng của mình (`author_id`). |

Khi sở hữu khác nhau theo dòng: endpoint danh sách trả `can_edit`/`can_delete` **theo từng dòng**,
JS dựng nút từ cờ đó; `perms` cấp trang chỉ quyết định nút Add / Delete-Selected.

## 5. Phân quyền — 2 trục + 3 lớp (BẮT BUỘC mọi tính năng)

**Hai trục độc lập, phải thoả CẢ HAI:**
- **ROLE = được làm gì** (`roles_permissions.roles` JSON `{menu:{view,add,edit,delete}}`), kiểm bằng
  `checkRoles($action, $menu)`. **Chỉ admin bỏ qua. Manager KHÔNG bỏ qua** — nhiều quản lý chỉ được
  giao vài menu, vẫn phải có role.
- **PHẠM VI DỮ LIỆU = được đụng dòng nào**: admin=mọi team; manager=toàn team mình
  (`authors.team_id`); user=chỉ dòng mình (`posts.author_id`). Xem `Products::get_base_auth_conditions()`.

**Ba lớp chặn — UI → code → dữ liệu lưu. Áp dụng mà không cần nhắc:**
1. **UI**: control không đủ quyền thì **KHOÁ hay ẨN theo bảng dưới** — ẩn = **không render**
   (fragment `if` / template string), tuyệt đối không chỉ ẩn bằng CSS. **Áp dụng MỌI bảng:**

   | Vị trí | Thiếu quyền thì | Vì sao |
   |---|---|---|
   | **Nút LẺ** ở cột Actions từng dòng | **KHOÁ** (`disabled` + `title` nói rõ lý do) | ẩn làm các nút còn lại xô lệch, mỗi dòng một kiểu; nút khoá còn cho biết chức năng có tồn tại để đi hỏi quản trị |
   | **Nút CON trong nhóm** (dropdown/btn-group) | **ẨN** mục đó | |
   | **Nút CHA** khi mọi con đều ẩn | **KHOÁ** nút cha (không ẩn cả cụm) | giữ cột Actions thẳng hàng |
   | **Filter** | **ẨN** — không bao giờ khoá | không gắn với dòng nào, khoá chỉ là rác thị giác |
   | **Form Add/Edit** | **KHOÁ theo TỪNG TRƯỜNG** đúng những gì endpoint sẽ từ chối | đỡ phải bấm Save mới biết bị chặn |
   | **Action hàng loạt** (Delete Selected...) | **ẨN** | |

   Helper `lockedBtn(icon, why)` có sẵn ở đầu mỗi `app-*-list.js` (cạnh `esc()`).
   **NGOẠI LỆ — giá trị bí mật thì ẨN, đừng khoá**: trường `disabled` vẫn **hiện giá trị**, nên
   khoá chỉ chặn *sửa* chứ không chặn *đọc*. Lương (`wage`/`insurance`) phải ẩn hẳn với người
   không có quyền xem, không được chuyển sang khoá.
   Nhớ: KHOÁ/ẨN chỉ là lớp 1 — `disabled` gỡ bằng DevTools trong 2 giây, lớp 2 và 3 mới là bảo vệ thật.
2. **Code**: mọi endpoint tự kiểm lại role + phạm vi, coi request là giả mạo. Tham số role không
   được phép dùng thì **bỏ qua, không tin** (non-admin gửi `team_id`/`author_id` → ép về của họ).
   ID list phải lọc qua helper ownership trước khi ghi/xoá.
3. **Dữ liệu**: schema tự chặn (cột scope, UNIQUE hợp luật, từ chối xoá gây mồ côi).

Gán chủ sở hữu chéo team: category/store phải hợp lệ theo **team của CHỦ SỞ HỮU MỚI**, không phải
người đang sửa (admin gán sản phẩm cho user team khác thì category/store cũng phải thuộc team đó).

## 6. Trang danh sách DataTables (server-side) — quy ước

- **TRẠNG THÁI XEM PHẢI NẰM TRÊN URL — mọi bảng, không trừ bảng nào.** Filter (theo đúng tên ô
  sẵn có: `UserTeam`, `UserRole`, `UserStatus`...), Search, cột+chiều sắp xếp, trang hiện tại,
  số dòng/trang — tất cả đẩy lên query string và **đồng bộ hai chiều**: đổi trên bảng thì URL
  đổi theo, mở URL có sẵn tham số thì bảng dựng lại đúng trạng thái đó. URL là thứ duy nhất
  chia sẻ/bookmark/Back/F5 giữ được, và là cách các trang link sang nhau (Teams → Users
  `?UserTeam=<id>`, Roles → Users `?UserRole=<id>`).
  Dùng **helper chung** `assets/js/dt-url-state.js`, đừng chép lại từng file. Đọc tham số
  **TRƯỚC** khi khởi tạo DataTable rồi nhét vào config (`order`/`displayStart`/`pageLength`/
  `search`) để không vẽ bảng hai lần; ghi bằng `history.replaceState` (không `pushState`, kẻo
  mỗi lần đổi lọc lại thêm một mục lịch sử); bỏ tham số mang giá trị mặc định. Ô lọc có thể
  KHÔNG tồn tại tuỳ quyền → luôn kiểm `.length` trước khi gán. Tham số URL là dữ liệu người
  dùng: server vẫn whitelist cột sort qua `SORT_MAP` và kiểm phạm vi như thường.
- **ORDER BY**: mỗi class khai `SORT_MAP = ['<key DataTables>' => '<biểu thức SQL>']`, dùng
  `get_datatable_params(array_keys(SORT_MAP), '<mặc định>')` + `build_order_by($params, SORT_MAP, '<bảng>.ID')`.
  Cột hiển thị **tên** thì sort theo tên (`s.name`), không theo id. **Luôn kèm khoá phụ ID** (giá trị
  trùng khiến phân trang nhảy dòng). Cột không có trong map **bắt buộc** `orderable:false` bên JS.
  Ngoại lệ Products: phân trang trên subquery chỉ có `posts` (deferred join) → SORT_MAP chỉ chứa
  cột `posts.*`; cột Category/Author để `orderable:false`.
- **Thao tác hàng loạt nhiều dòng** (xoá store nghìn sản phẩm...): client gửi theo lô + progress bar,
  popup chỉ đóng khi xong; server cập nhật theo lô (vd 2.000 dòng), hết ngân sách/request trả
  `status:'partial'` để client gọi tiếp. **Không bọc 1 transaction khổng lồ**; sắp bước sao cho đứt
  giữa chừng vẫn chạy lại được (idempotent).
- **Xoá bản ghi cha có bảng khác tham chiếu**: hỏi người dùng cách xử lý. Đã quyết: Category/Site →
  chặn xoá khi còn dùng; Store → cho xoá, sản phẩm chuyển `inactive` + gỡ liên kết (`store_id=0`).
- **QUÉT CẢ DB TRƯỚC KHI CODE xoá / chuyển giao / sáp nhập** (xoá user, xoá team, chuyển user sang
  team khác, bàn giao sản phẩm, merge team...). **Đọc code cũ là KHÔNG đủ** — phần lớn tham chiếu ở
  DB này không có FK thật nên bỏ sót chỉ làm dữ liệu lặng lẽ mồ côi, không báo lỗi gì. Quy trình:
  1. `SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='data'
     AND COLUMN_NAME LIKE '%team%'` (đổi `team` → `author`/`store`/`site`/`type`... tuỳ bản ghi),
     rồi `DESC <bảng>` lấy **đúng tên cột** — đừng suy theo quy ước: `salary` dùng cột `authors`
     chứ không phải `author_id`, nên câu DELETE cũ bị `col_exists()` bỏ qua, **chưa từng chạy**.
  2. Đếm dữ liệu thật + dòng mồ côi sẵn có cho từng cột tìm được.
  3. Mỗi bảng phải chốt luật với người dùng rồi mới code: **xoá theo / giữ lại / chuyển sang /
     gỡ liên kết về 0**. Dữ liệu dùng chung và dữ liệu kế toán (`salary`) thì giữ.
  4. Bảng giữ lại nhưng cột chủ sở hữu sắp mồ côi → **chụp thông tin trước khi xoá**
     (vd `salary.username_snapshot`), đừng để trơ ID vô nghĩa.
  5. Rà cả liên kết ra **dịch vụ ngoài**: xoá dòng `phones` KHÔNG huỷ số bên Telnyx (vẫn mất phí
     thuê bao) — nêu cho người dùng quyết, đừng tự gọi API huỷ.
  6. Viết AB TEST cho từng luật, kiểm hiệu quả THẬT bằng đọc lại DB sau thao tác.
  Đã dính 06/08/2026: xoá team bỏ sót `options` (chứa `openai_key`/`gemini_key` RIÊNG của team) →
  xoá team xong secret vẫn nằm lại trong DB; và bỏ sót `phones`.
- **THÊM BẢNG MỚI có cột liên kết (`team_id`/`author_id`/`account_id`/...) — chiều ngược lại, NGUY
  HIỂM HƠN**: mọi luồng xoá & chuyển giao ĐANG ĐÚNG bỗng thành THIẾU, và **không có gì báo lỗi** —
  code vẫn chạy, vẫn trả `success`, chỉ bỏ sót lặng lẽ. Thêm bảng/cột liên kết thì phải:
  1. Chốt luật với người dùng, sửa **mọi** luồng đang có: `Teams::purge_team()` + `merge_team()`,
     `Users::delete_users()` + `cleanup_user_refs()` + `cleanup_after_move()`, xoá Store/Category/Site.
  2. Cập nhật **số đếm ở modal xem trước** (người dùng phải thấy thứ sắp mất).
  3. Khai vào `LINKS` trong **`tests/schema-links.php`** rồi chạy `php tests/schema-links.php`.
     Chốt này quét `information_schema` mọi cột `%_id`/`authors`/`created_by`: **cột chưa khai =
     TRƯỢT**, và nó còn đếm dòng mồ côi nên bắt được cả luật khai đúng mà code làm sai.
  Chốt này ra đời vì chính commit thêm "xoá `phones` khi purge team" lại tạo đường mồ côi mới:
  `sms.phone_id` trỏ vào `phones` → xoá team 1 sẽ bỏ lại 288 tin nhắn mồ côi (đã vá cùng ngày).
- **Script dọn dữ liệu test (kể cả script tạm trong `/tmp`) phải xoá theo ĐÚNG ID vừa tạo**, hoặc
  kèm guard `AND ID NOT IN (SELECT DISTINCT team_id FROM authors)`. `DELETE ... WHERE name LIKE
  'ZZAB%'` trần trụi đã xoá nhầm team của skill ui-test (06/08/2026) làm 2 tài khoản test mất
  quyền đăng nhập.

## 7. Bảo mật — BẮT BUỘC

- **CSRF**: mọi handler ghi/xoá (kể cả hàng loạt) gọi `check_csrf()`; JS gửi kèm `window.csrfToken`.
  Thiếu một phía là hỏng nút. Lỗ hổng CSRF trên bulk-action đã vá 05/08/2026.
- **Stored XSS — escape KHI RENDER**: mọi trang list nhét field free-text (title sản phẩm nguồn Etsy,
  tên shop từ extension, tên/prompt category, tên/logo site) vào HTML qua template string của DataTables.
  **Luôn bọc `esc()`** (HTML-entity) mọi field người dùng khi render — có sẵn ở đầu mỗi
  `app-ecommerce-*-list.js`. Đừng dựa vào cấm ký tự lúc lưu (tên sàn cần dấu chấm/hoa).
- **Upload file** (`Site::upload_logo`): kiểm đuôi + MIME thật (`finfo`) + `getimagesize`; chặn
  dimensions > 4000 (pixel-flood DoS trước `imagecreatefrompng`); nhận biết file quá `post_max_size`
  qua `CONTENT_LENGTH` **trước** khi kiểm CSRF (nếu không báo nhầm "Invalid CSRF token"). Whitelist
  trường `logo` khi lưu (`^uploads/sites/...\.(png|jpe?g)$` hoặc tên icon dựng sẵn).
- **Path traversal khi xoá/ghi file theo đường dẫn từ input**: chặn `..` và null byte, rồi `realpath()`
  và xác nhận kết quả NẰM TRONG thư mục cho phép. Lỗ hổng `delete_logo_file` (replace='uploads/sites/
  ../../config.php') đã vá 05/08/2026.
- **Vuexy Dropzone GIẢ**: `assets/vendor/libs/dropzone/dropzone.js` ghi đè `uploadFiles()` bằng
  animation tiến trình mô phỏng — KHÔNG POST lên server, emit success với res = chuỗi `'success'`.
  Muốn upload thật: `autoProcessQueue:false` + tự `fetch()` trong `addedfile` (xem `uploadSiteLogo()`).
  Giữ "1 file" bằng cách gỡ file khác theo THAM CHIẾU, không theo chỉ số (mock ảnh cũ làm lệch chỉ số).
- **nginx**: `uploads/` deny-by-default; riêng `uploads/sites/` chỉ phục vụ tĩnh `.png/.jpg` (`.php`
  → 403, không qua php-fpm). `tests/` → 403. Đừng gỡ các lớp này.

## 8. Kiểm thử — 2 bộ (chạy CLI trên server, chỉ dùng khi được yêu cầu)

Không tạo/đăng nhập user thật được → test bằng script CLI giả lập `$_SESSION['auth']` theo từng vai,
gọi thẳng hàm thật. Dữ liệu test mang tiền tố `ZZAB`, **tự dọn kể cả khi lỗi**. `tests/` chỉ chạy CLI
(`PHP_SAPI` check) + nginx chặn 403 — vì file giả lập session người khác.

- **AB TEST** (`tests/ab-test/`, chạy `php tests/ab-test/run.php <Products|Category|Sites|Store|all>`):
  ma trận 9 actor × chức năng, xác minh luật đã khai báo (kể cả lớp UI qua `ab_render()` render fragment).
  Ô `!`=lỗ hổng, `x`=chặn nhầm. Ô sai thường do **phép thử chấm nhầm** (tham số giả mạo bị bỏ qua
  chứ không báo lỗi → kiểm hiệu quả THẬT bằng đọc lại DB; dữ liệu sống đổi giữa 2 truy vấn → đếm
  trước+sau).
- **HACK** (`tests/hack/`, chạy `php tests/hack/run.php <...|upload|all>`): red-team chủ động — auth
  bypass, IDOR, giả mạo tham số, SQLi, CSRF, path traversal, XSS, upload độc. `[THỦNG!]`=lỗ hổng
  thật. Vá theo 3 lớp, chạy lại HACK tới 0 thủng, **rồi chạy lại AB TEST** (bản vá bảo mật hay làm
  vỡ phép thử cũ).

Sau khi sửa xong: chạy `HACK all` + `AB TEST all`, kiểm không rác `ZZAB`, `tail php_errors.log`.
(Người dùng cũng gọi 2 bộ này bằng "AB TEST <bảng>" / "HACK <bảng>" — có skill tương ứng.)

## 9. Vấn đề đã biết / đang treo

- **Secret lộ trên GitHub public** (mật khẩu DB + key mã hoá trong `config.php`, `config.php` KHÔNG
  bị `.gitignore` → đang được git theo dõi). Rủi ro số 1 khi online. **Chưa xử lý** (tính tới 05/08/2026).
- `exports.type_id` / `exports.site_id` chưa được kiểm khi xoá Category/Site; đa số tham chiếu
  (`posts`, `store`, `exports`) chưa có FK thật — đề xuất thêm check + `ON DELETE RESTRICT`, chờ quyết.
- Product variants: định dạng `variantdata` color→sizes; bản redesign kiểu Shopify đã đề xuất, hoãn.
