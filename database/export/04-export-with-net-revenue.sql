-- Hurayra Analytics — WooCommerce HPOS export: attribution + net revenue.
--
-- Supersedes 03-export-with-attribution.sql. Same rows and same first ten
-- columns, so it stays importable; every extra column is optional and a file
-- exported before they existed still imports unchanged.
--
-- Every auxiliary table is joined **pre-aggregated**. Joining the raw meta,
-- coupon, product and refund rows directly multiplies them together — six meta
-- rows times three product lines times a coupon is eighteen duplicates of one
-- order — which made an earlier version of this query take minutes and forced
-- SUM(DISTINCT …), which is wrong the moment two refunds share an amount.
--
-- What each block adds:
--
--  1. ATTRIBUTION — WooCommerce Order Attribution (core since Woo 8.5). Without
--     it no channel can be tied to retention, so acquisition cannot be judged.
--     `source_type = typein` means direct; treating that as a channel win is how
--     direct ends up credited with everything.
--
--  2. BILLING CYCLE — the real renewal length. This store is NOT uniformly
--     monthly (roughly 73% monthly, 16% two-monthly, 8% six-weekly), so any
--     "overdue" rule on a fixed day count is wrong for a quarter of the book.
--
--  3. NEXT PAYMENT — the scheduled renewal for a live subscription. Makes the
--     renewal pipeline a dated list rather than an inference.
--
--  4. COUPON — whether discounted acquisitions retain worse.
--
--  5. PRIMARY PRODUCT — the highest-revenue line, so retention can be split by
--     product without exporting a second line-item file.
--
--  6. NET REVENUE — `total_amount` is GROSS: it carries VAT and shipping. Here
--     that is about 24% of the figure (£10.8k VAT + £4.4k shipping out of
--     £64.7k), so every LTV, ARPU and retention number built on it is overstated
--     by roughly a third. `wp_wc_order_stats` already holds the split.
--
--  7. REFUNDS — WooCommerce records a refund as its own `shop_order_refund` row
--     with a negative total, linked by `parent_order_id`. A query filtered on
--     `shop_order` never sees them, so refunded money counts as revenue.
--     Summed back onto the parent here, and reported positive.
--
-- Confirm the schedule meta key names with 01-discover-schedule-keys.sql first.
-- Export as CSV with a header row.

SELECT
    o.id,
    o.type                                          AS record_type,
    o.status,
    o.customer_id,
    o.billing_email,
    o.date_created_gmt,
    o.total_amount,
    COALESCE(ren.subscription_id, sub.id)           AS subscription_id,
    CASE
        WHEN o.type = 'shop_subscription'      THEN 'subscription'
        WHEN ren.subscription_id IS NOT NULL   THEN 'renewal'
        WHEN sub.id              IS NOT NULL   THEN 'parent'
        ELSE 'one_time'
    END                                             AS order_relationship,
    CASE WHEN o.type = 'shop_subscription' THEN
        COALESCE(
            NULLIF(NULLIF(sched.schedule_end, ''), '0'),
            NULLIF(NULLIF(sched.schedule_cancelled, ''), '0'),
            o.date_updated_gmt
        )
    END                                             AS ended_at,

    -- 1. Attribution ---------------------------------------------------------
    attr.source_type                                AS attribution_type,
    attr.utm_source,
    attr.utm_medium,
    attr.utm_campaign,
    attr.device_type,
    attr.referrer,

    -- 2. Billing cycle (subscriptions only) ----------------------------------
    CASE WHEN o.type = 'shop_subscription' THEN cyc.billing_period END      AS billing_period,
    CASE WHEN o.type = 'shop_subscription' THEN cyc.billing_interval + 0 END AS billing_interval,

    -- 3. The live renewal pipeline -------------------------------------------
    CASE WHEN o.type = 'shop_subscription'
         THEN NULLIF(NULLIF(sched.schedule_next_payment, ''), '0') END      AS next_payment_at,

    -- 4. Discount ------------------------------------------------------------
    cp.post_title                                   AS coupon_code,
    COALESCE(cl.discount_amount, 0)                 AS discount_amount,

    -- 5. Primary product -----------------------------------------------------
    pp.post_title                                   AS primary_product,

    -- 6. Gross vs net --------------------------------------------------------
    st.net_total                                    AS net_amount,
    st.tax_total                                    AS tax_amount,
    st.shipping_total                               AS shipping_amount,

    -- 7. Refunds (negative in the source; reported positive) -----------------
    COALESCE(-rf.refunded, 0)                       AS refunded_amount

