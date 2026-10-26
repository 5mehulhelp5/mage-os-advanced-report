-- MageOS_AdvancedReport benchmark: spread toolkit orders over 24 months with a realistic mix.
--
-- Run it right after `setup:performance:generate-fixtures` (`mysql <db> < dev/benchmark/spread-orders.sql`), never on a real shop:
-- it rewrites created_at, status/state, canceled amounts and the customer of EVERY order.
--
-- Deterministic: no random functions, everything derives from sales_order.entity_id and from
-- @anchor. The result is identical for the same anchor day; re-run this script before benchmarking
-- on a later day so the 24 month window ends on that day again.
--
-- Dates:     created_at = @anchor - (entity_id * 7919 mod 730) days - (entity_id mod 86400) seconds.
--            Applied identically to sales_order, sales_order_item and sales_order_grid.
-- Statuses:  entity_id mod 20 in 0..13 complete/complete (70 %), 14..16 processing/processing (15 %),
--            17..18 pending/new (10 %), 19 canceled/canceled (5 %; canceled amounts and item
--            qty_canceled follow). No credit memos/refunds are created.
-- Customers: entity_id mod 10 < 6 (60 %) are assigned to existing customer_entity rows
--            (customer position = entity_id mod customer count) so customers place repeat orders.
--            The other orders stay guests with their unique toolkit email, and the guest orders with
--            entity_id mod 40 in (17, 37) (about 12 % of the guests) reuse the email of the guest order
--            entity_id - 10 (repeat guests).
-- Currency:  toolkit orders are in USD while the base currency of this shop may be EUR; currencies
--            are not rewritten, so reports show USD amounts.
-- Orders:    `<orders>` of the profiles is a TOTAL target, not an increment.
-- Items:     the toolkit writes parent_item_id = 0 (the string 'null' cast to int) for top-level
--            items, while checkout writes NULL; it is reset to NULL so the top_level_item filter
--            (parent_item_id IS NULL) matches them.

SET @anchor = CURDATE();
SET @customer_count = (SELECT COUNT(*) FROM customer_entity);

-- Dates, status and state, canceled amounts
UPDATE sales_order
SET created_at = DATE_SUB(DATE_SUB(TIMESTAMP(@anchor), INTERVAL ((entity_id * 7919) MOD 730) DAY), INTERVAL (entity_id MOD 86400) SECOND),
    updated_at = DATE_SUB(DATE_SUB(TIMESTAMP(@anchor), INTERVAL ((entity_id * 7919) MOD 730) DAY), INTERVAL (entity_id MOD 86400) SECOND),
    state = CASE
        WHEN entity_id MOD 20 < 14 THEN 'complete'
        WHEN entity_id MOD 20 < 17 THEN 'processing'
        WHEN entity_id MOD 20 < 19 THEN 'new'
        ELSE 'canceled'
    END,
    status = CASE
        WHEN entity_id MOD 20 < 14 THEN 'complete'
        WHEN entity_id MOD 20 < 17 THEN 'processing'
        WHEN entity_id MOD 20 < 19 THEN 'pending'
        ELSE 'canceled'
    END,
    base_subtotal_canceled = IF(entity_id MOD 20 = 19, base_subtotal, 0),
    subtotal_canceled = IF(entity_id MOD 20 = 19, subtotal, 0),
    base_total_canceled = IF(entity_id MOD 20 = 19, base_grand_total, 0),
    total_canceled = IF(entity_id MOD 20 = 19, grand_total, 0);

-- Registered customers on 60 % of the orders
CREATE TEMPORARY TABLE tmp_ar_customers AS
SELECT (ROW_NUMBER() OVER (ORDER BY entity_id)) - 1 AS position,
       entity_id AS customer_id,
       email,
       firstname,
       lastname,
       group_id
FROM customer_entity;
-- Indexed join keys: without them the multi-table UPDATE is a nested loop (hours on the large profile)
ALTER TABLE tmp_ar_customers ADD INDEX idx_position (position);

UPDATE sales_order o
INNER JOIN tmp_ar_customers c ON c.position = o.entity_id MOD @customer_count
SET o.customer_id = c.customer_id,
    o.customer_email = c.email,
    o.customer_firstname = c.firstname,
    o.customer_lastname = c.lastname,
    o.customer_group_id = c.group_id,
    o.customer_is_guest = 0
WHERE o.entity_id MOD 10 < 6;

DROP TEMPORARY TABLE tmp_ar_customers;

-- Repeat guests: reuse the email of the guest order 10 ids earlier
CREATE TEMPORARY TABLE tmp_ar_guest_emails AS
SELECT entity_id, customer_email
FROM sales_order
WHERE entity_id MOD 40 IN (7, 27) AND customer_is_guest = 1;
ALTER TABLE tmp_ar_guest_emails ADD INDEX idx_entity_id (entity_id);

UPDATE sales_order o
INNER JOIN tmp_ar_guest_emails g ON g.entity_id = o.entity_id - 10
SET o.customer_email = g.customer_email
WHERE o.entity_id MOD 40 IN (17, 37) AND o.customer_is_guest = 1;

DROP TEMPORARY TABLE tmp_ar_guest_emails;

-- Items and grid follow the order
UPDATE sales_order_item i
INNER JOIN sales_order o ON o.entity_id = i.order_id
SET i.created_at = o.created_at,
    i.updated_at = o.created_at,
    i.qty_canceled = IF(o.status = 'canceled', i.qty_ordered, 0);

UPDATE sales_order_item SET parent_item_id = NULL WHERE parent_item_id = 0;

UPDATE sales_order_grid g
INNER JOIN sales_order o ON o.entity_id = g.entity_id
SET g.created_at = o.created_at,
    g.updated_at = o.created_at,
    g.status = o.status,
    g.customer_id = o.customer_id,
    g.customer_email = o.customer_email,
    g.customer_name = CONCAT_WS(' ', o.customer_firstname, o.customer_lastname);
