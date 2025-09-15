<?php
require __DIR__ . '/../../functions-telnyx.php';
$sms = get_sms();
$colors = [
        'timeline-point-primary',
        'timeline-point-success',
        'timeline-point-danger',
        'timeline-point-warning',
        'timeline-point-info',
        'timeline-point-secondary'
];
// Chọn ngẫu nhiên 1 class
$randomColor = $colors[array_rand($colors)];
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
                            <span class="timeline-point <?= $randomColor; ?>"></span>
                            <div class="timeline-event">
                                <div class="timeline-header mb-3">
                                    <h6 class="mb-0"><?= $value['name']; ?> <?= $value['number']; ?></h6>
                                    <small class="text-body-secondary"><?= timeAgo($value['date']) ?></small>
                                </div>
                                <p class="mb-2"><?= $value['text']; ?></p>
                                <p class="mb-2">from: <?= $value['from_number']; ?></p>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
    <!-- /Timeline Basic -->
</div>