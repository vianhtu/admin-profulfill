/**
 * app-academy-course Script
 */

'use strict';

document.addEventListener('DOMContentLoaded', function (e) {
    // Gọi AJAX tới PHP
    $.ajax({
        url: '../../ajax.php?action=get-product-copyright-warning',
        type: 'POST',
        dataType: 'json',
        success: function (response) {
            console.log('Tổng số:', response.total);
            console.log('Danh sách items:', response.items);

            // Ví dụ render ra HTML
            const container = $('.card-body #cardBody');
            container.empty();
            response.items.forEach(function (item) {
                let warningArr = item.copyrighted_content.split(',').map(s => s.trim());
                let copyrighted_content = [];
                warningArr.forEach((val, idx) => {
                    copyrighted_content.push(`<span class="badge bg-label-warning">${val}</span>`);
                });
                container.append(`
                <div class="col-sm-6 col-lg-4">
                    <div class="card p-2 h-100 shadow-none border">
                        <div class="rounded-2 text-center mb-4">
                            <a href=""><img class="img-fluid" src="${item.img}" alt="${item.title}"/></a>
                        </div>
                        <div class="card-body p-4 pt-2">
                            <div class="d-flex justify-content-between align-items-center mb-4">${copyrighted_content.join('')}</div>
                            <h5 >${item.copyright_warning}</h5>
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
        },
        error: function (xhr, status, error) {
            console.error('Lỗi AJAX:', error);
        }
    });
});
