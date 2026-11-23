-- Cancellation form answers, one row per submission, for the dashboard's
-- "Cancellation reasons" panel. Upload the CSV on the Upload page like the
-- orders export; the dashboard recognises it by its header.
--
-- Source (custom HPF build, found with 06-discover-cancellation-form.sql):
--   - hpf_cancel_survey posts, answers in wp_postmeta under _hpf_cancel_*
--   - the retention offer is a direct discount, not a coupon:
--       subscription meta  _hpf_retention_accepted_at / _percent / _shipping_value
--       renewal order meta _hpf_retention_factor = the renewal that spent it
--
-- The survey's own retention_used flag and the subscription's accepted_at do
-- not always agree (5 vs 8 when this was written), so both are exported and
-- the dashboard counts an offer as accepted when either says so. Subscriptions
-- that accepted the offer with no survey on record get a row of their own
-- (survey_id empty) so the accepted count reconciles. Only subscriptions
-- count: the renewal order that spends the discount carries accepted_at too.
--
-- HOW TO EXPORT IN phpMyAdmin
--   1. SQL tab: paste this whole file and click Go. Run it on its own.
--   2. Under the results, in "Query results operations", click Export.
--      (Or: the Export tab at the top right after running it.)
--   3. Format: CSV. Choose "Custom" and tick
--      "Put columns names in the first row". Replace NULL with: leave empty.
--   4. Upload the downloaded .csv on the dashboard's Upload CSV page.
--
-- Plain SELECT on purpose (no WITH): phpMyAdmin hides the Export option for
-- queries that start with WITH. Read-only.
-- If your tables do not start with `wp_`, change the prefix everywhere.

SELECT
    s.survey_id,
    s.subscription_id,
    -- _hpf_cancel_date is a unix timestamp; turned into UTC without touching
    -- the session time zone (FROM_UNIXTIME would use it).
    COALESCE(
        CASE WHEN s.submitted_ts REGEXP '^[0-9]+$'
             THEN DATE_ADD('1970-01-01 00:00:00', INTERVAL s.submitted_ts SECOND) END,
        s.post_date_gmt
    )                                                        AS submitted_at,
    s.email,
    s.customer_name,
    s.reason,
    s.satisfaction,
    s.would_return,
    s.feedback,
    s.subscription_total,
    s.currency,
    s.products,
    s.retention_used,
    COALESCE(r.percent, s.survey_retention_percent)          AS retention_percent,
    COALESCE(r.shipping_value, s.survey_retention_shipping)  AS retention_shipping,
    r.accepted_at                                            AS retention_accepted_at,
    COALESCE(sp.paid_orders, 0)                              AS retention_spent_orders,
    sp.first_order_at                                        AS retention_first_spent_at
