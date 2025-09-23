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
            const container = $('#product-list');
            container.empty();
            response.items.forEach(function (item) {
                container.append(`
                    <div class="product">
                        <h3>${item.title}</h3>
                        <img src="${item.name}" alt="${item.title}" />
                        <p>SKU: ${item.sku}</p>
                        <p>Copyright Warning: ${item.copyright_warning}</p>
                    </div>
                `);
            });
        },
        error: function (xhr, status, error) {
            console.error('Lỗi AJAX:', error);
        }
    });
});
