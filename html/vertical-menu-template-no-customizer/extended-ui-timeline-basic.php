<?php
require __DIR__ . '/../../functions-telnyx.php';
$sms = get_sms();
?>
<div class="row gy-6">
    <!-- Timeline Basic-->
    <div class="col-xl-12">
        <div class="card h-100">
            <h5 class="card-header">SMS</h5>
            <div class="card-body">
                <ul class="timeline mb-0">
                    <?php foreach ($sms as $value): ?>
                        <li class="timeline-item timeline-item-transparent">
                            <span class="timeline-point timeline-point-primary"></span>
                            <div class="timeline-event">
                                <div class="timeline-header mb-3">
                                    <h6 class="mb-0">12 Invoices have been paid</h6>
                                    <small class="text-body-secondary">12 min ago</small>
                                </div>
                                <p class="mb-2">Invoices have been paid to the company</p>
                                <div class="d-flex align-items-center mb-2">
                                    <div class="badge bg-lighter rounded d-flex align-items-center">
                                        <img src="../../assets//img/icons/misc/pdf.png" alt="img" width="15" class="me-2" />
                                        <span class="h6 mb-0 text-body">invoices.pdf</span>
                                    </div>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                    ?>
                </ul>
            </div>
        </div>
    </div>
    <!-- /Timeline Basic -->
</div>