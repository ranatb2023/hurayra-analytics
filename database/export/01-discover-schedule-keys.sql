-- Which meta keys actually exist on subscriptions in this install, and what do
-- they hold? Run this BEFORE the export query and look for the schedule dates:
-- normally `_schedule_end` (when the subscription actually finished) and
-- `_schedule_cancelled` (when the customer asked to cancel — often earlier).
--
-- If your install names them differently, swap the names into 02-export.sql.

SELECT
    m.meta_key,
    COUNT(*)                                   AS rows_with_key,
    SUM(m.meta_value IS NULL
        OR m.meta_value IN ('', '0'))          AS blank_values,
    MIN(NULLIF(NULLIF(m.meta_value, ''), '0')) AS earliest_value,
    MAX(NULLIF(NULLIF(m.meta_value, ''), '0')) AS latest_value
FROM wp_wc_orders o
JOIN wp_wc_orders_meta m ON m.order_id = o.id
WHERE o.type = 'shop_subscription'
GROUP BY m.meta_key
ORDER BY rows_with_key DESC;
