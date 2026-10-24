-- MageOS_AdvancedReport: measure how long building each module index takes on a seeded database.
--
-- Run it with `mysql <db> < dev/benchmark/index-timing.sql` (user-run only, on the seeded database). It never alters
-- the real tables: the work happens on the copies mageos_ar_idx_copy_order / mageos_ar_idx_copy_item, which
-- are dropped at the end. Expect a full copy of sales_order and sales_order_item (disk space and time).
--
-- CREATE TABLE ... LIKE copies ALL indexes including the module ones, so each module index is dropped from
-- the copy first; only then is ADD INDEX timed (online: ALGORITHM=INPLACE, LOCK=NONE, like db_schema.xml).

DROP TABLE IF EXISTS mageos_ar_idx_copy_order;
DROP TABLE IF EXISTS mageos_ar_idx_copy_item;

CREATE TABLE mageos_ar_idx_copy_order LIKE sales_order;
INSERT INTO mageos_ar_idx_copy_order SELECT * FROM sales_order;
CREATE TABLE mageos_ar_idx_copy_item LIKE sales_order_item;
INSERT INTO mageos_ar_idx_copy_item SELECT * FROM sales_order_item;

ALTER TABLE mageos_ar_idx_copy_order DROP INDEX SALES_ORDER_CUSTOMER_ID_CREATED_AT;
ALTER TABLE mageos_ar_idx_copy_order DROP INDEX SALES_ORDER_CUSTOMER_EMAIL_CREATED_AT;
ALTER TABLE mageos_ar_idx_copy_item DROP INDEX SALES_ORDER_ITEM_PRODUCT_ID;
ALTER TABLE mageos_ar_idx_copy_item DROP INDEX SALES_ORDER_ITEM_PRODUCT_ID_ORDER_ID;

SET @t = NOW(6);
ALTER TABLE mageos_ar_idx_copy_order ADD INDEX SALES_ORDER_CUSTOMER_ID_CREATED_AT (customer_id, created_at), ALGORITHM=INPLACE, LOCK=NONE;
SELECT 'SALES_ORDER_CUSTOMER_ID_CREATED_AT' AS `index`, TIMESTAMPDIFF(MICROSECOND, @t, NOW(6)) / 1000000 AS seconds;

SET @t = NOW(6);
ALTER TABLE mageos_ar_idx_copy_order ADD INDEX SALES_ORDER_CUSTOMER_EMAIL_CREATED_AT (customer_email, created_at), ALGORITHM=INPLACE, LOCK=NONE;
SELECT 'SALES_ORDER_CUSTOMER_EMAIL_CREATED_AT' AS `index`, TIMESTAMPDIFF(MICROSECOND, @t, NOW(6)) / 1000000 AS seconds;

SET @t = NOW(6);
ALTER TABLE mageos_ar_idx_copy_item ADD INDEX SALES_ORDER_ITEM_PRODUCT_ID (product_id), ALGORITHM=INPLACE, LOCK=NONE;
SELECT 'SALES_ORDER_ITEM_PRODUCT_ID' AS `index`, TIMESTAMPDIFF(MICROSECOND, @t, NOW(6)) / 1000000 AS seconds;

SET @t = NOW(6);
ALTER TABLE mageos_ar_idx_copy_item ADD INDEX SALES_ORDER_ITEM_PRODUCT_ID_ORDER_ID (product_id, order_id), ALGORITHM=INPLACE, LOCK=NONE;
SELECT 'SALES_ORDER_ITEM_PRODUCT_ID_ORDER_ID' AS `index`, TIMESTAMPDIFF(MICROSECOND, @t, NOW(6)) / 1000000 AS seconds;

DROP TABLE IF EXISTS mageos_ar_idx_copy_order;
DROP TABLE IF EXISTS mageos_ar_idx_copy_item;
