/**
 * app-academy-course Script
 */

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
                    let warningArr = item.copyrighted_content.split(',').map(s => s.trim());
                    let copyrighted_content = warningArr.map(val =>
                        `<span class="badge bg-label-warning">${val}</span>`
                    );
                    container.append(`
                        <div class="col-sm-6 col-lg-4">
                            <div class="card p-2 h-100 shadow-none border">
                                <div class="rounded-2 text-center mb-4">
                                    <a href=""><img class="img-fluid" src="${item.img}" alt="${item.title}"/></a>
                                </div>
                                <div class="card-body p-4 pt-2">
                                    <div class="d-flex justify-content-between align-items-center mb-4">${copyrighted_content.join('')}</div>
                                    <h5>${item.copyright_warning}</h5>
                                    <p class="mt-1">${item.title}</p>
                                    <div class="d-flex flex-column flex-md-row gap-4 text-nowrap flex-wrap flex-md-nowrap flex-lg-wrap flex-xxl-nowrap">
                                        <button class="w-100 btn btn-label-danger d-flex align-items-center item-reject">
                                            <i class="icon-base ti tabler-rotate-clockwise-2 icon-xs align-middle scaleX-n1-rtl me-2"></i>
                                            <span>Reject</span>
                                        </button>
                                        <button class="w-100 btn btn-label-primary d-flex align-items-center item-approve">
                                            <span class="me-2">Approve</span>
                                            <i class="icon-base ti tabler-chevron-right icon-xs lh-1 scaleX-n1-rtl"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `);
                });

                // Render lại pagination
                renderPagination(response.total, response.page, response.limit);
            },
            error: function (xhr, status, error) {
                console.error('Lỗi AJAX:', error);
            }
        });
    }

    function renderPagination(currentPage, totalPages) {
        const $pagination = $('.pagination');
        $pagination.empty();

        // First
        $pagination.append(`
        <li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
          <a class="page-link" href="javascript:void(0);" data-page="first">First</a>
        </li>
      `);

        // Prev
        $pagination.append(`
        <li class="page-item ${currentPage === 1 ? 'disabled' : ''}">
          <a class="page-link" href="javascript:void(0);" data-page="prev">Prev</a>
        </li>
      `);

        // Current page indicator (optional)
        $pagination.append(`
        <li class="page-item active">
          <span class="page-link">${currentPage}</span>
        </li>
      `);

        // Next
        $pagination.append(`
        <li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
          <a class="page-link" href="javascript:void(0);" data-page="next">Next</a>
        </li>
      `);

        // Last
        $pagination.append(`
        <li class="page-item ${currentPage === totalPages ? 'disabled' : ''}">
          <a class="page-link" href="javascript:void(0);" data-page="last">Last</a>
        </li>
      `);
        }

    // Xử lý sự kiện click
    $(document).on('click', '.pagination a[data-page]', function () {
        const action = $(this).data('page');
        let newPage = currentPage;

        if (action === 'first') newPage = 1;
        else if (action === 'prev') newPage = Math.max(1, currentPage - 1);
        else if (action === 'next') newPage = Math.min(totalPages, currentPage + 1);
        else if (action === 'last') newPage = totalPages;

        if (newPage !== currentPage) {
            loadPage(newPage); // Hàm AJAX để tải dữ liệu trang mới
        }
    });

    // Load lần đầu
    loadData(currentPage);
});