FROM wp_wc_orders o

-- Renewal link: one meta row per order, so no aggregation needed.
LEFT JOIN (
    SELECT order_id, MAX(meta_value) + 0 AS subscription_id
    FROM wp_wc_orders_meta
    WHERE meta_key = '_subscription_renewal'
    GROUP BY order_id
) ren ON ren.order_id = o.id

-- Parent link: the subscription whose parent is this order.
LEFT JOIN (
    SELECT parent_order_id, MIN(id) AS id
    FROM wp_wc_orders
    WHERE type = 'shop_subscription' AND parent_order_id > 0
    GROUP BY parent_order_id
) sub ON sub.parent_order_id = o.id

-- Schedule meta pivoted to one row per order.
LEFT JOIN (
    SELECT order_id,
           MAX(CASE WHEN meta_key = '_schedule_end'          THEN meta_value END) AS schedule_end,
           MAX(CASE WHEN meta_key = '_schedule_cancelled'    THEN meta_value END) AS schedule_cancelled,
           MAX(CASE WHEN meta_key = '_schedule_next_payment' THEN meta_value END) AS schedule_next_payment
    FROM wp_wc_orders_meta
    WHERE meta_key IN ('_schedule_end', '_schedule_cancelled', '_schedule_next_payment')
    GROUP BY order_id
) sched ON sched.order_id = o.id

-- Attribution meta, likewise pivoted.
LEFT JOIN (
    SELECT order_id,
           MAX(CASE WHEN meta_key = '_wc_order_attribution_source_type'  THEN meta_value END) AS source_type,
           MAX(CASE WHEN meta_key = '_wc_order_attribution_utm_source'   THEN meta_value END) AS utm_source,
           MAX(CASE WHEN meta_key = '_wc_order_attribution_utm_medium'   THEN meta_value END) AS utm_medium,
           MAX(CASE WHEN meta_key = '_wc_order_attribution_utm_campaign' THEN meta_value END) AS utm_campaign,
           MAX(CASE WHEN meta_key = '_wc_order_attribution_device_type'  THEN meta_value END) AS device_type,
           MAX(CASE WHEN meta_key = '_wc_order_attribution_referrer'     THEN meta_value END) AS referrer
    FROM wp_wc_orders_meta
    WHERE meta_key LIKE '\_wc\_order\_attribution\_%'
    GROUP BY order_id
) attr ON attr.order_id = o.id

LEFT JOIN (
    SELECT order_id,
           MAX(CASE WHEN meta_key = '_billing_period'   THEN meta_value END) AS billing_period,
           MAX(CASE WHEN meta_key = '_billing_interval' THEN meta_value END) AS billing_interval
    FROM wp_wc_orders_meta
    WHERE meta_key IN ('_billing_period', '_billing_interval')
    GROUP BY order_id
) cyc ON cyc.order_id = o.id

-- One coupon row per order: the largest discount, and the total discounted.
LEFT JOIN (
    SELECT order_id,
           SUM(discount_amount) AS discount_amount,
           SUBSTRING_INDEX(GROUP_CONCAT(coupon_id ORDER BY discount_amount DESC), ',', 1) + 0 AS coupon_id
    FROM wp_wc_order_coupon_lookup
    GROUP BY order_id
) cl ON cl.order_id = o.id
LEFT JOIN wp_posts cp ON cp.ID = cl.coupon_id AND cp.post_type = 'shop_coupon'

-- One product row per order: the biggest line by revenue.
LEFT JOIN (
    SELECT order_id,
           SUBSTRING_INDEX(GROUP_CONCAT(product_id ORDER BY product_gross_revenue DESC), ',', 1) + 0 AS product_id
    FROM wp_wc_order_product_lookup
    GROUP BY order_id
) pl ON pl.order_id = o.id
LEFT JOIN wp_posts pp ON pp.ID = pl.product_id

LEFT JOIN wp_wc_order_stats st ON st.order_id = o.id

LEFT JOIN (
    SELECT parent_order_id, SUM(total_amount) AS refunded
    FROM wp_wc_orders
    WHERE type = 'shop_order_refund'
    GROUP BY parent_order_id
) rf ON rf.parent_order_id = o.id

WHERE o.type IN ('shop_order', 'shop_subscription')
ORDER BY o.date_created_gmt;
