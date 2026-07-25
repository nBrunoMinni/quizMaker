<?php
// Set to false when public
define('DEBUG_MODE', false);

error_reporting(E_ALL);
ini_set('display_errors', DEBUG_MODE ? 1 : 0);
ini_set('log_errors', 1);

// Basic security
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer-when-downgrade');

// Catch escapes, no stack trace
set_exception_handler(function ($e) {
  error_log((string)$e);
  if (!headers_sent()) http_response_code(500);
  exit(DEBUG_MODE ? htmlspecialchars((string)$e, ENT_QUOTES, 'UTF-8') : 'Something went wrong.');
});

// PHP 8.1 defaults throwing
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// FILL IN host info here
$host = "localhost";
$username = "";
$password = "";
$database = "";

try {
  $dbc = new mysqli($host, $username, $password, $database);
  $dbc->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
  error_log('DB connect failed: ' . $e->getMessage());
  if (!headers_sent()) http_response_code(503);
  exit('Service temporarily unavailable.');
}


// FILL IN admin hash in place of 'HERE'
define('ADMIN_PASSWORD_HASH', 'HERE');


$dbc->query("CREATE TABLE IF NOT EXISTS quizzes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  data LONGTEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$dbc->query("CREATE TABLE IF NOT EXISTS submissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  quiz_id INT NOT NULL,
  student_name VARCHAR(255) NOT NULL,
  answers LONGTEXT NOT NULL,
  score INT NOT NULL,
  total INT NOT NULL,
  submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$dbc->query("CREATE TABLE IF NOT EXISTS loginAttempts (
  ip VARBINARY(16) NOT NULL PRIMARY KEY,
  fails INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");