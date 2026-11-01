# IPTB-LSMS (Lubricant Stock Management System for IPTB Sdn Bhd)

BCS3263 Software Quality Assurance, Semester I 2026/2027.

## What is working
* **Module 1 (Lubricant Product Management) is PHP + MySQL**: `module 1/products.php`
  Create, list with summary boxes, edit, delete with confirmation, search and filters,
  JPG/PNG image upload (2 MB limit). Validation is done on the server by one shared function
  (`includes/helpers.php`), queries use prepared statements, output is escaped, forms use a CSRF token.
  The stock quantity cannot be edited here (it changes only through deliveries, sales and adjustments).
* **Module 2 (Stock Receiving) is PHP + MySQL**: `module_2/stock-receiving.php`
  Order new stock from a supplier (purchase order, with a printable invoice), receive or cancel the order,
  add a delivery without an order, view / edit / cancel deliveries, and print stock on hand.
  Stock is changed only by the database triggers on `delivery_items`, never directly by the page.
  Rules checked on the server (`includes/helpers.php`): supplier and at least one product required, no product twice,
  whole-number quantity of 1 or more, unit cost of 0 or more, no duplicate supplier invoice number,
  dates not in the future and delivery date not before the order date. A delivery cannot be edited or cancelled
  if that would take stock below 0 (units already sold). Staff can only see and change their own location
  (checked by PHP); admin sees every location. The old prototype is kept as `module_2/stock-receiving.html`.
* Modules 3 and 4 are still the HTML prototypes of the other members.
  The shared menu (`sidebar.js`) opens the PHP pages for Modules 1 and 2. The old prototype is kept as `module 1/products.html`.

## Run it with XAMPP
1. Install XAMPP and start **Apache** and **MySQL**.
2. Copy this folder (`LSMS`) into `htdocs` (Windows: `C:\xampp\htdocs\`, macOS: `/Applications/XAMPP/xamppfiles/htdocs/`).
3. Open `http://localhost/phpmyadmin` > **Import** > choose `database/iptb_lsms.sql` > **Go**.
   (It drops and recreates the database `iptb_lsms`.)
4. Optional: import `database/optional_patch_stock_rows.sql` the same way.
   The seed file has stock rows only for Pahang; the patch adds zero-stock rows for Kelantan.5. Open `http://localhost/LSMS/login.html` (demo logins are listed at the top of `database/iptb_lsms.sql`),
   or go straight to `http://localhost/LSMS/module%201/products.php`.

Database settings are in `includes/db.php` (XAMPP default: user `root`, empty password).

## Put it on a web host (cPanel)
1. Create a database and a database user in cPanel and give the user all privileges.
2. In `database/iptb_lsms.sql` delete the first three statements (`DROP DATABASE`, `CREATE DATABASE`, `USE`),
   then import the file into your new database with phpMyAdmin.
3. Upload this folder to `public_html`, then edit `includes/db.php` with the host, database, user and password.
4. Before real use: change the demo passwords, delete the `database` folder from the server after the import,
   turn off `display_errors` and use HTTPS.

Note: the login in this version is kept in the browser (sessionStorage) and is for demonstration only.
Staff are limited to their own location by the page script, not by the server, so add PHP session login
before real use.
