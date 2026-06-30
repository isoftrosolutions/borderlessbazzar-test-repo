<?php

define('DB_HOST', 'localhost');
define('DB_USER', 'ektamultp_bb_user');
define('DB_PASS', ';oKu6aH068}Hg6r,');
define('DB_NAME', 'ektamultp_bb_backend');

define('SCRAPER_ALLOWED_HOSTS', 'amazon.in,amazon.com,myntra.com,flipkart.com,meesho.com');

function getDB() {
    static $conn = null;
    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            error_log("DB connection failed: " . $conn->connect_error);
            die("Service temporarily unavailable. Please try again later.");
        }
    }
    return $conn;
}
