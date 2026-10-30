-- =====================================================================
-- IPTB-LSMS : Lubricant Stock Management System for IPTB Sdn Bhd
-- BCS3263 Software Quality Assurance, Semester I 2026/2027
-- Database : MySQL 5.7+ / MariaDB 10.4+ (XAMPP + phpMyAdmin)
--
-- Import   : phpMyAdmin > Import > choose this file > Go
--            (drops and recreates the database iptb_lsms)
--
-- Built from the LSMS repo (login.html, Modules 1-4):
--   Module 1  Product Management    -> products
--   Module 2  Stock Receiving       -> suppliers, deliveries, delivery_items
--   Module 3  Sales and Adjustment  -> sales, sale_items, stock_adjustments
--   Module 4  Reports and Alerts    -> alert_rules, product_alert_settings, reports + views
--   Login     Users and locations   -> users, locations, staff_locations
--
-- LOCATIONS
--   * products, suppliers and the alert rule are shared by every location.
--   * stock, deliveries, sales and adjustments belong to ONE location.
--   * An admin assigns each staff member to a location on the admin page
--     (table staff_locations, one row marked is_default = 1; the table also
--     allows more than one location per staff member later).
--   * Staff log in with their EMAIL and the password the admin set.
--   * After login the app opens the dashboard of the staff member's location:
--         SELECT home_location_id FROM v_user_home WHERE email = ?;
--     Every staff query must then filter on that location_id.
--     Admin accounts have no location and can open any location.
--
-- DEMO ACCOUNTS (change the passwords before real use)
--     admin / admin123
--     staff.phg@example.com / staff123   (Cawangan Pahang)
--     staff.ktn@example.com / staff123   (Wilayah Pantai Timur)
--
-- STOCK
--   location_stock.current_stock is kept up to date by the triggers below and
--   can never go below 0. Reports can be rebuilt from v_stock_ledger.
-- =====================================================================

DROP DATABASE IF EXISTS iptb_lsms;
CREATE DATABASE iptb_lsms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE iptb_lsms;

