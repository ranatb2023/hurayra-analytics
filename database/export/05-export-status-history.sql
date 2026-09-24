-- Hurayra Analytics — subscription status history.
--
-- A SECOND file, uploaded alongside the orders export (04-export-with-net-revenue.sql).
-- The orders export only carries each subscription's status TODAY, so a
-- subscription that was on hold in March and has since resumed or cancelled
-- leaves no trace of the hold. Past months' On Hold, Pending Cancellation and
-- Pending counts cannot be rebuilt from it.
--
-- WooCommerce Subscriptions writes an order note on every status change:
--
--     "Status changed from Active to On hold."
--
-- sometimes after a reason ("Subscription renewal payment due: Status changed
-- from Active to On hold."). This exports those notes as they are; the app
-- parses the two statuses out of the text on import.
--
-- Order notes live in wp_comments under HPOS too, keyed on the order id.
-- Export as CSV with a header row, then upload it on the Upload page like any
-- other file. Re-uploading is safe: duplicate changes are ignored.

SELECT
    c.comment_post_ID     AS subscription_id,
    c.comment_date_gmt    AS changed_at,
    c.comment_content     AS note
FROM wp_comments c
JOIN wp_wc_orders o
  ON o.id = c.comment_post_ID
 AND o.type = 'shop_subscription'
WHERE c.comment_type = 'order_note'
  AND c.comment_content LIKE '%tatus changed from % to %'
ORDER BY c.comment_date_gmt, c.comment_ID;
