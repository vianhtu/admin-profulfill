<?php
// dashboards.php$
declare(strict_types=1);
require __DIR__ . '/../../config.php';
require __DIR__ . '/../../functions.php';
require_once __DIR__ . '/../../class/class.products.php';
require_once __DIR__ . '/../../class/class.product.php';
require_once __DIR__ . '/../../class/class.categories.php';
require_once __DIR__ . '/../../class/class.category.php';
require_once __DIR__ . '/../../class/class.sites.php';
require_once __DIR__ . '/../../class/class.site.php';
require_once __DIR__ . '/../../class/class.stores.php';
require_once __DIR__ . '/../../class/class.store.php';
// Fragment app-user-list.php dùng Users::can_add() / can_see_salary() để gate lớp UI
require_once __DIR__ . '/../../class/class.users.php';
require_once __DIR__ . '/../../class/class.account.php';
// Fragment app-access-permission.php dùng Roles::can_manage() / Role::valid_menu_actions()
require_once __DIR__ . '/../../class/class.roles.php';
require_once __DIR__ . '/../../class/class.role.php';
require_login();
$user = $_SESSION['auth']['user'] ?? 'user';
$currentMenu = $_GET['menu'] ?? '';
if (empty($_SESSION['csrf_token'])) {
    // random_bytes(32) tạo chuỗi ngẫu nhiên bảo mật
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!doctype html>

<html
  lang="en"
  class="layout-navbar-fixed layout-menu-fixed layout-compact"
  dir="ltr"
  data-skin="default"
  data-bs-theme="light"
  data-assets-path="../../assets/"
  data-template="vertical-menu-template-no-customizer-starter">
  <head>
    <meta charset="utf-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
    <meta name="robots" content="noindex, nofollow" />
    <title>Demo: Page 1 - Starter Kit | Vuexy - Bootstrap Dashboard PRO</title>

    <meta name="description" content="" />

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="../../assets/img/favicon/favicon.ico" />

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&ampdisplay=swap"
      rel="stylesheet" />

    <link rel="stylesheet" href="../../assets/vendor/fonts/iconify-icons.css" />
    <link rel="stylesheet" href="../../assets/vendor/fonts/fontawesome.css" />
    <!-- Core CSS -->
    <!-- build:css assets/vendor/css/theme.css  -->

    <link rel="stylesheet" href="../../assets/vendor/libs/node-waves/node-waves.css" />
    <link rel="stylesheet" href="../../assets/vendor/libs/pickr/pickr-themes.css" />
    <link rel="stylesheet" href="../../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../../assets/css/demo.css" />

    <!-- Vendors CSS -->

    <link rel="stylesheet" href="../../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />

    <!-- endbuild -->
    <link rel="stylesheet" href="../../assets/vendor/libs/datatables-bs5/datatables.bootstrap5.css" />
    <link rel="stylesheet" href="../../assets/vendor/libs/datatables-responsive-bs5/responsive.bootstrap5.css" />
    <link rel="stylesheet" href="../../assets/vendor/libs/datatables-buttons-bs5/buttons.bootstrap5.css" />
    <link rel="stylesheet" href="../../assets/vendor/libs/select2/select2.css" />
    <link rel="stylesheet" href="../../assets/vendor/libs/@form-validation/form-validation.css" />
    <link rel="stylesheet" href="../../assets/vendor/libs/quill/typography.css" />
    <link rel="stylesheet" href="../../assets/vendor/libs/quill/katex.css" />
    <link rel="stylesheet" href="../../assets/vendor/libs/quill/editor.css" />
    <link rel="stylesheet" href="../../assets/vendor/libs/dropzone/dropzone.css" />
    <link rel="stylesheet" href="../../assets/vendor/libs/flatpickr/flatpickr.css" />
    <link rel="stylesheet" href="../../assets/vendor/libs/tagify/tagify.css" />
    <link rel="stylesheet" href="../../assets/vendor/libs/swiper/swiper.css" />

    <!-- Page CSS -->

    <!-- Helpers -->
    <script src="../../assets/vendor/js/helpers.js"></script>
    <!--! Template customizer & Theme config files MUST be included after core stylesheets and helpers.js in the <head> section -->
    <script src="../../assets/vendor/js/template-customizer.js"></script>

    <!--? Config:  Mandatory theme config file contain global vars & default theme options, Set your preferred theme option in this file.  -->

    <script src="../../assets/js/config.js?v=<?= filemtime(ROOT_DIR . '/assets/js/config.js') ?>"></script>
    <script>
      window.csrfToken = "<?= $_SESSION['csrf_token'] ?>";
    </script>
      <!-- Các link CSS khác -->
  <style>
      /* Có thể thêm nếu vẫn bị tràn 1-2px do rounding */
      html, body {
          overflow-x: hidden;
      }

      /* Modal Add/Edit và Delete ở KHỔ HẸP: đệm mặc định của template ngốn gần hết màn hình
         nên ma trận quyền và danh sách chỉ còn vài dòng. Thu gọn để hiện được nhiều dữ liệu.
         Modal "Details of ..." của DataTables Responsive GIỮ NGUYÊN, không đụng tới. */
      @media (max-width: 767.98px) {
          .modal:not(.dtr-bs-modal) .modal-dialog { margin: .5rem; max-width: calc(100% - 1rem); }
          .modal:not(.dtr-bs-modal) .modal-body { padding: 1rem; }
          .modal:not(.dtr-bs-modal) .modal-header { padding: .75rem 1rem; }
          .modal:not(.dtr-bs-modal) .modal-footer { padding: .5rem 1rem; }
          /* Form Role đã dùng modal-header chuẩn, không còn khối tiêu đề giữa thân */
          /* Ma trận quyền: cao hơn + ô gọn hơn để thấy được nhiều menu cùng lúc */
          #addPermissionForm .table-responsive { max-height: 55vh !important; }
          #addPermissionForm table.table { font-size: .85rem; }
          #addPermissionForm table.table > :not(caption) > * > * { padding: .3rem .35rem; }
      }

  </style>
  </head>

  <body class="dark-layout">
    <!-- Layout wrapper -->
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        <!-- Menu -->

        <aside id="layout-menu" class="layout-menu menu-vertical menu">
          <div class="app-brand demo">
            <a href="index.html" class="app-brand-link">
              <span class="app-brand-logo demo">
                <span class="text-primary">
                  <svg width="32" height="22" viewBox="0 0 32 22" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path
                      fill-rule="evenodd"
                      clip-rule="evenodd"
                      d="M0.00172773 0V6.85398C0.00172773 6.85398 -0.133178 9.01207 1.98092 10.8388L13.6912 21.9964L19.7809 21.9181L18.8042 9.88248L16.4951 7.17289L9.23799 0H0.00172773Z"
                      fill="currentColor" />
                    <path
                      opacity="0.06"
                      fill-rule="evenodd"
                      clip-rule="evenodd"
                      d="M7.69824 16.4364L12.5199 3.23696L16.5541 7.25596L7.69824 16.4364Z"
                      fill="#161616" />
                    <path
                      opacity="0.06"
                      fill-rule="evenodd"
                      clip-rule="evenodd"
                      d="M8.07751 15.9175L13.9419 4.63989L16.5849 7.28475L8.07751 15.9175Z"
                      fill="#161616" />
                    <path
                      fill-rule="evenodd"
                      clip-rule="evenodd"
                      d="M7.77295 16.3566L23.6563 0H32V6.88383C32 6.88383 31.8262 9.17836 30.6591 10.4057L19.7824 22H13.6938L7.77295 16.3566Z"
                      fill="currentColor" />
                  </svg>
                </span>
              </span>
              <span class="app-brand-text demo menu-text fw-bold ms-3">profulfill.io</span>
            </a>

            <a href="javascript:void(0);" class="layout-menu-toggle menu-link text-large ms-auto">
              <i class="icon-base ti menu-toggle-icon d-none d-xl-block"></i>
              <i class="icon-base ti tabler-x d-block d-xl-none"></i>
            </a>
          </div>

          <div class="menu-inner-shadow"></div>

            <ul class="menu-inner py-1">
                <?php renderMenu($currentMenu); ?>
          </ul>
        </aside>

        <div class="menu-mobile-toggler d-xl-none rounded-1">
          <a href="javascript:void(0);" class="layout-menu-toggle menu-link text-large text-bg-secondary p-2 rounded-1">
            <i class="ti tabler-menu icon-base"></i>
            <i class="ti tabler-chevron-right icon-base"></i>
          </a>
        </div>
        <!-- / Menu -->

        <!-- Layout container -->
        <div class="layout-page">
          <!-- Navbar -->

          <nav
            class="layout-navbar container-xxl navbar-detached navbar navbar-expand-xl align-items-center bg-navbar-theme"
            id="layout-navbar">
            <div class="layout-menu-toggle navbar-nav align-items-xl-center me-3 me-xl-0 d-xl-none">
              <a class="nav-item nav-link px-0 me-xl-6" href="javascript:void(0)">
                <i class="icon-base ti tabler-menu-2 icon-md"></i>
              </a>
            </div>

            <div class="navbar-nav-right d-flex align-items-center justify-content-end" id="navbar-collapse">
              <ul class="navbar-nav flex-row align-items-center ms-md-auto">
                <!-- User -->
                <li class="nav-item navbar-dropdown dropdown-user dropdown">
                  <a
                    class="nav-link dropdown-toggle hide-arrow p-0"
                    href="javascript:void(0);"
                    data-bs-toggle="dropdown">
                    <div class="avatar avatar-online">
                      <img src="../../assets/img/avatars/1.png" alt class="rounded-circle" />
                    </div>
                  </a>
                  <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                      <a class="dropdown-item" href="#">
                        <div class="d-flex">
                          <div class="flex-shrink-0 me-3">
                            <div class="avatar avatar-online">
                              <img src="../../assets/img/avatars/1.png" alt class="w-px-40 h-auto rounded-circle" />
                            </div>
                          </div>
                          <div class="flex-grow-1">
                            <h6 class="mb-0"><?= h($user) ?></h6>
                            <small class="text-body-secondary">Admin</small>
                          </div>
                        </div>
                      </a>
                    </li>
                    <li>
                      <div class="dropdown-divider my-1 mx-n2"></div>
                    </li>
                    <li>
                      <!-- Đường vào trang tài khoản: KHÔNG gắn với menu Users nên người
                           không được cấp menu đó vẫn tự đổi được mật khẩu -->
                      <a class="dropdown-item" href="index.php?menu=account">
                        <i class="icon-base ti tabler-user icon-md me-3"></i><span>My Profile</span>
                      </a>
                    </li>
                    <li>
                      <a class="dropdown-item" href="index.php?menu=account#tab-security">
                        <i class="icon-base ti tabler-settings icon-md me-3"></i><span>Settings</span>
                      </a>
                    </li>
                    <li>
                      <a class="dropdown-item" href="#">
                        <span class="d-flex align-items-center align-middle">
                          <i class="flex-shrink-0 icon-base ti tabler-credit-card icon-md me-3"></i
                          ><span class="flex-grow-1 align-middle">Billing Plan</span>
                          <span class="flex-shrink-0 badge rounded-pill bg-danger">4</span>
                        </span>
                      </a>
                    </li>
                    <li>
                      <div class="dropdown-divider my-1 mx-n2"></div>
                    </li>
                    <li>
                      <a class="dropdown-item" href="../../auth.php?action=logout">
                        <i class="icon-base ti tabler-power icon-md me-3"></i><span>Log Out</span>
                      </a>
                    </li>
                  </ul>
                </li>
                <!--/ User -->
              </ul>
            </div>
          </nav>

          <!-- / Navbar -->

          <!-- Content wrapper -->
          <div class="content-wrapper">
            <!-- Content -->
            <div class="container-xxl flex-grow-1 container-p-y">
              <?php
              switch ($currentMenu) {
                  case 'products':
                      if (isset($_GET['form']) && ($_GET['form'] === 'add' || $_GET['form'] === 'edit')) {
                          include 'app-ecommerce-product-add.php';
                      } else {
                          include 'app-ecommerce-product-list.php';
                      }
                      break;
                  case 'categories':
                      if (isset($_GET['form']) && ($_GET['form'] === 'add' || $_GET['form'] === 'edit')) {
                          include 'app-ecommerce-category-add.php';
                      } else {
                          include 'app-ecommerce-category-list.php';
                      }
                      break;
                  case 'sites':
                      if (isset($_GET['form']) && ($_GET['form'] === 'add' || $_GET['form'] === 'edit')) {
                          include 'app-ecommerce-site-add.php';
                      } else {
                          include 'app-ecommerce-site-list.php';
                      }
                      break;
                  case 'store':
                      if (isset($_GET['form']) && ($_GET['form'] === 'add' || $_GET['form'] === 'edit')) {
                          include 'app-ecommerce-store-add.php';
                      } else {
                          include 'app-ecommerce-store-list.php';
                      }
                      break;
                  case 'copyright':
                      include 'app-ecommerce-product-copyright.php';
                      break;
                  case 'keywords':
                      include 'app-ecommerce-keyword-list.php';
                      break;
                  case 'orders':
                      include 'app-ecommerce-order-list.php';
                      break;
                  case 'stores':
                      if( isset( $_GET['form'] ) && ($_GET['form'] == 'add' || $_GET['form'] == 'edit')) {
                          include 'app-store-add.php';
                      } else {
                          include 'app-store-list.php';
                      }
                      break;
                  case 'exports_download':
                      include 'app-xlsx-download-list.php';
                      break;
                  case 'exports_xlsx':
                      if( isset( $_GET['form'] ) && $_GET['form'] == 'add') {
                          include 'app-xlsx-add.php';
                      } else {
                          include 'app-xlsx-list.php';
                      }
                      break;
                  case 'phones_numbers':
                      include 'app-phones-list.php';
                      break;
                  case 'phones_sms':
                      include 'app-phones-sms.php';
                      break;
                  case 'teams':
                      include 'app-teams-list.php';
                      break;
                  case 'users':
                      include 'app-user-list.php';
                      break;
                  // Trang tài khoản: KHÔNG gác checkRoles ở đây — xem hồ sơ của chính mình
                  // không phải là quản lý người dùng, và đây là đường duy nhất để người
                  // không được cấp menu Users tự đổi mật khẩu. Fragment tự kiểm khi ?id=N
                  // trỏ sang người khác.
                  case 'account':
                      include 'app-account.php';
                      break;
                  case 'roles-permissions':
                      include 'app-access-permission.php';
                      break;
              }
              ?>
            </div>
            <!-- / Content -->

            <!-- Footer -->
            <footer class="content-footer footer bg-footer-theme">
              <div class="container-xxl">
                <div
                  class="footer-container d-flex align-items-center justify-content-between py-4 flex-md-row flex-column">
                  <div class="text-body">
                    &#169;
                    <script>
                      document.write(new Date().getFullYear());
                    </script>
                    , made with ❤️ by <a href="https://pixinvent.com" target="_blank" class="footer-link">Pixinvent</a>
                  </div>
                  <div class="d-none d-lg-inline-block">
                    <a
                      href="https://demos.pixinvent.com/vuexy-html-admin-template/documentation/"
                      target="_blank"
                      class="footer-link me-4"
                      >Documentation</a
                    >
                  </div>
                </div>
              </div>
            </footer>
            <!-- / Footer -->

            <div class="content-backdrop fade"></div>
          </div>
          <!-- Content wrapper -->
        </div>
        <!-- / Layout page -->
      </div>

      <!-- Overlay -->
      <div class="layout-overlay layout-menu-toggle"></div>

      <!-- Drag Target Area To SlideIn Menu On Small Screens -->
      <div class="drag-target"></div>
    </div>
    <!-- / Layout wrapper -->

    <!-- Core JS -->
    <!-- build:js assets/vendor/js/theme.js  -->

    <script src="../../assets/vendor/libs/jquery/jquery.js"></script>

    <script src="../../assets/vendor/libs/popper/popper.js"></script>
    <script src="../../assets/vendor/js/bootstrap.js"></script>
    <script src="../../assets/vendor/libs/node-waves/node-waves.js"></script>
    <script src="../../assets/vendor/libs/pickr/pickr.js"></script>
    <script src="../../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>

    <script src="../../assets/vendor/libs/hammer/hammer.js"></script>

    <script src="../../assets/vendor/libs/i18n/i18n.js"></script>

    <script src="../../assets/vendor/js/menu.js"></script>

    <!-- endbuild -->
    <script src="../../assets/js/functions.js?v=<?= filemtime(ROOT_DIR . '/assets/js/functions.js') ?>"></script>
    <!-- Vendors JS -->
    <script src="../../assets/vendor/libs/moment/moment.js"></script>
    <script src="../../assets/vendor/libs/datatables-bs5/datatables-bootstrap5.js"></script>
    <script src="../../assets/vendor/libs/select2/select2.js"></script>
    <script src="../../assets/vendor/libs/@form-validation/popular.js"></script>
    <script src="../../assets/vendor/libs/@form-validation/bootstrap5.js"></script>
    <script src="../../assets/vendor/libs/@form-validation/auto-focus.js"></script>
    <script src="../../assets/vendor/libs/cleave-zen/cleave-zen.js"></script>
    <script src="../../assets/vendor/libs/quill/katex.js"></script>
    <script src="../../assets/vendor/libs/quill/quill.js"></script>
    <script src="../../assets/vendor/libs/dropzone/dropzone.js"></script>
    <script src="../../assets/vendor/libs/jquery-repeater/jquery-repeater.js"></script>
    <script src="../../assets/vendor/libs/flatpickr/flatpickr.js"></script>
    <script src="../../assets/vendor/libs/tagify/tagify.js"></script>
    <script src="../../assets/vendor/libs/swiper/swiper.js"></script>
    <!-- Helper chung: đồng bộ trạng thái bảng (filter/search/sort/trang/số dòng) với URL.
         Phải nạp TRƯỚC page-JS vì các trang gọi dtUrlState() lúc dựng DataTable. -->
    <script src="../../assets/js/dt-url-state.js?v=<?= filemtime(ROOT_DIR . '/assets/js/dt-url-state.js') ?>"></script>
    <?php
    switch ($currentMenu) {
        case 'products':
            if (isset($_GET['form']) && ($_GET['form'] === 'add' || $_GET['form'] === 'edit')) { ?>
                <script src="../../assets/js/app-ecommerce-product-add.js?v=<?= filemtime(ROOT_DIR . '/assets/js/app-ecommerce-product-add.js') ?>"></script>
            <?php } else { ?>
                <script src="../../assets/js/app-ecommerce-product-list.js?v=<?= filemtime(ROOT_DIR . '/assets/js/app-ecommerce-product-list.js') ?>"></script>
            <?php }
            break;
        case 'categories':
            if (isset($_GET['form']) && ($_GET['form'] === 'add' || $_GET['form'] === 'edit')) { ?>
                <script src="../../assets/js/app-ecommerce-category-add.js?v=<?= filemtime(ROOT_DIR . '/assets/js/app-ecommerce-category-add.js') ?>"></script>
            <?php } else { ?>
                <script src="../../assets/js/app-ecommerce-category-list.js?v=<?= filemtime(ROOT_DIR . '/assets/js/app-ecommerce-category-list.js') ?>"></script>
            <?php }
            break;
        case 'sites':
            if (isset($_GET['form']) && ($_GET['form'] === 'add' || $_GET['form'] === 'edit')) { ?>
                <script src="../../assets/js/app-ecommerce-site-add.js?v=<?= filemtime(ROOT_DIR . '/assets/js/app-ecommerce-site-add.js') ?>"></script>
            <?php } else { ?>
                <script src="../../assets/js/app-ecommerce-site-list.js?v=<?= filemtime(ROOT_DIR . '/assets/js/app-ecommerce-site-list.js') ?>"></script>
            <?php }
            break;
        case 'store':
            if (isset($_GET['form']) && ($_GET['form'] === 'add' || $_GET['form'] === 'edit')) { ?>
                <script src="../../assets/js/app-ecommerce-store-add.js?v=<?= filemtime(ROOT_DIR . '/assets/js/app-ecommerce-store-add.js') ?>"></script>
            <?php } else { ?>
                <script src="../../assets/js/app-ecommerce-store-list.js?v=<?= filemtime(ROOT_DIR . '/assets/js/app-ecommerce-store-list.js') ?>"></script>
            <?php }
            break;
        case 'copyright': ?>
            <script src="../../assets/js/app-ecommerce-product-copyright.js?v=<?= filemtime(ROOT_DIR . '/assets/js/app-ecommerce-product-copyright.js') ?>"></script>
        <?php break;
        case 'keywords': ?>
            <script src="../../assets/js/app-ecommerce-keywords-list.js?v=<?= filemtime(ROOT_DIR . '/assets/js/app-ecommerce-keywords-list.js') ?>"></script>
            <?php break;
        case 'orders': ?>
            <script src="../../assets/js/app-ecommerce-order-list.js?v=<?= filemtime(ROOT_DIR . '/assets/js/app-ecommerce-order-list.js') ?>"></script>
            <?php break;
        case 'stores': if( isset( $_GET['form'] ) && ($_GET['form'] == 'add' || $_GET['form'] == 'edit')){ ?>
                <script src="../../assets/js/app-store-add.js?v=<?= filemtime(ROOT_DIR . '/assets/js/app-store-add.js') ?>"></script>
            <?php } else { ?>
                <script src="../../assets/js/app-store-list.js?v=<?= filemtime(ROOT_DIR . '/assets/js/app-store-list.js') ?>"></script>
            <?php } ?>
            <?php break;
        case 'exports_download': ?>
            <script src="../../assets/js/app-xlsx-download-list.js?v=<?= filemtime(ROOT_DIR . '/assets/js/app-xlsx-download-list.js') ?>"></script>
            <?php break;
        case 'exports_xlsx':
            if( isset( $_GET['form'] ) && $_GET['form'] == 'add') { ?>
                <script src="../../assets/js/app-xlsx-add.js?v=<?= filemtime(ROOT_DIR . '/assets/js/app-xlsx-add.js') ?>"></script>
            <?php } else { ?>
                <script src="../../assets/js/app-xlsx-list.js?v=<?= filemtime(ROOT_DIR . '/assets/js/app-xlsx-list.js') ?>"></script>
            <?php } ?>
            <?php break;
        case 'phones_numbers': ?>
            <script src="../../assets/js/app-phones-list.js?v=<?= filemtime(ROOT_DIR . '/assets/js/app-phones-list.js') ?>"></script>
        <?php break;
        case 'teams': ?>
            <script src="../../assets/js/app-teams-list.js?v=<?= filemtime(ROOT_DIR . '/assets/js/app-teams-list.js') ?>"></script>
        <?php break;
        case 'users': ?>
            <script src="../../assets/js/app-user-list.js?v=<?= filemtime(ROOT_DIR . '/assets/js/app-user-list.js') ?>"></script>
        <?php break;
        case 'account': ?>
            <script src="../../assets/js/app-account.js?v=<?= filemtime(ROOT_DIR . '/assets/js/app-account.js') ?>"></script>
        <?php break;
        case 'roles-permissions': ?>
            <!-- modal-add-permission.js + modal-edit-permission.js đã gộp vào file dưới -->
            <script src="../../assets/js/app-access-permission.js?v=<?= filemtime(ROOT_DIR . '/assets/js/app-access-permission.js') ?>"></script>
            <?php break;
    }
    ?>
    <script>
      /**
       * Mở modal làm thanh navbar xê dịch 7px — KHÔNG phải lỗi thanh cuộn.
       * Vuexy có rule:
       *   .layout-navbar-fixed .modal-open .layout-navbar.navbar-detached {
       *       inline-size: calc(100% - 1.5rem*2 - calc(16.25rem + var(--bs-scrollbar-width))) }
       * tức là nó CỐ Ý thu hẹp navbar đúng bằng --bs-scrollbar-width để bù phần thanh cuộn
       * mà Bootstrap lấy đi khi khoá cuộn body. Nhưng ở layout này Bootstrap KHÔNG lấy
       * (body padding-right vẫn 0), nên phần bù thành thừa -> navbar hụt 15px -> canh giữa
       * lại -> lệch 7.5px mỗi bên.
       *
       * Vá đúng gốc: đồng bộ --bs-scrollbar-width với phần Bootstrap THỰC SỰ bù vào body.
       * Trang nào Bootstrap có bù thật thì giá trị vẫn đúng như cũ, nên không phá trang khác.
       * Dùng capture để chạy trước lúc trình duyệt vẽ lại.
       */
      (function () {
        var sync = function () {
          var pr = parseFloat(getComputedStyle(document.body).paddingRight) || 0;
          document.body.style.setProperty('--bs-scrollbar-width', pr + 'px');
        };
        document.addEventListener('show.bs.modal', sync, true);
        document.addEventListener('shown.bs.modal', sync, true);
        document.addEventListener('hidden.bs.modal', function () {
          document.body.style.removeProperty('--bs-scrollbar-width');
        }, true);
      })();
    </script>

    <script>
      /**
       * Ô nhập bị đánh dấu lỗi (.is-invalid) phải TỰ GỠ ngay khi người dùng nhập lại.
       * Trước đây mỗi trang chỉ gỡ lúc MỞ form, nên sửa xong ô vẫn đỏ — người dùng tưởng
       * còn sai. UI TEST bắt được ở cả Roles lẫn Teams nên vá một chỗ ở layout cho MỌI form.
       * Dùng capture + delegated để bắt được cả ô sinh ra sau khi trang đã dựng.
       */
      document.addEventListener('input', function (e) {
        var el = e.target;
        if (el && el.classList && el.classList.contains('is-invalid')
            && String(el.value || '').trim() !== '') {
          el.classList.remove('is-invalid');
        }
      }, true);
    </script>

    <script>
      /**
       * MOBILE — bấm nút Actions BÊN TRONG modal "Details" của DataTables Responsive.
       *
       * Dưới 768px, Responsive giấu cột Actions và đẩy nó vào modal Details (.dtr-bs-modal).
       * Bấm Edit/Delete ở đó là mở modal THỨ HAI chồng lên modal thứ nhất — Bootstrap không
       * xếp chồng modal, nên form mới bị backdrop nuốt: nút bấm được mà không thấy gì hiện
       * ra, trông y như nút hỏng. Đúng lỗi đã gặp ở menu Roles.
       *
       * Cách chữa dùng chung cho MỌI bảng: chặn cú bấm, đóng modal Details trước, đợi nó
       * đóng hẳn rồi mới phát lại cú bấm đó. Không trang nào phải tự lo lấy.
       */
      document.addEventListener('click', function (e) {
        var dtr = e.target.closest ? e.target.closest('.dtr-bs-modal') : null;
        if (!dtr) {
          return;
        }
        var btn = e.target.closest('button, a');
        // Nút đóng của chính modal Details thì để yên cho Bootstrap xử lý
        if (!btn || btn.hasAttribute('data-bs-dismiss') || btn.classList.contains('btn-close')) {
          return;
        }
        if (btn.dataset.dtrRelayed === '1') {
          delete btn.dataset.dtrRelayed;   // đây là cú bấm phát lại -> cho đi tiếp
          return;
        }
        e.preventDefault();
        e.stopPropagation();
        var inst = bootstrap.Modal.getInstance(dtr);
        if (!inst) {
          return;
        }
        dtr.addEventListener('hidden.bs.modal', function () {
          btn.dataset.dtrRelayed = '1';
          btn.click();
        }, { once: true });
        inst.hide();
      }, true);
    </script>

    <!-- Main JS -->

    <script src="../../assets/js/main.js?v=<?= filemtime(ROOT_DIR . '/assets/js/main.js') ?>"></script>

    <!-- Page JS -->
  </body>
</html>
