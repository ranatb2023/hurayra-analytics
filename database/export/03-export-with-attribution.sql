-- Hurayra Analytics — WooCommerce HPOS export, with marketing attribution.
--
-- Supersedes 02-export.sql. Same rows and same first ten columns, so it stays
-- importable, plus the fields the dashboard currently cannot answer marketing
-- questions without. Verified against a full production dump (183 tables); every
-- column below was confirmed populated before being added.
--
-- What each block adds, and why it is worth the join:
--
--  1. ATTRIBUTION — WooCommerce Order Attribution (core since Woo 8.5) is
--     already recording on this store: 6,001 orders carry a utm_source and
--     3,331 a utm_medium. Without these columns no channel can be tied to
--     retention, so acquisition spend cannot be judged against LTV.
--
--  2. BILLING CYCLE — `_billing_period` + `_billing_interval` give the real
--     renewal length. This store is NOT uniformly monthly: roughly 73% renew
--     monthly, 16% every two months, 8% every six weeks. Anything that reasons
--     about "overdue" or "dormant" on a fixed day count is wrong for a quarter
--     of the book, so the cycle has to travel with the row.
--
--  3. NEXT PAYMENT — `_schedule_next_payment` is the scheduled renewal date for
--     live subscriptions (235 of them). It turns "who might churn" from a guess
--     into a dated list, which is what a save campaign needs.
--
--  4. COUPON — 804 order lines carry a coupon (four distinct codes). Needed to
--     tell whether discounted acquisitions retain worse than full-price ones.
--
--  5. PRIMARY PRODUCT — the highest-revenue line on the order. One row per
--     order is preserved; this just labels it, so retention can be split by
--     product without exporting a second line-item file.
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
    END                                             AS ended_at,

    -- 1. Attribution ------------------------------------------------------
    -- source_type is the honest one: 'typein' means direct, and treating that
    -- as a channel win is how direct traffic ends up credited with everything.
    MAX(CASE WHEN attr.meta_key = '_wc_order_attribution_source_type'
             THEN attr.meta_value END)              AS attribution_type,
    MAX(CASE WHEN attr.meta_key = '_wc_order_attribution_utm_source'
             THEN attr.meta_value END)              AS utm_source,
    MAX(CASE WHEN attr.meta_key = '_wc_order_attribution_utm_medium'
             THEN attr.meta_value END)              AS utm_medium,
    MAX(CASE WHEN attr.meta_key = '_wc_order_attribution_utm_campaign'
             THEN attr.meta_value END)              AS utm_campaign,
    MAX(CASE WHEN attr.meta_key = '_wc_order_attribution_device_type'
             THEN attr.meta_value END)              AS device_type,
    MAX(CASE WHEN attr.meta_key = '_wc_order_attribution_referrer'
             THEN attr.meta_value END)              AS referrer,

    -- 2. Billing cycle (subscriptions only) --------------------------------
    CASE WHEN o.type = 'shop_subscription' THEN
        MAX(CASE WHEN cyc.meta_key = '_billing_period' THEN cyc.meta_value END)
    END                                             AS billing_period,
    CASE WHEN o.type = 'shop_subscription' THEN
        MAX(CASE WHEN cyc.meta_key = '_billing_interval'
                 THEN cyc.meta_value END) + 0
    END                                             AS billing_interval,

    -- 3. The live renewal pipeline -----------------------------------------
    CASE WHEN o.type = 'shop_subscription' THEN
        MAX(CASE WHEN sched.meta_key = '_schedule_next_payment'
                 THEN NULLIF(NULLIF(sched.meta_value, ''), '0') END)
    END                                             AS next_payment_at,

    -- 4. Discount -----------------------------------------------------------
    MAX(cp.post_title)                              AS coupon_code,
    COALESCE(SUM(DISTINCT cl.discount_amount), 0)   AS discount_amount,

    -- 5. Primary product: the biggest line on the order ---------------------
    SUBSTRING_INDEX(
        GROUP_CONCAT(pp.post_title ORDER BY pl.product_gross_revenue DESC
                     SEPARATOR '||'), '||', 1)      AS primary_product

FROM wp_wc_orders o
LEFT JOIN wp_wc_orders_meta ren
       ON ren.order_id = o.id AND ren.meta_key = '_subscription_renewal'
LEFT JOIN wp_wc_orders sub
       ON sub.type = 'shop_subscription' AND sub.parent_order_id = o.id
LEFT JOIN wp_wc_orders_meta sched
       ON sched.order_id = o.id
      AND sched.meta_key IN ('_schedule_end', '_schedule_cancelled', '_schedule_next_payment')
LEFT JOIN wp_wc_orders_meta attr
       ON attr.order_id = o.id
      AND attr.meta_key IN ('_wc_order_attribution_source_type',
                            '_wc_order_attribution_utm_source',
                            '_wc_order_attribution_utm_medium',
                            '_wc_order_attribution_utm_campaign',
                            '_wc_order_attribution_device_type',
                            '_wc_order_attribution_referrer')
LEFT JOIN wp_wc_orders_meta cyc
       ON cyc.order_id = o.id
      AND cyc.meta_key IN ('_billing_period', '_billing_interval')
LEFT JOIN wp_wc_order_coupon_lookup cl ON cl.order_id = o.id
LEFT JOIN wp_posts cp ON cp.ID = cl.coupon_id AND cp.post_type = 'shop_coupon'
LEFT JOIN wp_wc_order_product_lookup pl ON pl.order_id = o.id
LEFT JOIN wp_posts pp ON pp.ID = pl.product_id
WHERE o.type IN ('shop_order', 'shop_subscription')
GROUP BY o.id, o.type, o.status, o.customer_id, o.billing_email,
         o.date_created_gmt, o.total_amount, o.date_updated_gmt
ORDER BY o.date_created_gmt;