-- ---------------------------------------------------------------------
-- Users, locations and staff-location assignment
-- ---------------------------------------------------------------------
CREATE TABLE users (
  user_id        INT AUTO_INCREMENT PRIMARY KEY,
  full_name      VARCHAR(100) NOT NULL,
  email          VARCHAR(100) NOT NULL UNIQUE,          -- login id: staff use their email; the demo admin uses 'admin'
  password_hash  VARCHAR(255) NOT NULL,                 -- PHP password_hash() / password_verify()
  role           ENUM('admin','staff') NOT NULL DEFAULT 'staff',
  is_active      TINYINT(1) NOT NULL DEFAULT 1,         -- deactivate instead of deleting
  last_login_at  DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE locations (                                -- an IPTB office / station
  location_id    INT AUTO_INCREMENT PRIMARY KEY,
  location_code  VARCHAR(10)  NOT NULL UNIQUE,          -- PHG, KTN ...
  location_name  VARCHAR(100) NOT NULL,
  address        VARCHAR(255) NULL,
  state          VARCHAR(50)  NULL,
  phone          VARCHAR(20)  NULL,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE staff_locations (                          -- set by the admin
  staff_location_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id           INT NOT NULL,
  location_id       INT NOT NULL,
  is_default        TINYINT(1) NOT NULL DEFAULT 0,      -- the dashboard opened after login
  assigned_by       INT NULL,
  assigned_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  default_user_id   INT GENERATED ALWAYS AS (IF(is_default = 1, user_id, NULL)) STORED,
  UNIQUE KEY uq_user_location (user_id, location_id),
  UNIQUE KEY uq_one_default   (default_user_id),        -- at most one default per staff member
  CONSTRAINT fk_sl_user     FOREIGN KEY (user_id)     REFERENCES users(user_id),
  CONSTRAINT fk_sl_location FOREIGN KEY (location_id) REFERENCES locations(location_id),
  CONSTRAINT fk_sl_admin    FOREIGN KEY (assigned_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Module 1: Product Management (shared catalogue)
-- ---------------------------------------------------------------------
CREATE TABLE products (
  product_id       INT AUTO_INCREMENT PRIMARY KEY,      -- SKU = 'LUB-' + product_id padded to 3 digits
  sku              VARCHAR(10)  NOT NULL UNIQUE,
  brand            VARCHAR(50)  NOT NULL,
  product_name     VARCHAR(100) NOT NULL,
  viscosity_grade  VARCHAR(20)  NOT NULL,               -- 10W-40, SAE 90 EP, 2T ...
  product_type     ENUM('Engine oil','Gear oil','Motorcycle oil','Grease','Other') NOT NULL,
  pack_size        DECIMAL(6,2) NOT NULL,               -- litres per pack: 0.5, 0.8, 1, 4, 18, 208
  cost_price       DECIMAL(10,2) NOT NULL,              -- RM
  selling_price    DECIMAL(10,2) NOT NULL,              -- RM
  min_stock        INT NOT NULL DEFAULT 0,              -- packs (default alert level)
  image_path       VARCHAR(255) NULL,                   -- images/LUB-001.jpg
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_prod_pack  CHECK (pack_size > 0),
  CONSTRAINT chk_prod_cost  CHECK (cost_price >= 0),
  CONSTRAINT chk_prod_price CHECK (selling_price >= 0),
  CONSTRAINT chk_prod_min   CHECK (min_stock >= 0)
) ENGINE=InnoDB;

CREATE TABLE location_stock (                           -- stock of one product at one location
  location_id    INT NOT NULL,
  product_id     INT NOT NULL,
  opening_stock  INT NOT NULL DEFAULT 0,                -- balance on opening_date (Module 4 reports)
  opening_date   DATE NOT NULL,
  current_stock  INT NOT NULL DEFAULT 0,                -- kept up to date by triggers
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (location_id, product_id),
  CONSTRAINT chk_ls_open  CHECK (opening_stock >= 0),
  CONSTRAINT chk_ls_stock CHECK (current_stock >= 0),
  CONSTRAINT fk_ls_location FOREIGN KEY (location_id) REFERENCES locations(location_id),
  CONSTRAINT fk_ls_product  FOREIGN KEY (product_id)  REFERENCES products(product_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Module 2: Stock Receiving (Supplier Deliveries)
-- ---------------------------------------------------------------------
CREATE TABLE suppliers (
  supplier_id     INT AUTO_INCREMENT PRIMARY KEY,
  supplier_name   VARCHAR(100) NOT NULL UNIQUE,
  contact_person  VARCHAR(100) NULL,
  phone           VARCHAR(20)  NULL,
  email           VARCHAR(100) NULL,
  address         VARCHAR(255) NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE deliveries (                               -- shown as DEL-001
  delivery_id    INT AUTO_INCREMENT PRIMARY KEY,
  location_id    INT NOT NULL,                          -- the station that received the stock
  supplier_id    INT NOT NULL,
  invoice_no     VARCHAR(30) NOT NULL UNIQUE,           -- no duplicate invoices
  delivery_date  DATE NOT NULL,                         -- not in the future (checked by the app)
  remarks        VARCHAR(255) NULL,
  created_by     INT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_del_location FOREIGN KEY (location_id) REFERENCES locations(location_id),
  CONSTRAINT fk_del_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(supplier_id),
  CONSTRAINT fk_del_user     FOREIGN KEY (created_by)  REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE delivery_items (
  delivery_item_id  INT AUTO_INCREMENT PRIMARY KEY,
  delivery_id       INT NOT NULL,
  product_id        INT NOT NULL,
  quantity          INT NOT NULL,                       -- packs received
  unit_cost         DECIMAL(10,2) NOT NULL,             -- RM per pack on the invoice
  UNIQUE KEY uq_delivery_product (delivery_id, product_id),   -- a product appears once per delivery
  CONSTRAINT chk_di_qty  CHECK (quantity >= 1),
  CONSTRAINT chk_di_cost CHECK (unit_cost >= 0),
  CONSTRAINT fk_di_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(delivery_id) ON DELETE CASCADE,
  CONSTRAINT fk_di_product  FOREIGN KEY (product_id)  REFERENCES products(product_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Module 3: Sales and Stock Adjustment
-- ---------------------------------------------------------------------
CREATE TABLE sales (                                    -- shown as SAL-001
  sale_id      INT AUTO_INCREMENT PRIMARY KEY,
  location_id  INT NOT NULL,
  sale_date    DATE NOT NULL,
  recorded_by  INT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sale_location FOREIGN KEY (location_id) REFERENCES locations(location_id),
  CONSTRAINT fk_sale_user     FOREIGN KEY (recorded_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE sale_items (
  sale_item_id  INT AUTO_INCREMENT PRIMARY KEY,
  sale_id       INT NOT NULL,
  product_id    INT NOT NULL,
  quantity      INT NOT NULL,                           -- packs sold
  unit_price    DECIMAL(10,2) NOT NULL,                 -- RM per pack at the time of sale
  UNIQUE KEY uq_sale_product (sale_id, product_id),
  CONSTRAINT chk_si_qty   CHECK (quantity >= 1),
  CONSTRAINT chk_si_price CHECK (unit_price >= 0),
  CONSTRAINT fk_si_sale    FOREIGN KEY (sale_id)    REFERENCES sales(sale_id) ON DELETE CASCADE,
  CONSTRAINT fk_si_product FOREIGN KEY (product_id) REFERENCES products(product_id)
) ENGINE=InnoDB;

CREATE TABLE stock_adjustments (                        -- shown as ADJ-001
  adjustment_id    INT AUTO_INCREMENT PRIMARY KEY,
  location_id      INT NOT NULL,
  product_id       INT NOT NULL,
  adjustment_date  DATE NOT NULL,
  adjustment_type  ENUM('damaged','expired','count') NOT NULL,
  system_count     INT NULL,                            -- count: system balance (filled by trigger)
  physical_count   INT NULL,                            -- count: packs counted on the shelf
  quantity_change  INT NOT NULL DEFAULT 0,              -- signed. damaged/expired: enter packs lost (stored negative)
                                                        --         count: variance = physical - system (filled by trigger)
  note             VARCHAR(255) NOT NULL,               -- reason / remark is required
  adjusted_by      INT NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_adj_phys CHECK (physical_count IS NULL OR physical_count >= 0),
  CONSTRAINT fk_adj_location FOREIGN KEY (location_id) REFERENCES locations(location_id),
  CONSTRAINT fk_adj_product  FOREIGN KEY (product_id)  REFERENCES products(product_id),
  CONSTRAINT fk_adj_user     FOREIGN KEY (adjusted_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Module 4: Alert settings and saved reports
-- ---------------------------------------------------------------------
CREATE TABLE alert_rules (                              -- one row: the rule for every product
  rule_id           TINYINT NOT NULL PRIMARY KEY DEFAULT 1,
  mode              ENUM('min','buffer') NOT NULL DEFAULT 'min',   -- min: flag at or below the alert level
                                                                    -- buffer: early warning (level + buffer %)
  buffer_pct        INT NOT NULL DEFAULT 20,
  window_days       INT NOT NULL DEFAULT 30,            -- days used for average sold per day
  reorder_multiplier DECIMAL(3,1) NOT NULL DEFAULT 2,   -- re-order up to multiplier x alert level
  updated_by        INT NULL,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_rule_one   CHECK (rule_id = 1),
  CONSTRAINT chk_rule_buf   CHECK (buffer_pct BETWEEN 0 AND 100),
  CONSTRAINT chk_rule_win   CHECK (window_days BETWEEN 1 AND 365),
  CONSTRAINT chk_rule_mult  CHECK (reorder_multiplier BETWEEN 1 AND 5),
  CONSTRAINT fk_rule_user   FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE product_alert_settings (                   -- optional per-product override
  product_id    INT NOT NULL PRIMARY KEY,
  custom_level  INT NULL,                               -- NULL = use products.min_stock
  is_enabled    TINYINT(1) NOT NULL DEFAULT 1,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_pas_level CHECK (custom_level IS NULL OR custom_level >= 0),
  CONSTRAINT fk_pas_product FOREIGN KEY (product_id) REFERENCES products(product_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE reports (                                  -- saved reports, shown as RPT-0001
  report_id         INT AUTO_INCREMENT PRIMARY KEY,
  report_type       ENUM('daily','monthly','range','product') NOT NULL,
  title             VARCHAR(100) NOT NULL,
  location_id       INT NULL,                           -- NULL = all locations (admin)
  date_from         DATE NOT NULL,
  date_to           DATE NOT NULL,
  product_id        INT NULL,                           -- only for 'product' reports
  brand_filter      VARCHAR(50) NULL,
  type_filter       VARCHAR(30) NULL,
  status_filter     ENUM('flagged','low','out','ok') NULL,
  rule_snapshot     JSON NULL,                          -- alert rule in force when generated
  result_snapshot   JSON NULL,                          -- rows and totals shown in the report
  generated_by      INT NULL,
  generated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_rep_dates CHECK (date_to >= date_from),
  CONSTRAINT fk_rep_location FOREIGN KEY (location_id) REFERENCES locations(location_id) ON DELETE SET NULL,
  CONSTRAINT fk_rep_product  FOREIGN KEY (product_id)  REFERENCES products(product_id)  ON DELETE SET NULL,
  CONSTRAINT fk_rep_user     FOREIGN KEY (generated_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Stored procedure + triggers (stock never below 0)
-- Note: MySQL does not fire triggers for cascaded deletes, so deleting a whole
-- delivery or sale is handled by trg_deliveries_bd / trg_sales_bd.
-- ---------------------------------------------------------------------
DELIMITER $$

CREATE PROCEDURE sp_stock_change(IN p_location INT, IN p_product INT, IN p_delta INT)
BEGIN
  DECLARE v_stock INT DEFAULT 0;
  DECLARE v_msg   VARCHAR(200);
  INSERT IGNORE INTO location_stock (location_id, product_id, opening_stock, opening_date, current_stock)
  VALUES (p_location, p_product, 0, CURDATE(), 0);
  SELECT current_stock INTO v_stock FROM location_stock
   WHERE location_id = p_location AND product_id = p_product FOR UPDATE;
  IF v_stock + p_delta < 0 THEN
    SET v_msg = CONCAT('Stock would drop below 0 (', v_stock, ' pack(s) in stock, change ', p_delta, ').');
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_msg;
  END IF;
  UPDATE location_stock SET current_stock = v_stock + p_delta
   WHERE location_id = p_location AND product_id = p_product;
END$$

-- staff_locations: only staff accounts; the first location becomes the default -----------
CREATE TRIGGER trg_sl_bi BEFORE INSERT ON staff_locations FOR EACH ROW
BEGIN
  DECLARE v_role VARCHAR(10);
  SELECT role INTO v_role FROM users WHERE user_id = NEW.user_id;
  IF v_role IS NULL OR v_role <> 'staff' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only staff accounts are assigned to locations (admin can open every location).';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM staff_locations WHERE user_id = NEW.user_id) THEN
    SET NEW.is_default = 1;
  END IF;
END$$

-- Module 2: delivery items --------------------------------------------------------------
CREATE TRIGGER trg_di_ai AFTER INSERT ON delivery_items FOR EACH ROW
BEGIN
  DECLARE v_loc INT;
  SELECT location_id INTO v_loc FROM deliveries WHERE delivery_id = NEW.delivery_id;
  CALL sp_stock_change(v_loc, NEW.product_id, NEW.quantity);
END$$

CREATE TRIGGER trg_di_au AFTER UPDATE ON delivery_items FOR EACH ROW
BEGIN
  DECLARE v_loc INT;
  IF NEW.delivery_id <> OLD.delivery_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An item cannot be moved to another delivery. Delete and add it again.';
  END IF;
  SELECT location_id INTO v_loc FROM deliveries WHERE delivery_id = NEW.delivery_id;
  IF NEW.product_id = OLD.product_id THEN
    IF NEW.quantity <> OLD.quantity THEN
      CALL sp_stock_change(v_loc, NEW.product_id, NEW.quantity - OLD.quantity);
    END IF;
  ELSE
    CALL sp_stock_change(v_loc, OLD.product_id, -OLD.quantity);
    CALL sp_stock_change(v_loc, NEW.product_id, NEW.quantity);
  END IF;
END$$

CREATE TRIGGER trg_di_ad AFTER DELETE ON delivery_items FOR EACH ROW
BEGIN
  DECLARE v_loc INT;
  SELECT location_id INTO v_loc FROM deliveries WHERE delivery_id = OLD.delivery_id;
  CALL sp_stock_change(v_loc, OLD.product_id, -OLD.quantity);
END$$

CREATE TRIGGER trg_deliveries_bu BEFORE UPDATE ON deliveries FOR EACH ROW
BEGIN
  IF NEW.location_id <> OLD.location_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The location of a delivery cannot be changed.';
  END IF;
END$$

CREATE TRIGGER trg_deliveries_bd BEFORE DELETE ON deliveries FOR EACH ROW
BEGIN
  IF EXISTS (SELECT 1 FROM delivery_items di
               LEFT JOIN location_stock ls ON ls.location_id = OLD.location_id AND ls.product_id = di.product_id
              WHERE di.delivery_id = OLD.delivery_id AND COALESCE(ls.current_stock, 0) < di.quantity) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot cancel delivery: stock would drop below 0 (some units were already sold).';
  END IF;
  UPDATE location_stock ls JOIN delivery_items di ON di.product_id = ls.product_id AND di.delivery_id = OLD.delivery_id
     SET ls.current_stock = ls.current_stock - di.quantity
   WHERE ls.location_id = OLD.location_id;
END$$

-- Module 3: sale items ------------------------------------------------------------------
CREATE TRIGGER trg_si_ai AFTER INSERT ON sale_items FOR EACH ROW
BEGIN
  DECLARE v_loc INT;
  SELECT location_id INTO v_loc FROM sales WHERE sale_id = NEW.sale_id;
  CALL sp_stock_change(v_loc, NEW.product_id, -NEW.quantity);
END$$

CREATE TRIGGER trg_si_au AFTER UPDATE ON sale_items FOR EACH ROW
BEGIN
  DECLARE v_loc INT;
  IF NEW.sale_id <> OLD.sale_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An item cannot be moved to another sale. Delete and add it again.';
  END IF;
  SELECT location_id INTO v_loc FROM sales WHERE sale_id = NEW.sale_id;
  IF NEW.product_id = OLD.product_id THEN
    IF NEW.quantity <> OLD.quantity THEN
      CALL sp_stock_change(v_loc, NEW.product_id, OLD.quantity - NEW.quantity);
    END IF;
  ELSE
    CALL sp_stock_change(v_loc, OLD.product_id, OLD.quantity);
    CALL sp_stock_change(v_loc, NEW.product_id, -NEW.quantity);
  END IF;
END$$

CREATE TRIGGER trg_si_ad AFTER DELETE ON sale_items FOR EACH ROW
BEGIN
  DECLARE v_loc INT;
  SELECT location_id INTO v_loc FROM sales WHERE sale_id = OLD.sale_id;
  CALL sp_stock_change(v_loc, OLD.product_id, OLD.quantity);
END$$

CREATE TRIGGER trg_sales_bu BEFORE UPDATE ON sales FOR EACH ROW
BEGIN
  IF NEW.location_id <> OLD.location_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The location of a sale cannot be changed.';
  END IF;
END$$

CREATE TRIGGER trg_sales_bd BEFORE DELETE ON sales FOR EACH ROW
BEGIN
  UPDATE location_stock ls JOIN sale_items si ON si.product_id = ls.product_id AND si.sale_id = OLD.sale_id
     SET ls.current_stock = ls.current_stock + si.quantity
   WHERE ls.location_id = OLD.location_id;
END$$

-- Module 3: stock adjustments (damaged / expired / physical count) ----------------------
CREATE TRIGGER trg_adj_bi BEFORE INSERT ON stock_adjustments FOR EACH ROW
BEGIN
  DECLARE v_sys INT;
  SET v_sys = COALESCE((SELECT current_stock FROM location_stock
                         WHERE location_id = NEW.location_id AND product_id = NEW.product_id), 0);
  IF NEW.adjustment_type = 'count' THEN
    IF NEW.physical_count IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Enter the physical count for a stock count.';
    END IF;
    SET NEW.system_count    = v_sys;
    SET NEW.quantity_change = NEW.physical_count - v_sys;          -- variance
    IF NEW.quantity_change = 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The count matches the system balance, so there is no variance to record.';
    END IF;
  ELSE
    SET NEW.system_count    = NULL;
    SET NEW.physical_count  = NULL;
    SET NEW.quantity_change = -ABS(NEW.quantity_change);           -- always a write-off
    IF NEW.quantity_change = 0 THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Enter the number of damaged or expired packs (at least 1).';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_adj_ai AFTER INSERT ON stock_adjustments FOR EACH ROW
BEGIN
  CALL sp_stock_change(NEW.location_id, NEW.product_id, NEW.quantity_change);
END$$

CREATE TRIGGER trg_adj_bu BEFORE UPDATE ON stock_adjustments FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Adjustments cannot be edited. Delete (damaged/expired only) and record it again.';
END$$

CREATE TRIGGER trg_adj_bd BEFORE DELETE ON stock_adjustments FOR EACH ROW
BEGIN
  IF OLD.adjustment_type = 'count' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stock count records cannot be deleted.';
  END IF;
END$$

CREATE TRIGGER trg_adj_ad AFTER DELETE ON stock_adjustments FOR EACH ROW
BEGIN
  CALL sp_stock_change(OLD.location_id, OLD.product_id, -OLD.quantity_change);   -- units go back to stock
END$$

DELIMITER ;

-- ---------------------------------------------------------------------
-- Sample data: REAL IPTB data
--   Products : 10 products taken from the IPTB "Small Pack" stock list
--              (22 Oct 2026). Selling price = list price; cost price =
--              selling price less 30%. Minimum stock levels are proposed
--              values set by the group.
--   Stock    : the quantities in the list are the stock at Wilayah Pantai
--              Timur (Kota Bharu, KTN), loaded as opening stock on
--              22 Oct 2026. Cawangan Pahang (PHG) starts at 0.
--   No deliveries, sales or adjustments are seeded: record them through
--   Modules 2 and 3 so the stock changes are made by the triggers.
-- ---------------------------------------------------------------------
INSERT INTO users (user_id, full_name, email, password_hash, role) VALUES
(1, 'Administrator',       'admin',                  '$2y$10$DgKEecNEDJ8Kg4EwTxsr2up67yNbi9nJYLyQ2mbp6HwdQrFIwsrfi', 'admin'),
(2, 'Staff Pahang (demo)', 'staff.phg@example.com',  '$2y$10$LcYpdtLJOIbKql1ytG8be.7e0V2bDlYGU2844YQKoLFhjZ2X1tXfa', 'staff'),
(3, 'Staff Kelantan (demo)','staff.ktn@example.com', '$2y$10$LcYpdtLJOIbKql1ytG8be.7e0V2bDlYGU2844YQKoLFhjZ2X1tXfa', 'staff');

INSERT INTO locations (location_id, location_code, location_name, address, state) VALUES
(1, 'PHG', 'Pejabat IPTB Sdn Bhd Cawangan Pahang',
    'B34-G, Jalan IM 7/5, Bandar Indera Mahkota, 25200 Kuantan, Pahang', 'Pahang'),
(2, 'KTN', 'Pejabat IPTB Sdn Bhd Wilayah Pantai Timur',
    'Lot PT 1023, Ground Floor, Jalan Sri Cemerlang 1/27, 15300 Kota Bharu, Kelantan', 'Kelantan');

INSERT INTO staff_locations (user_id, location_id, is_default, assigned_by) VALUES
(2, 1, 1, 1),      -- staff.phg opens the Pahang dashboard after login
(3, 2, 1, 1);      -- staff.ktn opens the Pantai Timur dashboard

INSERT INTO products (sku, brand, product_name, viscosity_grade, product_type, pack_size, cost_price, selling_price, min_stock, image_path) VALUES
('LUB-001','Grantt','Stellar 5000 API SN','10W-40','Engine oil',4,66.50,95.00,10,NULL),
('LUB-002','Grantt','Stellar 1000 API SN','15W-50','Engine oil',4,59.50,85.00,10,'images/LUB-002.jpg'),
('LUB-003','Grantt','Stellar 1000 API SN','10W-30','Engine oil',4,58.63,83.75,10,'images/LUB-003.jpg'),
('LUB-004','Grantt','Stellar 1000','20W-50','Engine oil',4,54.25,77.50,10,'images/LUB-004.jpg'),
('LUB-005','BuraqLube','2T JASO FB API TB','2T','Motorcycle oil',0.5,4.55,6.50,50,'images/LUB-005.jpg'),
('LUB-006','BuraqLube','API SL/CF','20W-50','Engine oil',0.5,5.25,7.50,24,'images/LUB-006.jpg'),
('LUB-007','BuraqLube','4T API SF','SAE 40','Motorcycle oil',0.5,4.76,6.80,100,'images/LUB-007.jpg'),
('LUB-008','Grantt','Gear Oil GL4','SAE 90','Gear oil',4,53.38,76.25,6,NULL),
('LUB-009','Grantt','Gear Oil GL4','SAE 140','Gear oil',4,55.13,78.75,6,NULL),
('LUB-010','Grantt','Quadra 4T SJ/CD','20W-40','Motorcycle oil',1,14.00,20.00,24,NULL);

INSERT INTO location_stock (location_id, product_id, opening_stock, opening_date, current_stock) VALUES
(2, 1, 21, '2026-10-22', 21),
(2, 2, 6, '2026-10-22', 6),
(2, 3, 8, '2026-10-22', 8),
(2, 4, 12, '2026-10-22', 12),
(2, 5, 283, '2026-10-22', 283),
(2, 6, 38, '2026-10-22', 38),
(2, 7, 1130, '2026-10-22', 1130),
(2, 8, 18, '2026-10-22', 18),
(2, 9, 36, '2026-10-22', 36),
(2, 10, 158, '2026-10-22', 158),
(1, 1, 0, '2026-10-22', 0),
(1, 2, 0, '2026-10-22', 0),
(1, 3, 0, '2026-10-22', 0),
(1, 4, 0, '2026-10-22', 0),
(1, 5, 0, '2026-10-22', 0),
(1, 6, 0, '2026-10-22', 0),
(1, 7, 0, '2026-10-22', 0),
(1, 8, 0, '2026-10-22', 0),
(1, 9, 0, '2026-10-22', 0),
(1, 10, 0, '2026-10-22', 0);

INSERT INTO suppliers (supplier_name) VALUES
('Supplier A Sdn Bhd'),
('Supplier B Trading'),
('Supplier C Lubricants');

INSERT INTO alert_rules (rule_id, mode, buffer_pct, window_days, reorder_multiplier) VALUES (1, 'min', 20, 30, 2);
INSERT INTO product_alert_settings (product_id) VALUES
(1),
(2),
(3),
(4),
(5),
(6),
(7),
(8),
(9),
(10);

-- ---------------------------------------------------------------------
-- Views
-- ---------------------------------------------------------------------

-- Where to send a user after login (staff: default location; admin: NULL = all locations)
CREATE VIEW v_user_home AS
SELECT u.user_id, u.email, u.full_name, u.role,
       h.location_id   AS home_location_id,
       l.location_code AS home_location_code,
       l.location_name AS home_location_name
FROM users u
LEFT JOIN (SELECT u2.user_id,
                  (SELECT sl.location_id
                     FROM staff_locations sl
                     JOIN locations l2 ON l2.location_id = sl.location_id AND l2.is_active = 1
                    WHERE sl.user_id = u2.user_id
                    ORDER BY sl.is_default DESC, sl.assigned_at, sl.location_id
                    LIMIT 1) AS location_id
             FROM users u2) h ON h.user_id = u.user_id
LEFT JOIN locations l ON l.location_id = h.location_id
WHERE u.is_active = 1;

-- Every active product at every active location, with stock status and alert level
CREATE VIEW v_location_stock AS
SELECT x.*,
       CASE WHEN x.current_stock = 0 THEN 'Out of stock'
            WHEN x.alert_enabled = 1 AND x.current_stock <= x.trigger_level THEN 'Low stock'
            ELSE 'In stock' END AS stock_status,
       x.current_stock * x.pack_size  AS total_litres,
       x.current_stock * x.cost_price AS stock_value
FROM (
  SELECT l.location_id, l.location_code, l.location_name,
         p.product_id, p.sku, p.brand, p.product_name, p.viscosity_grade, p.product_type,
         p.pack_size, p.cost_price, p.selling_price, p.min_stock, p.image_path,
         COALESCE(ls.current_stock, 0)                AS current_stock,
         COALESCE(pa.custom_level, p.min_stock)       AS alert_level,
         CASE WHEN r.mode = 'buffer'
              THEN CEIL(COALESCE(pa.custom_level, p.min_stock) * (1 + r.buffer_pct / 100))
              ELSE COALESCE(pa.custom_level, p.min_stock) END AS trigger_level,
         COALESCE(pa.is_enabled, 1)                   AS alert_enabled
  FROM locations l
  CROSS JOIN products p
  JOIN alert_rules r ON r.rule_id = 1
  LEFT JOIN location_stock ls ON ls.location_id = l.location_id AND ls.product_id = p.product_id
  LEFT JOIN product_alert_settings pa ON pa.product_id = p.product_id
  WHERE l.is_active = 1 AND p.is_active = 1
) x;

-- Staff / admin dashboard cards for each location
CREATE VIEW v_location_dashboard AS
SELECT location_id, location_code, location_name,
       COUNT(*)                                        AS products,
       SUM(current_stock)                              AS units_in_stock,
       SUM(total_litres)                               AS litres_in_stock,
       SUM(stock_value)                                AS stock_value,
       SUM(stock_status = 'Low stock')                 AS low_stock,
       SUM(stock_status = 'Out of stock')              AS out_of_stock
FROM v_location_stock
GROUP BY location_id, location_code, location_name;

-- Module 4: products that need re-ordering, with suggested quantity
CREATE VIEW v_low_stock_alerts AS
SELECT v.location_id, v.location_code, v.sku, v.brand, v.product_name, v.viscosity_grade,
       v.current_stock, v.alert_level, v.trigger_level, v.stock_status,
       (SELECT COALESCE(SUM(si.quantity), 0) / r.window_days
          FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id
         WHERE s.location_id = v.location_id AND si.product_id = v.product_id
           AND s.sale_date > DATE_SUB(CURDATE(), INTERVAL r.window_days DAY)) AS avg_sold_per_day,
       GREATEST(CEIL(v.alert_level * r.reorder_multiplier) - v.current_stock, 0) AS suggested_reorder_qty
FROM v_location_stock v
JOIN alert_rules r ON r.rule_id = 1
WHERE v.alert_enabled = 1 AND v.stock_status IN ('Low stock', 'Out of stock');

-- Module 2
CREATE VIEW v_delivery_summary AS
SELECT d.delivery_id, CONCAT('DEL-', LPAD(d.delivery_id, 3, '0')) AS delivery_code,
       d.location_id, l.location_code, d.delivery_date, s.supplier_name, d.invoice_no,
       COUNT(di.delivery_item_id)                    AS product_count,
       COALESCE(SUM(di.quantity), 0)                 AS total_units,
       COALESCE(SUM(di.quantity * di.unit_cost), 0)  AS total_cost
FROM deliveries d
JOIN locations l ON l.location_id = d.location_id
JOIN suppliers s ON s.supplier_id = d.supplier_id
LEFT JOIN delivery_items di ON di.delivery_id = d.delivery_id
GROUP BY d.delivery_id, d.location_id, l.location_code, d.delivery_date, s.supplier_name, d.invoice_no;

-- Module 3
CREATE VIEW v_sale_summary AS
SELECT s.sale_id, CONCAT('SAL-', LPAD(s.sale_id, 3, '0')) AS sale_code,
       s.location_id, l.location_code, s.sale_date,
       COUNT(si.sale_item_id)                       AS product_count,
       COALESCE(SUM(si.quantity), 0)                AS total_units,
       COALESCE(SUM(si.quantity * si.unit_price), 0) AS total_value
FROM sales s
JOIN locations l ON l.location_id = s.location_id
LEFT JOIN sale_items si ON si.sale_id = s.sale_id
GROUP BY s.sale_id, s.location_id, l.location_code, s.sale_date;

-- Module 4: every stock movement (balance on any date = SUM of quantity up to that date)
CREATE VIEW v_stock_ledger AS
SELECT location_id, product_id, opening_date AS movement_date, 'opening' AS movement_type,
       opening_stock AS quantity, 'OPENING' AS reference
FROM location_stock WHERE opening_stock <> 0
UNION ALL
SELECT d.location_id, di.product_id, d.delivery_date, 'delivery', di.quantity,
       CONCAT('DEL-', LPAD(d.delivery_id, 3, '0'))
FROM delivery_items di JOIN deliveries d ON d.delivery_id = di.delivery_id
UNION ALL
SELECT s.location_id, si.product_id, s.sale_date, 'sale', -si.quantity,
       CONCAT('SAL-', LPAD(s.sale_id, 3, '0'))
FROM sale_items si JOIN sales s ON s.sale_id = si.sale_id
UNION ALL
SELECT a.location_id, a.product_id, a.adjustment_date, a.adjustment_type, a.quantity_change,
       CONCAT('ADJ-', LPAD(a.adjustment_id, 3, '0'))
FROM stock_adjustments a;

-- ---------------------------------------------------------------------
-- Quick checks after import
-- ---------------------------------------------------------------------
-- SELECT * FROM v_user_home;                                   -- where each user lands after login
-- SELECT * FROM v_location_dashboard;
-- SELECT * FROM v_location_stock WHERE location_code = 'KTN';
-- SELECT * FROM v_low_stock_alerts;
-- -- ledger and current stock must agree (no rows expected):
-- SELECT ls.location_id, ls.product_id FROM location_stock ls
--  LEFT JOIN (SELECT location_id, product_id, SUM(quantity) q FROM v_stock_ledger GROUP BY 1, 2) g
--    ON g.location_id = ls.location_id AND g.product_id = ls.product_id
--  WHERE ls.current_stock <> COALESCE(g.q, 0);

-- ---------------------------------------------------------------------
-- Statements for the admin page "Staff and locations" (PHP phase)
-- ---------------------------------------------------------------------
-- Add staff (password_hash() is done in PHP):
--   INSERT INTO users (full_name, email, password_hash, role) VALUES (?, ?, ?, 'staff');
--   INSERT INTO staff_locations (user_id, location_id, is_default, assigned_by) VALUES (LAST_INSERT_ID(), ?, 1, ?);
-- Edit staff (leave the password blank to keep it):
--   UPDATE users SET full_name = ?, email = ? WHERE user_id = ?;
--   UPDATE users SET password_hash = ? WHERE user_id = ?;                    -- only when a new password is typed
--   UPDATE staff_locations SET location_id = ?, assigned_by = ?, assigned_at = NOW() WHERE user_id = ? AND is_default = 1;
-- Remove staff (keeps their deliveries and sales; they can no longer log in):
--   UPDATE users SET is_active = 0 WHERE user_id = ? AND role = 'staff';
-- Staff per location (the cards at the top of the page):
--   SELECT l.location_id, l.location_name, COUNT(u.user_id) AS staff
--     FROM locations l LEFT JOIN staff_locations sl ON sl.location_id = l.location_id
--     LEFT JOIN users u ON u.user_id = sl.user_id AND u.is_active = 1
--    GROUP BY l.location_id, l.location_name;
-- Login: SELECT user_id, password_hash, role FROM users WHERE email = ? AND is_active = 1;
