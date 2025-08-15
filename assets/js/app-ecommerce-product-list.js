'use strict';

$(function () {
    var dtProductsTable = $('.datatables-products');

    if (dtProductsTable.length) {
        var dt_product = dtProductsTable.DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '../../ajax.php?action=get-products',
                type: 'POST',
                dataSrc: function (json) {
                    console.log(json);
                    return json.data;
                }
            },
            columns: [
                { data: 'post_id' },         // ID
                { data: 'post_image' },      // Ảnh
                { data: 'post_title' },      // Tiêu đề
                { data: 'category_name' },   // Danh mục
                { data: 'post_status' },     // Trạng thái
                { data: 'stock_status' },    // Tồn kho
                { data: 'post_date' },       // Ngày đăng
                { data: 'actions' }          // Hành động
            ],
            columnDefs: [
                {
                    targets: 1, // Cột ảnh
                    render: function (data) {
                        return `<img src="${data}" alt="Ảnh" class="rounded" 
                     style="width:50px;height:50px;object-fit:cover;">`;
                    }
                },
                {
                    targets: 4, // Cột trạng thái
                    render: function (data) {
                        var badgeClass = data === 'active' ? 'bg-label-success' : 'bg-label-secondary';
                        return `<span class="badge ${badgeClass}">${data}</span>`;
                    }
                },
                {
                    targets: -1, // Cột hành động
                    orderable: false,
                    searchable: false,
                    render: function (data, type, full) {
                        return `
              <button class="btn btn-sm btn-primary edit-record" data-id="${full.post_id}">Sửa</button>
              <button class="btn btn-sm btn-danger delete-record" data-id="${full.post_id}">Xóa</button>
            `;
                    }
                }
            ],
            initComplete: function () {
                // Filter Danh mục
                this.api().columns(3).every(function () {
                    var column = this;
                    var select = $('<select class="form-select form-select-sm"><option value="">Tất cả</option></select>')
                        .appendTo($(column.header()).empty())
                        .on('change', function () {
                            var val = $.fn.dataTable.util.escapeRegex($(this).val());
                            column.search(val ? '^' + val + '$' : '', true, false).draw();
                        });

                    column.data().unique().sort().each(function (d) {
                        if (d) select.append('<option value="' + d + '">' + d + '</option>');
                    });
                });

                // Filter Trạng thái
                this.api().columns(4).every(function () {
                    var column = this;
                    var select = $('<select class="form-select form-select-sm"><option value="">Tất cả</option></select>')
                        .appendTo($(column.header()).empty())
                        .on('change', function () {
                            var val = $.fn.dataTable.util.escapeRegex($(this).val());
                            column.search(val ? '^' + val + '$' : '', true, false).draw();
                        });

                    column.data().unique().sort().each(function (d) {
                        if (d) select.append('<option value="' + d + '">' + d + '</option>');
                    });
                });
            }
        });

        // Sự kiện nút Sửa
        dtProductsTable.on('click', '.edit-record', function () {
            var id = $(this).data('id');
            console.log('Edit record with ID:', id);
            // TODO: mở modal chỉnh sửa
        });

        // Sự kiện nút Xóa
        dtProductsTable.on('click', '.delete-record', function () {
            var id = $(this).data('id');
            if (confirm('Bạn có chắc muốn xóa bản ghi này?')) {
                console.log('Delete record with ID:', id);
                // TODO: gửi AJAX xóa dữ liệu
            }
        });
    }
});