FROM (
    -- One row per survey post, its answers pivoted out of postmeta.
    SELECT
        p.ID AS survey_id,
        p.post_date_gmt,
        MAX(CASE WHEN m.meta_key = '_hpf_cancel_subscription_id'    THEN m.meta_value END) + 0 AS subscription_id,
        MAX(CASE WHEN m.meta_key = '_hpf_cancel_date'               THEN m.meta_value END)     AS submitted_ts,
        MAX(CASE WHEN m.meta_key = '_hpf_cancel_email'              THEN m.meta_value END)     AS email,
        MAX(CASE WHEN m.meta_key = '_hpf_cancel_customer_name'      THEN m.meta_value END)     AS customer_name,
        MAX(CASE WHEN m.meta_key = '_hpf_cancel_reason'             THEN m.meta_value END)     AS reason,
        MAX(CASE WHEN m.meta_key = '_hpf_cancel_satisfaction'       THEN m.meta_value END)     AS satisfaction,
        MAX(CASE WHEN m.meta_key = '_hpf_cancel_would_return'       THEN m.meta_value END)     AS would_return,
        MAX(CASE WHEN m.meta_key = '_hpf_cancel_feedback'           THEN m.meta_value END)     AS feedback,
        MAX(CASE WHEN m.meta_key = '_hpf_cancel_subscription_total' THEN m.meta_value END)     AS subscription_total,
        MAX(CASE WHEN m.meta_key = '_hpf_cancel_currency'           THEN m.meta_value END)     AS currency,
        MAX(CASE WHEN m.meta_key = '_hpf_cancel_products'           THEN m.meta_value END)     AS products,
        MAX(CASE WHEN m.meta_key = '_hpf_cancel_retention_used'     THEN m.meta_value END)     AS retention_used,
        MAX(CASE WHEN m.meta_key = '_hpf_cancel_retention_percent'  THEN m.meta_value END)     AS survey_retention_percent,
        MAX(CASE WHEN m.meta_key = '_hpf_cancel_retention_shipping' THEN m.meta_value END)     AS survey_retention_shipping
    FROM wp_posts p
    LEFT JOIN wp_postmeta m ON m.post_id = p.ID AND m.meta_key LIKE '\_hpf\_cancel\_%'
    WHERE p.post_type = 'hpf_cancel_survey'
      AND p.post_status = 'publish'
    GROUP BY p.ID, p.post_date_gmt
) s
LEFT JOIN (
    -- The offer as the subscription records it.
    SELECT
        rm.order_id AS subscription_id,
        MAX(CASE WHEN rm.meta_key = '_hpf_retention_accepted_at'    THEN rm.meta_value END) AS accepted_at,
        MAX(CASE WHEN rm.meta_key = '_hpf_retention_percent'        THEN rm.meta_value END) AS percent,
        MAX(CASE WHEN rm.meta_key = '_hpf_retention_shipping_value' THEN rm.meta_value END) AS shipping_value
    FROM wp_wc_orders_meta rm
    -- Subscriptions only: the renewal order that spends the discount carries
    -- _hpf_retention_accepted_at too, and would otherwise count as a second offer.
    JOIN wp_wc_orders ro ON ro.id = rm.order_id AND ro.type = 'shop_subscription'
    WHERE rm.meta_key IN ('_hpf_retention_accepted_at', '_hpf_retention_percent', '_hpf_retention_shipping_value')
    GROUP BY rm.order_id
) r ON r.subscription_id = s.subscription_id
LEFT JOIN (
    -- Paid renewal orders that carried the discount: the offer actually spent.
    SELECT
        ren.meta_value + 0                                  AS subscription_id,
        SUM(o.status IN ('wc-completed', 'wc-processing'))  AS paid_orders,
        MIN(o.date_created_gmt)                             AS first_order_at
    FROM wp_wc_orders_meta ren
    JOIN wp_wc_orders_meta f ON f.order_id = ren.order_id AND f.meta_key = '_hpf_retention_factor'
    JOIN wp_wc_orders o      ON o.id = ren.order_id
    WHERE ren.meta_key = '_subscription_renewal'
    GROUP BY ren.meta_value + 0
) sp ON sp.subscription_id = s.subscription_id

UNION ALL

-- Accepted the offer, but no survey on record for that subscription.
SELECT
    NULL, r.subscription_id, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL,
    'yes', r.percent, r.shipping_value, r.accepted_at,
    COALESCE(sp.paid_orders, 0), sp.first_order_at
FROM (
    SELECT
        rm.order_id AS subscription_id,
        MAX(CASE WHEN rm.meta_key = '_hpf_retention_accepted_at'    THEN rm.meta_value END) AS accepted_at,
        MAX(CASE WHEN rm.meta_key = '_hpf_retention_percent'        THEN rm.meta_value END) AS percent,
        MAX(CASE WHEN rm.meta_key = '_hpf_retention_shipping_value' THEN rm.meta_value END) AS shipping_value
    FROM wp_wc_orders_meta rm
    -- Subscriptions only: the renewal order that spends the discount carries
    -- _hpf_retention_accepted_at too, and would otherwise count as a second offer.
    JOIN wp_wc_orders ro ON ro.id = rm.order_id AND ro.type = 'shop_subscription'
    WHERE rm.meta_key IN ('_hpf_retention_accepted_at', '_hpf_retention_percent', '_hpf_retention_shipping_value')
    GROUP BY rm.order_id
) r
LEFT JOIN (
    SELECT
        ren.meta_value + 0                                  AS subscription_id,
        SUM(o.status IN ('wc-completed', 'wc-processing'))  AS paid_orders,
        MIN(o.date_created_gmt)                             AS first_order_at
    FROM wp_wc_orders_meta ren
    JOIN wp_wc_orders_meta f ON f.order_id = ren.order_id AND f.meta_key = '_hpf_retention_factor'
    JOIN wp_wc_orders o      ON o.id = ren.order_id
    WHERE ren.meta_key = '_subscription_renewal'
    GROUP BY ren.meta_value + 0
) sp ON sp.subscription_id = r.subscription_id
WHERE r.accepted_at IS NOT NULL
  AND r.subscription_id NOT IN (
      SELECT pm.meta_value + 0
      FROM wp_postmeta pm
      JOIN wp_posts p ON p.ID = pm.post_id
      WHERE pm.meta_key = '_hpf_cancel_subscription_id'
        AND p.post_type = 'hpf_cancel_survey'
        AND p.post_status = 'publish'
  )

ORDER BY submitted_at, survey_id;
