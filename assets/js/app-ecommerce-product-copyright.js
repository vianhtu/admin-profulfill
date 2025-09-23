'use strict';
document.addEventListener('DOMContentLoaded', function () {
    let currentPage = 1;
    let limit = 6;

    function loadData(page = 1) {
        $.ajax({
            url: '../../ajax.php?action=get-product-copyright-warning',
            type: 'POST',
            dataType: 'json',
            data: { page: page, limit: limit },
            success: function (response) {
                const container = $('#cardBody');
                container.empty();

                let total = response.total ?? 0;
                $('#totalWarning').text(`Total ${total} products copyright warning`);

                response.items.forEach(function (item) {
                    let warningArr = item.copyrighted_content
                        .split(',')
                        .map(s => s.trim());
                    let badges = warningArr
                        .map(val =>
                            `<span class="badge bg-label-warning">${val}</span>`
                        )
                        .join('');

                    container.append(`
            <div class="col-sm-6 col-lg-4">
              <div class="card p-2 h-100 shadow-none border">
                <div class="rounded-2 text-center mb-4">
                  <a href="">
                    <img class="img-fluid" src="${item.img}" alt="${item.title}"/>
                  </a>
                </div>
                <div class="card-body p-4 pt-2">
                  <div class="d-flex justify-content-between align-items-center mb-4">
                    ${badges}
                  </div>
                  <h5>${item.copyright_warning}</h5>
                  <p class="mt-1">${item.title}</p>
                  <div class="d-flex flex-column flex-md-row gap-4 text-nowrap
                              flex-wrap flex-md-nowrap flex-lg-wrap flex-xxl-nowrap">
                    <button class="w-100 btn btn-label-danger d-flex align-items-center item-reject">
                      <i class="icon-base ti tabler-rotate-clockwise-2 icon-xs
                                align-middle scaleX-n1-rtl me-2"></i>
                      <span>Reject</span>
                    </button>
                    <button class="w-100 btn btn-label-primary d-flex align-items-center item-approve">
                      <span class="me-2">Approve</span>
                      <i class="icon-base ti tabler-chevron-right icon-xs
                                lh-1 scaleX-n1-rtl"></i>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          `);
                });

                // now redraw pagination
                renderPagination(response.total, response.page, response.limit);
            },
            error: function (xhr, status, error) {
                console.error('Lỗi AJAX:', error);
            }
        });
    }

    function renderPagination(total, page, limit) {
        const totalPages = Math.ceil(total / limit) || 1;
        const maxVisible = 5;    // số trang hiển thị tối đa cùng lúc
        let startPage = 1;
        let endPage = totalPages;

        if (totalPages > maxVisible) {
            const half = Math.floor(maxVisible / 2);
            if (page <= half) {
                startPage = 1;
                endPage = maxVisible;
            } else if (page + half >= totalPages) {
                startPage = totalPages - maxVisible + 1;
                endPage = totalPages;
            } else {
                startPage = page - half;
                endPage = page + half;
            }
        }

        const $ul = $('.pagination');
        $ul.empty();

        // First
        $ul.append(`
    <li class="page-item first ${page === 1 ? 'disabled' : ''}">
      <a class="page-link" href="javascript:void(0);" data-page="1">
        <i class="ti ti-chevrons-left icon-sm scaleX-n1-rtl"></i>
      </a>
    </li>
  `);

        // Prev
        const prevPage = page > 1 ? page - 1 : 1;
        $ul.append(`
    <li class="page-item prev ${page === 1 ? 'disabled' : ''}">
      <a class="page-link" href="javascript:void(0);" data-page="${prevPage}">
        <i class="ti ti-chevron-left icon-sm scaleX-n1-rtl"></i>
      </a>
    </li>
  `);

        // … nếu có khoảng trống trước cửa sổ
        if (startPage > 1) {
            $ul.append(`
      <li class="page-item disabled">
        <span class="page-link">…</span>
      </li>
    `);
        }

        // Các trang trong cửa sổ
        for (let i = startPage; i <= endPage; i++) {
            $ul.append(`
      <li class="page-item ${i === page ? 'active' : ''}">
        <a class="page-link" href="javascript:void(0);" data-page="${i}">${i}</a>
      </li>
    `);
        }

        // … nếu có khoảng trống sau cửa sổ
        if (endPage < totalPages) {
            $ul.append(`
      <li class="page-item disabled">
        <span class="page-link">…</span>
      </li>
    `);
        }

        // Next
        const nextPage = page < totalPages ? page + 1 : totalPages;
        $ul.append(`
    <li class="page-item next ${page === totalPages ? 'disabled' : ''}">
      <a class="page-link" href="javascript:void(0);" data-page="${nextPage}">
        <i class="ti ti-chevron-right icon-xs scaleX-n1-rtl"></i>
      </a>
    </li>
  `);

        // Last
        $ul.append(`
    <li class="page-item last ${page === totalPages ? 'disabled' : ''}">
      <a class="page-link" href="javascript:void(0);" data-page="${totalPages}">
        <i class="ti ti-chevrons-right icon-sm scaleX-n1-rtl"></i>
      </a>
    </li>
  `);

        // Bắt sự kiện click
        $ul.find('.page-link[data-page]').off('click').on('click', function () {
            const p = parseInt($(this).data('page'), 10);
            if (!isNaN(p) && p !== currentPage) {
                currentPage = p;
                loadData(p);
            }
        });
    }

    // initial load
    loadData(currentPage);
});