/**
 * app-ecommerce-order-list Script
 */

'use strict';

function showImageModal(src) {
    const modalImg = document.getElementById('modalImage');
    modalImg.src = src;
    const modal = new bootstrap.Modal(document.getElementById('imageModal'));
    modal.show();
}

function getFullSizeImage(url) {
    return url.replace(/_.*\.jpg$/, '.jpg');
}

function replaceAmazonImageSize(url, newSize) {
    // newSize chỉ cần số, ví dụ 200
    return url.replace(/SX\d+/, `SX${newSize}`);
}

// Hàm tạo HTML cho bảng con
function getItemsRow(orderItems, colCount) {
    let html = '';

    orderItems.forEach(item => {
        const row_image = `
          <div class="d-flex justify-content-start align-items-center product-name">
            <div class="avatar-wrapper">
              <div class="avatar avatar me-2 me-sm-4 rounded-2 bg-label-secondary" style="width:80px; height:80px;">
                <img src="${replaceAmazonImageSize(item.Image, 150)}" 
                     title="${item.Title}" 
                     alt="${item.Title}" 
                     class="rounded img-fluid" 
                     style="cursor:pointer;" 
                     onclick="showImageModal('${getFullSizeImage(item.Image)}')">
              </div>
            </div>
            <div class="d-flex flex-column">
              <small>ASIN: <a href="https://www.amazon.com/dp/${item.ASIN}" target="_blank">${item.ASIN}</a></small>
              <small>SKU: ${item.SKU}</small>
              <small>QLT: ${item.Quantity}</small>
              <small>PRICE: ${item.Cost.Amount} ${item.Cost.CurrencyCode}</small>
            </div>
          </div>`;

        let row_custom = '<div class="d-flex flex-column">';
        item.Customizations.forEach(m => {
            m.Modifications.forEach(values => {
                row_custom += `<small>${values.Name}: ${values.Value}</small>`;
            });
        });
        row_custom += '</div>';

        html += `
          <tr class="child-row">
            <td style="display: none;"></td>
            <td></td>
            <td>${row_image}</td>
            <td>${row_custom}</td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
          </tr>`;
    });

    html += '';

    return html;
}
// Datatable (js)

