-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3307
-- Generation Time: Nov 04, 2026 at 12:09 PM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `iptb_lsms`
--

-- --------------------------------------------------------

--
-- Table structure for table `alert_rules`
--

CREATE TABLE `alert_rules` (
  `rule_id` tinyint(4) NOT NULL DEFAULT 1,
  `mode` enum('min','buffer') NOT NULL DEFAULT 'min',
  `buffer_pct` int(11) NOT NULL DEFAULT 20,
  `window_days` int(11) NOT NULL DEFAULT 30,
  `reorder_multiplier` decimal(3,1) NOT NULL DEFAULT 2.0,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `alert_rules`
--

INSERT INTO `alert_rules` (`rule_id`, `mode`, `buffer_pct`, `window_days`, `reorder_multiplier`, `updated_by`, `updated_at`) VALUES
(1, 'min', 20, 30, 2.0, NULL, '2026-11-04 08:06:29');

-- --------------------------------------------------------

--
-- Table structure for table `deliveries`
--

CREATE TABLE `deliveries` (
  `delivery_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `invoice_no` varchar(30) NOT NULL,
  `delivery_date` date NOT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `deliveries`
--

INSERT INTO `deliveries` (`delivery_id`, `location_id`, `supplier_id`, `invoice_no`, `delivery_date`, `remarks`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'INV-A-20931', '2026-10-23', NULL, 2, '2026-11-04 08:06:29', '2026-11-04 08:06:29'),
(2, 1, 2, 'INV-B-1187', '2026-10-25', NULL, 2, '2026-11-04 08:06:29', '2026-11-04 08:06:29'),
(3, 1, 1, 'INV-A-21044', '2026-10-27', NULL, 2, '2026-11-04 08:06:29', '2026-11-04 08:06:29'),
(4, 1, 3, 'INV-C-0552', '2026-10-29', NULL, 2, '2026-11-04 08:06:29', '2026-11-04 08:06:29'),
(5, 2, 2, 'INV-B-1201', '2026-10-24', NULL, 3, '2026-11-04 08:06:29', '2026-11-04 08:06:29'),
(6, 2, 3, 'INV-C-0561', '2026-10-30', NULL, 3, '2026-11-04 08:06:29', '2026-11-04 08:06:29');

--
-- Triggers `deliveries`
--
DELIMITER $$
CREATE TRIGGER `trg_deliveries_bd` BEFORE DELETE ON `deliveries` FOR EACH ROW BEGIN
  IF EXISTS (SELECT 1 FROM delivery_items di
               LEFT JOIN location_stock ls ON ls.location_id = OLD.location_id AND ls.product_id = di.product_id
              WHERE di.delivery_id = OLD.delivery_id AND COALESCE(ls.current_stock, 0) < di.quantity) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Cannot cancel delivery: stock would drop below 0 (some units were already sold).';
  END IF;
  UPDATE location_stock ls JOIN delivery_items di ON di.product_id = ls.product_id AND di.delivery_id = OLD.delivery_id
     SET ls.current_stock = ls.current_stock - di.quantity
   WHERE ls.location_id = OLD.location_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_deliveries_bu` BEFORE UPDATE ON `deliveries` FOR EACH ROW BEGIN
  IF NEW.location_id <> OLD.location_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The location of a delivery cannot be changed.';
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `delivery_items`
--

CREATE TABLE `delivery_items` (
  `delivery_item_id` int(11) NOT NULL,
  `delivery_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_cost` decimal(10,2) NOT NULL
) ;

--
-- Triggers `delivery_items`
--
DELIMITER $$
CREATE TRIGGER `trg_di_ad` AFTER DELETE ON `delivery_items` FOR EACH ROW BEGIN
  DECLARE v_loc INT;
  SELECT location_id INTO v_loc FROM deliveries WHERE delivery_id = OLD.delivery_id;
  CALL sp_stock_change(v_loc, OLD.product_id, -OLD.quantity);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_di_ai` AFTER INSERT ON `delivery_items` FOR EACH ROW BEGIN
  DECLARE v_loc INT;
  SELECT location_id INTO v_loc FROM deliveries WHERE delivery_id = NEW.delivery_id;
  CALL sp_stock_change(v_loc, NEW.product_id, NEW.quantity);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_di_au` AFTER UPDATE ON `delivery_items` FOR EACH ROW BEGIN
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
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `locations`
--

CREATE TABLE `locations` (
  `location_id` int(11) NOT NULL,
  `location_code` varchar(10) NOT NULL,
  `location_name` varchar(100) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `state` varchar(50) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `locations`
--

INSERT INTO `locations` (`location_id`, `location_code`, `location_name`, `address`, `state`, `phone`, `is_active`, `created_at`) VALUES
(1, 'PHG', 'Pejabat IPTB Sdn Bhd Cawangan Pahang', 'B34-G, Jalan IM 7/5, Bandar Indera Mahkota, 25200 Kuantan, Pahang', 'Pahang', NULL, 1, '2026-11-04 08:06:29'),
(2, 'KTN', 'Pejabat IPTB Sdn Bhd Wilayah Pantai Timur', 'Lot PT 1023, Ground Floor, Jalan Sri Cemerlang 1/27, 15300 Kota Bharu, Kelantan', 'Kelantan', NULL, 1, '2026-11-04 08:06:29');

-- --------------------------------------------------------

--
-- Table structure for table `location_stock`
--

CREATE TABLE `location_stock` (
  `location_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `opening_stock` int(11) NOT NULL DEFAULT 0,
  `opening_date` date NOT NULL,
  `current_stock` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `location_stock`
--

INSERT INTO `location_stock` (`location_id`, `product_id`, `opening_stock`, `opening_date`, `current_stock`, `updated_at`) VALUES
(1, 1, 0, '2026-10-22', 0, '2026-11-04 08:06:29'),
(1, 2, 0, '2026-10-22', 0, '2026-11-04 08:06:29'),
(1, 3, 0, '2026-10-22', 0, '2026-11-04 08:06:29'),
(1, 4, 0, '2026-10-22', 0, '2026-11-04 08:06:29'),
(1, 5, 0, '2026-10-22', 0, '2026-11-04 08:06:29'),
(1, 6, 0, '2026-10-22', 0, '2026-11-04 08:06:29'),
(1, 7, 0, '2026-10-22', 0, '2026-11-04 08:06:29'),
(1, 8, 0, '2026-10-22', 0, '2026-11-04 08:06:29'),
(1, 9, 0, '2026-10-22', 0, '2026-11-04 08:06:29'),
(1, 10, 0, '2026-10-22', 0, '2026-11-04 08:06:29'),
(2, 1, 21, '2026-10-22', 21, '2026-11-04 08:06:29'),
(2, 2, 6, '2026-10-22', 6, '2026-11-04 08:06:29'),
(2, 3, 8, '2026-10-22', 8, '2026-11-04 08:06:29'),
(2, 4, 12, '2026-10-22', 12, '2026-11-04 08:06:29'),
(2, 5, 283, '2026-10-22', 283, '2026-11-04 08:06:29'),
(2, 6, 38, '2026-10-22', 38, '2026-11-04 08:06:29'),
(2, 7, 1130, '2026-10-22', 1130, '2026-11-04 08:06:29'),
(2, 8, 18, '2026-10-22', 18, '2026-11-04 08:06:29'),
(2, 9, 36, '2026-10-22', 36, '2026-11-04 08:06:29'),
(2, 10, 158, '2026-10-22', 158, '2026-11-04 08:06:29');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `product_id` int(11) NOT NULL,
  `sku` varchar(10) NOT NULL,
  `brand` varchar(50) NOT NULL,
  `product_name` varchar(100) NOT NULL,
  `viscosity_grade` varchar(20) NOT NULL,
  `product_type` enum('Engine oil','Gear oil','Motorcycle oil','Grease','Other') NOT NULL,
  `pack_size` decimal(6,2) NOT NULL,
  `cost_price` decimal(10,2) NOT NULL,
  `selling_price` decimal(10,2) NOT NULL,
  `min_stock` int(11) NOT NULL DEFAULT 0,
  `image_path` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`product_id`, `sku`, `brand`, `product_name`, `viscosity_grade`, `product_type`, `pack_size`, `cost_price`, `selling_price`, `min_stock`, `image_path`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'LUB-001', 'Grantt', 'Stellar 5000 API SN', '10W-40', 'Engine oil', 4.00, 66.50, 95.00, 10, 'images/LUB-001.png', 1, '2026-11-04 08:06:29', '2026-11-04 08:07:12'),
(2, 'LUB-002', 'Grantt', 'Stellar 1000 API SN', '15W-50', 'Engine oil', 4.00, 59.50, 85.00, 10, 'images/LUB-002.jpg', 1, '2026-11-04 08:06:29', '2026-11-04 08:06:29'),
(3, 'LUB-003', 'Grantt', 'Stellar 1000 API SN', '10W-30', 'Engine oil', 4.00, 58.63, 83.75, 10, 'images/LUB-003.jpg', 1, '2026-11-04 08:06:29', '2026-11-04 08:06:29'),
(4, 'LUB-004', 'Grantt', 'Stellar 1000', '20W-50', 'Engine oil', 4.00, 54.25, 77.50, 10, 'images/LUB-004.jpg', 1, '2026-11-04 08:06:29', '2026-11-04 08:06:29'),
(5, 'LUB-005', 'BuraqLube', '2T JASO FB API TB', '2T', 'Motorcycle oil', 0.50, 4.55, 6.50, 50, 'images/LUB-005.jpg', 1, '2026-11-04 08:06:29', '2026-11-04 08:06:29'),
(6, 'LUB-006', 'BuraqLube', 'API SL/CF', '20W-50', 'Engine oil', 0.50, 5.25, 7.50, 24, 'images/LUB-006.jpg', 1, '2026-11-04 08:06:29', '2026-11-04 08:06:29'),
(7, 'LUB-007', 'BuraqLube', '4T API SF', 'SAE 40', 'Motorcycle oil', 0.50, 4.76, 6.80, 100, 'images/LUB-007.jpg', 1, '2026-11-04 08:06:29', '2026-11-04 08:06:29'),
(8, 'LUB-008', 'Grantt', 'Gear Oil GL4', 'SAE 90', 'Gear oil', 4.00, 53.38, 76.25, 6, 'images/LUB-008.png', 1, '2026-11-04 08:06:29', '2026-11-04 08:08:38'),
(9, 'LUB-009', 'Grantt', 'Gear Oil GL4', 'SAE 140', 'Gear oil', 4.00, 55.13, 78.75, 6, 'images/LUB-009.png', 1, '2026-11-04 08:06:29', '2026-11-04 08:09:00'),
(10, 'LUB-010', 'Grantt', 'Quadra 4T SJ/CD', '20W-40', 'Motorcycle oil', 1.00, 14.00, 20.00, 24, 'images/LUB-010.jpg', 1, '2026-11-04 08:06:29', '2026-11-04 08:14:23');

-- --------------------------------------------------------

--
-- Table structure for table `product_alert_settings`
--

CREATE TABLE `product_alert_settings` (
  `product_id` int(11) NOT NULL,
  `custom_level` int(11) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

--
-- Dumping data for table `product_alert_settings`
--

INSERT INTO `product_alert_settings` (`product_id`, `custom_level`, `is_enabled`, `updated_at`) VALUES
(1, NULL, 1, '2026-11-04 08:06:29'),
(2, NULL, 1, '2026-11-04 08:06:29'),
(3, NULL, 1, '2026-11-04 08:06:29'),
(4, NULL, 1, '2026-11-04 08:06:29'),
(5, NULL, 1, '2026-11-04 08:06:29'),
(6, NULL, 1, '2026-11-04 08:06:29'),
(7, NULL, 1, '2026-11-04 08:06:29'),
(8, NULL, 1, '2026-11-04 08:06:29'),
(9, NULL, 1, '2026-11-04 08:06:29'),
(10, NULL, 1, '2026-11-04 08:06:29');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `po_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `order_date` date NOT NULL,
  `status` enum('ordered','received','cancelled') NOT NULL DEFAULT 'ordered',
  `delivery_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `purchase_orders`
--

INSERT INTO `purchase_orders` (`po_id`, `location_id`, `supplier_id`, `order_date`, `status`, `delivery_id`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 1, 3, '2026-10-26', 'received', 4, 2, '2026-11-04 08:06:29', '2026-11-04 08:06:29'),
(2, 1, 1, '2026-10-30', 'ordered', NULL, 2, '2026-11-04 08:06:29', '2026-11-04 08:06:29'),
(3, 2, 2, '2026-10-31', 'ordered', NULL, 3, '2026-11-04 08:06:29', '2026-11-04 08:06:29');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_order_items`
--

CREATE TABLE `purchase_order_items` (
  `po_item_id` int(11) NOT NULL,
  `po_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_cost` decimal(10,2) NOT NULL
) ;

--
-- Dumping data for table `purchase_order_items`
--

INSERT INTO `purchase_order_items` (`po_item_id`, `po_id`, `product_id`, `quantity`, `unit_cost`) VALUES
(1, 1, 8, 14, 53.38),
(2, 1, 10, 30, 14.00),
(3, 2, 2, 10, 59.50),
(4, 2, 4, 12, 54.25),
(5, 3, 8, 6, 53.38),
(6, 3, 9, 4, 55.13);

-- --------------------------------------------------------

--
-- Table structure for table `reports`
--

CREATE TABLE `reports` (
  `report_id` int(11) NOT NULL,
  `report_type` enum('daily','monthly','range','product') NOT NULL,
  `title` varchar(100) NOT NULL,
  `location_id` int(11) DEFAULT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `brand_filter` varchar(50) DEFAULT NULL,
  `type_filter` varchar(30) DEFAULT NULL,
  `status_filter` enum('flagged','low','out','ok') DEFAULT NULL,
  `rule_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`rule_snapshot`)),
  `result_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`result_snapshot`)),
  `generated_by` int(11) DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `sale_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  `sale_date` date NOT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `sales`
--
DELIMITER $$
CREATE TRIGGER `trg_sales_bd` BEFORE DELETE ON `sales` FOR EACH ROW BEGIN
  UPDATE location_stock ls JOIN sale_items si ON si.product_id = ls.product_id AND si.sale_id = OLD.sale_id
     SET ls.current_stock = ls.current_stock + si.quantity
   WHERE ls.location_id = OLD.location_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_sales_bu` BEFORE UPDATE ON `sales` FOR EACH ROW BEGIN
  IF NEW.location_id <> OLD.location_id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The location of a sale cannot be changed.';
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `sale_items`
--

CREATE TABLE `sale_items` (
  `sale_item_id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL
) ;

--
-- Triggers `sale_items`
--
DELIMITER $$
CREATE TRIGGER `trg_si_ad` AFTER DELETE ON `sale_items` FOR EACH ROW BEGIN
  DECLARE v_loc INT;
  SELECT location_id INTO v_loc FROM sales WHERE sale_id = OLD.sale_id;
  CALL sp_stock_change(v_loc, OLD.product_id, OLD.quantity);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_si_ai` AFTER INSERT ON `sale_items` FOR EACH ROW BEGIN
  DECLARE v_loc INT;
  SELECT location_id INTO v_loc FROM sales WHERE sale_id = NEW.sale_id;
  CALL sp_stock_change(v_loc, NEW.product_id, -NEW.quantity);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_si_au` AFTER UPDATE ON `sale_items` FOR EACH ROW BEGIN
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
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `staff_locations`
--

CREATE TABLE `staff_locations` (
  `staff_location_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `default_user_id` int(11) GENERATED ALWAYS AS (if(`is_default` = 1,`user_id`,NULL)) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `staff_locations`
--

INSERT INTO `staff_locations` (`staff_location_id`, `user_id`, `location_id`, `is_default`, `assigned_by`, `assigned_at`) VALUES
(1, 2, 1, 1, 1, '2026-11-04 08:06:29'),
(2, 3, 2, 1, 1, '2026-11-04 08:06:29');

--
-- Triggers `staff_locations`
--
DELIMITER $$
CREATE TRIGGER `trg_sl_bi` BEFORE INSERT ON `staff_locations` FOR EACH ROW BEGIN
  DECLARE v_role VARCHAR(10);
  SELECT role INTO v_role FROM users WHERE user_id = NEW.user_id;
  IF v_role IS NULL OR v_role <> 'staff' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only staff accounts are assigned to locations (admin can open every location).';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM staff_locations WHERE user_id = NEW.user_id) THEN
    SET NEW.is_default = 1;
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `stock_adjustments`
--

CREATE TABLE `stock_adjustments` (
  `adjustment_id` int(11) NOT NULL,
  `location_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `adjustment_date` date NOT NULL,
  `adjustment_type` enum('damaged','expired','count') NOT NULL,
  `system_count` int(11) DEFAULT NULL,
  `physical_count` int(11) DEFAULT NULL,
  `quantity_change` int(11) NOT NULL DEFAULT 0,
  `note` varchar(255) NOT NULL,
  `adjusted_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

--
-- Triggers `stock_adjustments`
--
DELIMITER $$
CREATE TRIGGER `trg_adj_ad` AFTER DELETE ON `stock_adjustments` FOR EACH ROW BEGIN
  CALL sp_stock_change(OLD.location_id, OLD.product_id, -OLD.quantity_change);   -- units go back to stock
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_adj_ai` AFTER INSERT ON `stock_adjustments` FOR EACH ROW BEGIN
  CALL sp_stock_change(NEW.location_id, NEW.product_id, NEW.quantity_change);
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_adj_bd` BEFORE DELETE ON `stock_adjustments` FOR EACH ROW BEGIN
  IF OLD.adjustment_type = 'count' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Stock count records cannot be deleted.';
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_adj_bi` BEFORE INSERT ON `stock_adjustments` FOR EACH ROW BEGIN
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
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_adj_bu` BEFORE UPDATE ON `stock_adjustments` FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Adjustments cannot be edited. Delete (damaged/expired only) and record it again.';
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `supplier_id` int(11) NOT NULL,
  `supplier_name` varchar(100) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`supplier_id`, `supplier_name`, `contact_person`, `phone`, `email`, `address`, `is_active`) VALUES
(1, 'Supplier A Sdn Bhd', NULL, NULL, NULL, NULL, 1),
(2, 'Supplier B Trading', NULL, NULL, NULL, NULL, 1),
(3, 'Supplier C Lubricants', NULL, NULL, NULL, NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','staff') NOT NULL DEFAULT 'staff',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `full_name`, `email`, `password_hash`, `role`, `is_active`, `last_login_at`, `created_at`) VALUES
(1, 'Administrator', 'admin', '$2y$10$DgKEecNEDJ8Kg4EwTxsr2up67yNbi9nJYLyQ2mbp6HwdQrFIwsrfi', 'admin', 1, NULL, '2026-11-04 08:06:29'),
(2, 'Staff Pahang (demo)', 'staff.phg@example.com', '$2y$10$LcYpdtLJOIbKql1ytG8be.7e0V2bDlYGU2844YQKoLFhjZ2X1tXfa', 'staff', 1, NULL, '2026-11-04 08:06:29'),
(3, 'Staff Kelantan (demo)', 'staff.ktn@example.com', '$2y$10$LcYpdtLJOIbKql1ytG8be.7e0V2bDlYGU2844YQKoLFhjZ2X1tXfa', 'staff', 1, NULL, '2026-11-04 08:06:29');

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_location_dashboard`
-- (See below for the actual view)
--
CREATE TABLE `v_location_dashboard` (
`location_id` int(11)
,`location_code` varchar(10)
,`location_name` varchar(100)
,`products` bigint(21)
,`units_in_stock` decimal(32,0)
,`litres_in_stock` decimal(38,2)
,`stock_value` decimal(42,2)
,`low_stock` decimal(23,0)
,`out_of_stock` decimal(23,0)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_location_stock`
-- (See below for the actual view)
--
CREATE TABLE `v_location_stock` (
`location_id` int(11)
,`location_code` varchar(10)
,`location_name` varchar(100)
,`product_id` int(11)
,`sku` varchar(10)
,`brand` varchar(50)
,`product_name` varchar(100)
,`viscosity_grade` varchar(20)
,`product_type` enum('Engine oil','Gear oil','Motorcycle oil','Grease','Other')
,`pack_size` decimal(6,2)
,`cost_price` decimal(10,2)
,`selling_price` decimal(10,2)
,`min_stock` int(11)
,`image_path` varchar(255)
,`current_stock` int(11)
,`alert_level` int(11)
,`trigger_level` decimal(22,0)
,`alert_enabled` int(4)
,`stock_status` varchar(12)
,`total_litres` decimal(16,2)
,`stock_value` decimal(20,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_user_home`
-- (See below for the actual view)
--
CREATE TABLE `v_user_home` (
`user_id` int(11)
,`email` varchar(100)
,`full_name` varchar(100)
,`role` enum('admin','staff')
,`home_location_id` int(11)
,`home_location_code` varchar(10)
,`home_location_name` varchar(100)
);

-- --------------------------------------------------------

--
-- Structure for view `v_location_dashboard`
--
DROP TABLE IF EXISTS `v_location_dashboard`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_location_dashboard`  AS SELECT `v_location_stock`.`location_id` AS `location_id`, `v_location_stock`.`location_code` AS `location_code`, `v_location_stock`.`location_name` AS `location_name`, count(0) AS `products`, sum(`v_location_stock`.`current_stock`) AS `units_in_stock`, sum(`v_location_stock`.`total_litres`) AS `litres_in_stock`, sum(`v_location_stock`.`stock_value`) AS `stock_value`, sum(`v_location_stock`.`stock_status` = 'Low stock') AS `low_stock`, sum(`v_location_stock`.`stock_status` = 'Out of stock') AS `out_of_stock` FROM `v_location_stock` GROUP BY `v_location_stock`.`location_id`, `v_location_stock`.`location_code`, `v_location_stock`.`location_name` ;

-- --------------------------------------------------------

--
-- Structure for view `v_location_stock`
--
DROP TABLE IF EXISTS `v_location_stock`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_location_stock`  AS SELECT `x`.`location_id` AS `location_id`, `x`.`location_code` AS `location_code`, `x`.`location_name` AS `location_name`, `x`.`product_id` AS `product_id`, `x`.`sku` AS `sku`, `x`.`brand` AS `brand`, `x`.`product_name` AS `product_name`, `x`.`viscosity_grade` AS `viscosity_grade`, `x`.`product_type` AS `product_type`, `x`.`pack_size` AS `pack_size`, `x`.`cost_price` AS `cost_price`, `x`.`selling_price` AS `selling_price`, `x`.`min_stock` AS `min_stock`, `x`.`image_path` AS `image_path`, `x`.`current_stock` AS `current_stock`, `x`.`alert_level` AS `alert_level`, `x`.`trigger_level` AS `trigger_level`, `x`.`alert_enabled` AS `alert_enabled`, CASE WHEN `x`.`current_stock` = 0 THEN 'Out of stock' WHEN `x`.`alert_enabled` = 1 AND `x`.`current_stock` <= `x`.`trigger_level` THEN 'Low stock' ELSE 'In stock' END AS `stock_status`, `x`.`current_stock`* `x`.`pack_size` AS `total_litres`, `x`.`current_stock`* `x`.`cost_price` AS `stock_value` FROM (select `l`.`location_id` AS `location_id`,`l`.`location_code` AS `location_code`,`l`.`location_name` AS `location_name`,`p`.`product_id` AS `product_id`,`p`.`sku` AS `sku`,`p`.`brand` AS `brand`,`p`.`product_name` AS `product_name`,`p`.`viscosity_grade` AS `viscosity_grade`,`p`.`product_type` AS `product_type`,`p`.`pack_size` AS `pack_size`,`p`.`cost_price` AS `cost_price`,`p`.`selling_price` AS `selling_price`,`p`.`min_stock` AS `min_stock`,`p`.`image_path` AS `image_path`,coalesce(`ls`.`current_stock`,0) AS `current_stock`,coalesce(`pa`.`custom_level`,`p`.`min_stock`) AS `alert_level`,case when `r`.`mode` = 'buffer' then ceiling(coalesce(`pa`.`custom_level`,`p`.`min_stock`) * (1 + `r`.`buffer_pct` / 100)) else coalesce(`pa`.`custom_level`,`p`.`min_stock`) end AS `trigger_level`,coalesce(`pa`.`is_enabled`,1) AS `alert_enabled` from ((((`locations` `l` join `products` `p`) join `alert_rules` `r` on(`r`.`rule_id` = 1)) left join `location_stock` `ls` on(`ls`.`location_id` = `l`.`location_id` and `ls`.`product_id` = `p`.`product_id`)) left join `product_alert_settings` `pa` on(`pa`.`product_id` = `p`.`product_id`)) where `l`.`is_active` = 1 and `p`.`is_active` = 1) AS `x` ;

-- --------------------------------------------------------

--
-- Structure for view `v_user_home`
--
DROP TABLE IF EXISTS `v_user_home`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_user_home`  AS SELECT `u`.`user_id` AS `user_id`, `u`.`email` AS `email`, `u`.`full_name` AS `full_name`, `u`.`role` AS `role`, `h`.`location_id` AS `home_location_id`, `l`.`location_code` AS `home_location_code`, `l`.`location_name` AS `home_location_name` FROM ((`users` `u` left join (select `u2`.`user_id` AS `user_id`,(select `sl`.`location_id` from (`staff_locations` `sl` join `locations` `l2` on(`l2`.`location_id` = `sl`.`location_id` and `l2`.`is_active` = 1)) where `sl`.`user_id` = `u2`.`user_id` order by `sl`.`is_default` desc,`sl`.`assigned_at`,`sl`.`location_id` limit 1) AS `location_id` from `users` `u2`) `h` on(`h`.`user_id` = `u`.`user_id`)) left join `locations` `l` on(`l`.`location_id` = `h`.`location_id`)) WHERE `u`.`is_active` = 1 ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `alert_rules`
--
ALTER TABLE `alert_rules`
  ADD PRIMARY KEY (`rule_id`),
  ADD KEY `fk_rule_user` (`updated_by`);

--
-- Indexes for table `deliveries`
--
ALTER TABLE `deliveries`
  ADD PRIMARY KEY (`delivery_id`),
  ADD UNIQUE KEY `invoice_no` (`invoice_no`),
  ADD KEY `fk_del_location` (`location_id`),
  ADD KEY `fk_del_supplier` (`supplier_id`),
  ADD KEY `fk_del_user` (`created_by`);

--
-- Indexes for table `delivery_items`
--
ALTER TABLE `delivery_items`
  ADD PRIMARY KEY (`delivery_item_id`),
  ADD UNIQUE KEY `uq_delivery_product` (`delivery_id`,`product_id`),
  ADD KEY `fk_di_product` (`product_id`);

--
-- Indexes for table `locations`
--
ALTER TABLE `locations`
  ADD PRIMARY KEY (`location_id`),
  ADD UNIQUE KEY `location_code` (`location_code`);

--
-- Indexes for table `location_stock`
--
ALTER TABLE `location_stock`
  ADD PRIMARY KEY (`location_id`,`product_id`),
  ADD KEY `fk_ls_product` (`product_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`product_id`),
  ADD UNIQUE KEY `sku` (`sku`);

--
-- Indexes for table `product_alert_settings`
--
ALTER TABLE `product_alert_settings`
  ADD PRIMARY KEY (`product_id`);

--
-- Indexes for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`po_id`),
  ADD UNIQUE KEY `delivery_id` (`delivery_id`),
  ADD KEY `fk_po_location` (`location_id`),
  ADD KEY `fk_po_supplier` (`supplier_id`),
  ADD KEY `fk_po_user` (`created_by`);

--
-- Indexes for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD PRIMARY KEY (`po_item_id`),
  ADD UNIQUE KEY `uq_po_product` (`po_id`,`product_id`),
  ADD KEY `fk_poi_product` (`product_id`);

--
-- Indexes for table `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`report_id`),
  ADD KEY `fk_rep_location` (`location_id`),
  ADD KEY `fk_rep_product` (`product_id`),
  ADD KEY `fk_rep_user` (`generated_by`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`sale_id`),
  ADD KEY `fk_sale_location` (`location_id`),
  ADD KEY `fk_sale_user` (`recorded_by`);

--
-- Indexes for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD PRIMARY KEY (`sale_item_id`),
  ADD UNIQUE KEY `uq_sale_product` (`sale_id`,`product_id`),
  ADD KEY `fk_si_product` (`product_id`);

--
-- Indexes for table `staff_locations`
--
ALTER TABLE `staff_locations`
  ADD PRIMARY KEY (`staff_location_id`),
  ADD UNIQUE KEY `uq_user_location` (`user_id`,`location_id`),
  ADD UNIQUE KEY `uq_one_default` (`default_user_id`),
  ADD KEY `fk_sl_location` (`location_id`),
  ADD KEY `fk_sl_admin` (`assigned_by`);

--
-- Indexes for table `stock_adjustments`
--
ALTER TABLE `stock_adjustments`
  ADD PRIMARY KEY (`adjustment_id`),
  ADD KEY `fk_adj_location` (`location_id`),
  ADD KEY `fk_adj_product` (`product_id`),
  ADD KEY `fk_adj_user` (`adjusted_by`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`supplier_id`),
  ADD UNIQUE KEY `supplier_name` (`supplier_name`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `deliveries`
--
ALTER TABLE `deliveries`
  MODIFY `delivery_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `delivery_items`
--
ALTER TABLE `delivery_items`
  MODIFY `delivery_item_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `locations`
--
ALTER TABLE `locations`
  MODIFY `location_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `product_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `po_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  MODIFY `po_item_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reports`
--
ALTER TABLE `reports`
  MODIFY `report_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `sale_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sale_items`
--
ALTER TABLE `sale_items`
  MODIFY `sale_item_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `staff_locations`
--
ALTER TABLE `staff_locations`
  MODIFY `staff_location_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `stock_adjustments`
--
ALTER TABLE `stock_adjustments`
  MODIFY `adjustment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `supplier_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `alert_rules`
--
ALTER TABLE `alert_rules`
  ADD CONSTRAINT `fk_rule_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `deliveries`
--
ALTER TABLE `deliveries`
  ADD CONSTRAINT `fk_del_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`),
  ADD CONSTRAINT `fk_del_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`),
  ADD CONSTRAINT `fk_del_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `delivery_items`
--
ALTER TABLE `delivery_items`
  ADD CONSTRAINT `fk_di_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`delivery_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_di_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`);

--
-- Constraints for table `location_stock`
--
ALTER TABLE `location_stock`
  ADD CONSTRAINT `fk_ls_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`),
  ADD CONSTRAINT `fk_ls_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`);

--
-- Constraints for table `product_alert_settings`
--
ALTER TABLE `product_alert_settings`
  ADD CONSTRAINT `fk_pas_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD CONSTRAINT `fk_po_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`delivery_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_po_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`),
  ADD CONSTRAINT `fk_po_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`),
  ADD CONSTRAINT `fk_po_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `purchase_order_items`
--
ALTER TABLE `purchase_order_items`
  ADD CONSTRAINT `fk_poi_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`po_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_poi_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`);

--
-- Constraints for table `reports`
--
ALTER TABLE `reports`
  ADD CONSTRAINT `fk_rep_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_rep_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_rep_user` FOREIGN KEY (`generated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `fk_sale_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`),
  ADD CONSTRAINT `fk_sale_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD CONSTRAINT `fk_si_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  ADD CONSTRAINT `fk_si_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`) ON DELETE CASCADE;

--
-- Constraints for table `staff_locations`
--
ALTER TABLE `staff_locations`
  ADD CONSTRAINT `fk_sl_admin` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sl_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`),
  ADD CONSTRAINT `fk_sl_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `stock_adjustments`
--
ALTER TABLE `stock_adjustments`
  ADD CONSTRAINT `fk_adj_location` FOREIGN KEY (`location_id`) REFERENCES `locations` (`location_id`),
  ADD CONSTRAINT `fk_adj_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  ADD CONSTRAINT `fk_adj_user` FOREIGN KEY (`adjusted_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
