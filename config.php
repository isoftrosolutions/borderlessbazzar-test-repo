<?php

define('DB_HOST', 'localhost');
define('DB_USER', 'test_user');
define('DB_PASS', '2@ROrWwM.4(QU2a4');
define('DB_NAME', 'bb_backend');

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