document.addEventListener('DOMContentLoaded', function (e) {
  let borderColor, bodyBg, headingColor;

  borderColor = config.colors.borderColor;
  bodyBg = config.colors.bodyBg;
  headingColor = config.colors.headingColor;

  // Variable declaration for table

  const dt_order_table = document.querySelector('.datatables-order'),
    statusObj = {
      Unshipped: { title: 'Unshipped', class: 'bg-label-warning' },
      2: { title: 'Delivered', class: 'bg-label-success' },
      3: { title: 'Out for Delivery', class: 'bg-label-primary' },
      4: { title: 'Ready to Pickup', class: 'bg-label-info' }
    },
    paymentObj = {
      1: { title: 'Paid', class: 'text-success' },
      2: { title: 'Pending', class: 'text-warning' },
      3: { title: 'Failed', class: 'text-danger' },
      4: { title: 'Cancelled', class: 'text-secondary' }
    };

  // E-commerce Products datatable

  if (dt_order_table) {
    const dt_products = new DataTable(dt_order_table, {
      ajax: {
          url: '../../ajax.php?action=get-orders',
          type: 'POST',
          data: function (d) {},
          dataSrc: function (json) {
              return json.data;
          }
      },
      columns: [
        // columns according to JSON
        { data: 'id' },
        { data: 'id', orderable: false, render: DataTable.render.select() },
        { data: 'host_id' },
        { data: 'purchase_date' },
        { data: 'total_price' },
        { data: 'ship_date' },
        { data: 'delivery_date' },
        { data: 'status' },
        { data: 'full_name' },
        { data: 'id' }
      ],
      columnDefs: [
        {
          // For Responsive
          className: 'control',
          searchable: false,
          orderable: false,
          responsivePriority: 2,
          targets: 0,
          render: function (data, type, full, meta) {
            return '';
          }
        },
        {
          // For Checkboxes
          targets: 1,
          orderable: false,
          searchable: false,
          responsivePriority: 3,
          checkboxes: true,
          render: function () {
            return '<input type="checkbox" class="dt-checkboxes form-check-input">';
          },
          checkboxes: {
            selectAllRender: '<input type="checkbox" class="form-check-input">'
          }
        },
        {
          // Order ID
          targets: 2,
          render: function (data, type, full, meta) {
            const order_id = full['host_id'];
            const items = JSON.parse(full['items']);
            // Creates full output for row
            return '<a href="app-ecommerce-order-details.html">' +
            '<span class="text-nowrap">#' + order_id + '</span>' +
            '</a>';
          }
        },
        {
          targets: 3,
          render: function (data, type, full, meta) {
            const date = new Date(full['purchase_date']);
            const formattedDate = date.toLocaleDateString('en-US', {
              month: 'short',
              day: 'numeric',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
              hour12: false,
              timeZone: 'Asia/Ho_Chi_Minh'
            });
            return `<span class="text-nowrap">${formattedDate}</span>`;
          }
        },
        {
          targets: 4,
          render: function (data, type, full, meta) {
            return '<h6 class="text-nowrap mb-0">$'+full['total_price']+'</h6>';
          }
        },
        {
          targets: 5,
          render: function (data, type, full, meta) {
              const date = new Date(full['ship_date']);
              const formattedDate = date.toLocaleString('en-US', {
                  month: 'short',
                  day: 'numeric',
                  hour: '2-digit',
                  minute: '2-digit',
                  hour12: false,
                  timeZone: 'Asia/Ho_Chi_Minh'
              });
              return `<span class="text-nowrap">${formattedDate}</span>`;
          }
        },
        {
          targets: 6,
          render: function (data, type, full, meta) {
              const date = new Date(full['delivery_date']);
              const formattedDate = date.toLocaleString('en-US', {
                  month: 'short',
                  day: 'numeric',
                  hour: '2-digit',
                  minute: '2-digit',
                  hour12: false,
                  timeZone: 'Asia/Ho_Chi_Minh'
              });
              return `<span class="text-nowrap">${formattedDate}</span>`;
          }
        },
          {
              targets: 7,
              render: function (data, type, full, meta) {
                  const status = full['status'];
                  const statusInfo = statusObj[status];
                  if (statusInfo) {
                      return `
                <span class="badge px-2 ${statusInfo.class} text-capitalized">
                  ${statusInfo.title}
                </span>`;
                  }
                  return data;
              }
          },
          {
              targets: 8,
              responsivePriority: 1,
              render: function (data, type, full, meta) {
                  const street_address_2 = full['street_address_2'] ? '<small class="text-truncate d-none d-sm-block">'+full['street_address_2']+'</small>' : '';
                  return '<div class="d-flex flex-column">' +
                      '<h6 class="text-nowrap mb-0">'+full['full_name']+'</h6>' +
                      '<small class="text-truncate d-none d-sm-block">'+full['street_address_1']+'</small>' +
                      street_address_2 +
                      '<small class="text-truncate d-none d-sm-block">'+full['city']+', '+full['state']+' '+ full['zip_code'] +'</small>' +
                      '<small class="text-truncate d-none d-sm-block">'+full['country']+'</small>' +
                      '</div>';
              }
          },
        {
          targets: -1,
          title: 'Actions',
          searchable: false,
          orderable: false,
          render: function (data, type, full, meta) {
            return `
              <div class="d-flex justify-content-sm-start align-items-sm-center">
                <button class="btn btn-text-secondary rounded-pill waves-effect btn-icon dropdown-toggle hide-arrow" data-bs-toggle="dropdown">
                  <i class="icon-base ti tabler-dots-vertical"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-end m-0">
                  <a href="app-ecommerce-order-details.html" class="dropdown-item">View</a>
                  <a href="javascript:void(0);" class="dropdown-item delete-record">Delete</a>
                </div>
              </div>`;
          }
        }
      ],
      select: {
        style: 'multi',
        selector: 'td:nth-child(2)'
      },
      order: [3, 'desc'],
      layout: {
        topStart: {
          search: {
            placeholder: 'Search Order',
            text: '_INPUT_'
          }
        },
        topEnd: {
          rowClass: 'row mx-3 my-0 justify-content-between',
          features: [
            {
              pageLength: {
                menu: [7, 10, 25, 50, 100],
                text: '_MENU_'
              }
            },
            {
              buttons: [
                {
                  extend: 'collection',
                  className: 'btn btn-label-primary dropdown-toggle',
                  text: '<span class="d-flex align-items-center gap-1"><i class="icon-base ti tabler-upload icon-xs"></i> <span class="d-none d-sm-inline-block">Export</span></span>',
                  buttons: [
                    {
                      extend: 'print',
                      text: `<span class="d-flex align-items-center"><i class="icon-base ti tabler-printer me-1"></i>Print</span>`,
                      className: 'dropdown-item',
                      exportOptions: {
                        columns: [3, 4, 5, 6, 7],
                        format: {
                          body: function (inner, coldex, rowdex) {
                            if (inner.length <= 0) return inner;
                            const el = new DOMParser().parseFromString(inner, 'text/html').body.childNodes;
                            let result = '';
                            el.forEach(item => {
                              if (item.classList && item.classList.contains('user-name')) {
                                result += item.lastChild.firstChild.textContent;
                              } else {
                                result += item.textContent || item.innerText || '';
                              }
                            });
                            return result;
                          }
                        }
                      },
                      customize: function (win) {
                        win.document.body.style.color = headingColor;
                        win.document.body.style.borderColor = borderColor;
                        win.document.body.style.backgroundColor = bodyBg;
                        const table = win.document.body.querySelector('table');
                        table.classList.add('compact');
                        table.style.color = 'inherit';
                        table.style.borderColor = 'inherit';
                        table.style.backgroundColor = 'inherit';
                      }
                    },
                    {
                      extend: 'csv',
                      text: `<span class="d-flex align-items-center"><i class="icon-base ti tabler-file me-1"></i>Csv</span>`,
                      className: 'dropdown-item',
                      exportOptions: {
                        columns: [3, 4, 5, 6, 7],
                        format: {
                          body: function (inner, coldex, rowdex) {
                            if (inner.length <= 0) return inner;
                            const el = new DOMParser().parseFromString(inner, 'text/html').body.childNodes;
                            let result = '';
                            el.forEach(item => {
                              if (item.classList && item.classList.contains('user-name')) {
                                result += item.lastChild.firstChild.textContent;
                              } else {
                                result += item.textContent || item.innerText || '';
                              }
                            });
                            return result;
                          }
                        }
                      }
                    },
                    {
                      extend: 'excel',
                      text: `<span class="d-flex align-items-center"><i class="icon-base ti tabler-upload me-1"></i>Excel</span>`,
                      className: 'dropdown-item',
                      exportOptions: {
                        columns: [3, 4, 5, 6, 7],
                        format: {
                          body: function (inner, coldex, rowdex) {
                            if (inner.length <= 0) return inner;
                            const el = new DOMParser().parseFromString(inner, 'text/html').body.childNodes;
                            let result = '';
                            el.forEach(item => {
                              if (item.classList && item.classList.contains('user-name')) {
                                result += item.lastChild.firstChild.textContent;
                              } else {
                                result += item.textContent || item.innerText || '';
                              }
                            });
                            return result;
                          }
                        }
                      }
                    },
                    {
                      extend: 'pdf',
                      text: `<span class="d-flex align-items-center"><i class="icon-base ti tabler-file-text me-1"></i>Pdf</span>`,
                      className: 'dropdown-item',
                      exportOptions: {
                        columns: [3, 4, 5, 6, 7],
                        format: {
                          body: function (inner, coldex, rowdex) {
                            if (inner.length <= 0) return inner;
                            const el = new DOMParser().parseFromString(inner, 'text/html').body.childNodes;
                            let result = '';
                            el.forEach(item => {
                              if (item.classList && item.classList.contains('user-name')) {
                                result += item.lastChild.firstChild.textContent;
                              } else {
                                result += item.textContent || item.innerText || '';
                              }
                            });
                            return result;
                          }
                        }
                      }
                    },
                    {
                      extend: 'copy',
                      text: `<i class="icon-base ti tabler-copy me-1"></i>Copy`,
                      className: 'dropdown-item',
                      exportOptions: {
                        columns: [3, 4, 5, 6, 7],
                        format: {
                          body: function (inner, coldex, rowdex) {
                            if (inner.length <= 0) return inner;
                            const el = new DOMParser().parseFromString(inner, 'text/html').body.childNodes;
                            let result = '';
                            el.forEach(item => {
                              if (item.classList && item.classList.contains('user-name')) {
                                result += item.lastChild.firstChild.textContent;
                              } else {
                                result += item.textContent || item.innerText || '';
                              }
                            });
                            return result;
                          }
                        }
                      }
                    }
                  ]
                }
              ]
            }
          ]
        },
        bottomStart: {
          rowClass: 'row mx-3 justify-content-between',
          features: ['info']
        },
        bottomEnd: 'paging'
      },
      language: {
        paginate: {
          next: '<i class="icon-base ti tabler-chevron-right scaleX-n1-rtl icon-18px"></i>',
          previous: '<i class="icon-base ti tabler-chevron-left scaleX-n1-rtl icon-18px"></i>',
          first: '<i class="icon-base ti tabler-chevrons-left scaleX-n1-rtl icon-18px"></i>',
          last: '<i class="icon-base ti tabler-chevrons-right scaleX-n1-rtl icon-18px"></i>'
        }
      },
      // For responsive popup
      responsive: {
        details: {
          display: DataTable.Responsive.display.modal({
            header: function (row) {
              const data = row.data();
              return 'Details of ' + data['customer'];
            }
          }),
          type: 'column',
          renderer: function (api, rowIdx, columns) {
            const data = columns
              .map(function (col) {
                return col.title !== '' // Do not show row in modal popup if title is blank (for check box)
                  ? `<tr data-dt-row="${col.rowIndex}" data-dt-column="${col.columnIndex}">
                      <td>${col.title}:</td>
                      <td>${col.data}</td>
                    </tr>`
                  : '';
              })
              .join('');

            if (data) {
              const div = document.createElement('div');
              div.classList.add('table-responsive');
              const table = document.createElement('table');
              div.appendChild(table);
              table.classList.add('table');
              const tbody = document.createElement('tbody');
              tbody.innerHTML = data;
              table.appendChild(tbody);
              return div;
            }
            return false;
          }
        }
      },
      initComplete:function(settings, json) {
          const api = this.api();
          const colCount = api.columns().count();

          api.rows().every(function() {
              const rowData = this.data();
              const items = JSON.parse(rowData.items);

              // Chèn hàng con ngay sau hàng cha
              $(this.node()).after(getItemsRow(items, colCount));
              $(this.node()).addClass('shown');
          });
      }
      });

    //? The 'delete-record' class is necessary for the functionality of the following code.
    document.addEventListener('click', function (e) {
      if (e.target.classList.contains('delete-record')) {
        dt_products.row(e.target.closest('tr')).remove().draw();
        const modalEl = document.querySelector('.dtr-bs-modal');
        if (modalEl && modalEl.classList.contains('show')) {
          const modal = bootstrap.Modal.getInstance(modalEl);
          modal?.hide();
        }
      }
    });
  }

  // Filter form control to default size
  // ? setTimeout used for order-list table initialization
  setTimeout(() => {
    const elementsToModify = [
      { selector: '.dt-buttons .btn', classToRemove: 'btn-secondary', classToAdd: 'btn-label-secondary' },
      { selector: '.dt-search .form-control', classToRemove: 'form-control-sm', classToAdd: 'ms-0' },
      { selector: '.dt-length .form-select', classToRemove: 'form-select-sm' },
      { selector: '.dt-length', classToAdd: 'mt-md-6 mt-0' },
      { selector: '.dt-layout-table', classToRemove: 'row mt-2' },
      { selector: '.dt-layout-end', classToAdd: 'px-3 mt-0' },
      {
        selector: '.dt-layout-end .dt-buttons',
        classToAdd: 'gap-2 px-3 mt-0 mb-md-0 mb-6'
      },
      {
        selector: '.dt-layout-end .dt-buttons .btn-group',
        classToAdd: 'mx-auto'
      },
      { selector: '.dt-layout-start', classToAdd: 'px-3 mt-0' },
      { selector: '.dt-layout-full', classToRemove: 'col-md col-12', classToAdd: 'table-responsive' }
    ];

    // Delete record
    elementsToModify.forEach(({ selector, classToRemove, classToAdd }) => {
      document.querySelectorAll(selector).forEach(element => {
        if (classToRemove) {
          classToRemove.split(' ').forEach(className => element.classList.remove(className));
        }
        if (classToAdd) {
          classToAdd.split(' ').forEach(className => element.classList.add(className));
        }
      });
    });
  }, 100);
});
