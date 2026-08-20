-- Hurayra Analytics — WooCommerce HPOS export.
--
-- Same as the original export plus an `ended_at` column, which is what lets the
-- dashboard say "who was subscribed in April" instead of "who is subscribed
-- today and existed in April". Without it every ended subscription falls back to
-- the date of its last linked order, which runs about one billing cycle early
-- and undercounts every past month.
--
-- Preference order for the end date, and why:
--   1. _schedule_end       — when the subscription actually finished. If someone
--                            cancels on 1 April but their paid term runs to the
--                            30th, they were a subscriber for all of April.
--   2. _schedule_cancelled — when they ASKED to cancel. Earlier than the real
--                            end, so only used when _schedule_end is missing.
--   3. date_updated_gmt    — last touch on the row. For a subscription that was
--                            cancelled and never edited since, this is close to
--                            the cancellation. Rough, but better than nothing.
--
-- Confirm the meta key names with 01-discover-schedule-keys.sql first.
--
-- Export the result as CSV with a header row and import it as usual. The
-- importer reads `ended_at` only for cancelled/expired subscriptions, so the
-- date_updated_gmt fallback can never be mistaken for an end date on a live one.

SELECT
    o.id,
    o.type                                          AS record_type,
    o.status,
    o.customer_id,
    o.billing_email,
    o.date_created_gmt,
    o.total_amount,
    COALESCE(MAX(ren.meta_value) + 0, MAX(sub.id))  AS subscription_id,
    CASE
        WHEN o.type = 'shop_subscription'    THEN 'subscription'
        WHEN MAX(ren.meta_value) IS NOT NULL THEN 'renewal'
        WHEN MAX(sub.id)         IS NOT NULL THEN 'parent'
        ELSE 'one_time'
    END                                             AS order_relationship,
    CASE WHEN o.type = 'shop_subscription' THEN
        COALESCE(
            MAX(CASE WHEN sched.meta_key = '_schedule_end'
                     THEN NULLIF(NULLIF(sched.meta_value, ''), '0') END),
            MAX(CASE WHEN sched.meta_key = '_schedule_cancelled'
                     THEN NULLIF(NULLIF(sched.meta_value, ''), '0') END),
            o.date_updated_gmt
        )
    END                                             AS ended_at
FROM wp_wc_orders o
LEFT JOIN wp_wc_orders_meta ren
       ON ren.order_id = o.id AND ren.meta_key = '_subscription_renewal'
LEFT JOIN wp_wc_orders sub
       ON sub.type = 'shop_subscription' AND sub.parent_order_id = o.id
LEFT JOIN wp_wc_orders_meta sched
       ON sched.order_id = o.id
      AND sched.meta_key IN ('_schedule_end', '_schedule_cancelled')
WHERE o.type IN ('shop_order', 'shop_subscription')
GROUP BY o.id, o.type, o.status, o.customer_id, o.billing_email,
         o.date_created_gmt, o.total_amount, o.date_updated_gmt
ORDER BY o.date_created_gmt;
