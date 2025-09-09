#!/bin/bash

# Ngưỡng tải hệ thống (ví dụ: 1.0)
THRESHOLD=1.0

# Lấy load trung bình 1 phút
LOAD=$(awk '{print $1}' /proc/loadavg)

# So sánh và chạy nếu thấp hơn ngưỡng
if (( $(echo "$LOAD < $THRESHOLD" | bc -l) )); then
    /usr/bin/php /var/www/html/admin-profulfill/cron-job.php >> /var/log/cron-job.log 2>&1
else
    echo "$(date): Skipped due to high load ($LOAD)" >> /var/log/cron-job.log
fi