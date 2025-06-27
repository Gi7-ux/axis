<?php
// Database configuration
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1'); // Use getenv for Docker, default for local
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'architex_axis');
define('DB_USER', getenv('DB_USER') ?: 'root'); // Replace with your DB username
define('DB_PASS', getenv('DB_PASS') ?: '');   // Replace with your DB password

class Database {
    private static $instance = null;
    private $conn;

    private function __construct() {
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->conn = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // In a real application, log this error and show a user-friendly message
            error_log("Database Connection Error: " . $e->getMessage());
            // For development, you might want to see the error directly
            // die("Database Connection Error: " . $e->getMessage());
            // For production, a generic error is better for the client
            throw new RuntimeException("Could not connect to the database. Please try again later.");
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }

    // Optional: Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {}
}

// Function to easily get the PDO connection
function get_db_connection() {
    return Database::getInstance()->getConnection();
}

// Test connection (optional, remove for production)
/*
try {
    $db = get_db_connection();
    echo "Database connection successful!\n";
} catch (RuntimeException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
*/
?>
