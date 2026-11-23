-- Where does the cancellation form store its answers?
--
-- The form customers fill in when they cancel (the reason they give, and
-- whether they took the coupon it offers to stay) is saved by a plugin, and
-- every plugin saves it somewhere different. Run each query below in
-- phpMyAdmin (read-only: SELECTs only) and send back whatever returns rows.
-- That tells us which keys or table to export in 07-export-cancellation-form.sql.
--
-- If your tables do not start with `wp_`, change the prefix everywhere.


-- 1) Meta keys on subscriptions or orders that look like a cancellation form
--    (HPOS order meta).
SELECT
    o.type,
    m.meta_key,
    COUNT(*)            AS rows_with_key,
    MIN(m.meta_value)   AS sample_value_a,
    MAX(m.meta_value)   AS sample_value_b
FROM wp_wc_orders o
JOIN wp_wc_orders_meta m ON m.order_id = o.id
WHERE m.meta_key REGEXP 'cancel|reason|survey|feedback|churn|retention|retain|offer|exit|why'
GROUP BY o.type, m.meta_key
ORDER BY rows_with_key DESC;


-- 2) The same in classic post meta (some plugins still write here under HPOS),
--    including on the form's own post types.
SELECT
    p.post_type,
    pm.meta_key,
    COUNT(*)             AS rows_with_key,
    MIN(pm.meta_value)   AS sample_value_a,
    MAX(pm.meta_value)   AS sample_value_b
FROM wp_postmeta pm
JOIN wp_posts p ON p.ID = pm.post_id
WHERE pm.meta_key REGEXP 'cancel|reason|survey|feedback|churn|retention|retain|offer|exit'
GROUP BY p.post_type, pm.meta_key
ORDER BY rows_with_key DESC;


-- 3) The same in user meta (forms that store the answer on the customer).
SELECT
    um.meta_key,
    COUNT(*)             AS rows_with_key,
    MIN(um.meta_value)   AS sample_value_a,
    MAX(um.meta_value)   AS sample_value_b
FROM wp_usermeta um
WHERE um.meta_key REGEXP 'cancel|reason|survey|feedback|churn|retention|retain|offer|exit'
GROUP BY um.meta_key
ORDER BY rows_with_key DESC;


-- 4) Subscription notes that carry a reason or a coupon (the status-history
--    export only reads "Status changed from ..." notes, so these were skipped).
SELECT
    c.comment_post_ID   AS subscription_id,
    c.comment_date_gmt  AS written_at,
    c.comment_content   AS note
FROM wp_comments c
JOIN wp_wc_orders o ON o.id = c.comment_post_ID AND o.type = 'shop_subscription'
WHERE c.comment_type = 'order_note'
  AND c.comment_content REGEXP 'reason|survey|feedback|coupon|offer|discount'
ORDER BY c.comment_date_gmt DESC
LIMIT 50;


-- 5) Tables a cancellation / survey / retention plugin created for itself.
SELECT
    table_name,
    table_rows
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name REGEXP 'cancel|survey|feedback|churn|retention|retain|exit|reason|form|entr'
ORDER BY table_name;


-- 6) Active plugins, so the form's plugin can be named.
SELECT option_value AS active_plugins
FROM wp_options
WHERE option_name = 'active_plugins';
