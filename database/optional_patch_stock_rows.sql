-- OPTIONAL patch for iptb_lsms.sql
-- The seed file only creates stock rows for Cawangan Pahang (PHG). Wilayah Pantai Timur (KTN)
-- has no row in location_stock, so every product shows 0 stock there.
-- This adds a row (stock 0) for every product at every location that does not have one yet.
-- Import it AFTER iptb_lsms.sql: phpMyAdmin > iptb_lsms > Import. Safe to run more than once.
USE iptb_lsms;
INSERT IGNORE INTO location_stock (location_id, product_id, opening_stock, opening_date, current_stock)
SELECT l.location_id, p.product_id, 0, CURDATE(), 0
FROM locations l CROSS JOIN products p;
