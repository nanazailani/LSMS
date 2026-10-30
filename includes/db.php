<?php
// Database settings for IPTB-LSMS.
// XAMPP defaults: user "root" with an empty password.
// On a web host, use the database name, user and password created in cPanel.
const DB_HOST = 'localhost';
const DB_NAME = 'iptb_lsms';
const DB_USER = 'root';
const DB_PASS = '';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,   // real prepared statements
            ]
        );
    }
    return $pdo;
}
