<?php
namespace Architex;

require_once __DIR__ . '/../vendor/autoload.php'; // Autoload Composer dependencies

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Exception;
use UnexpectedValueException;
use DomainException;

class JWTHandler {
    private static $secret_key;
    private static $issuer_claim;
    private static $audience_claim;
    private static $issued_at_claim;
    private static $not_before_claim;
    private static $expire_claim;
    private static $algorithm = 'HS256';

    public static function init() {
        // In a real application, use environment variables for these
        self::$secret_key = getenv('JWT_SECRET_KEY') ?: 'YOUR_DEFAULT_VERY_STRONG_SECRET_KEY_HERE_CHANGE_ME';
        self::$issuer_claim = getenv('JWT_ISSUER') ?: "THE_ISSUER";
        self::$audience_claim = getenv('JWT_AUDIENCE') ?: "THE_AUDIENCE";
        self::$issued_at_claim = time(); // current timestamp
        self::$not_before_claim = self::$issued_at_claim; // can be same as issued_at or future
        self::$expire_claim = self::$issued_at_claim + (60 * 60 * 24); // Token valid for 24 hours
    }

    public static function encode($data) {
        self::init(); // Ensure static properties are initialized

        $token = array(
            "iss" => self::$issuer_claim,
            "aud" => self::$audience_claim,
            "iat" => self::$issued_at_claim,
            "nbf" => self::$not_before_claim,
            "exp" => self::$expire_claim,
            "data" => $data // User-specific data like user_id, role
        );

        try {
            return JWT::encode($token, self::$secret_key, self::$algorithm);
        } catch (Exception $e) {
            // Log error or handle appropriately
            error_log("JWT Encoding Error: " . $e->getMessage());
            return null;
        }
    }

    public static function decode($jwt) {
        self::init(); // Ensure static properties are initialized

        if (!$jwt) {
            return null;
        }

        try {
            $decoded = JWT::decode($jwt, new Key(self::$secret_key, self::$algorithm));
            return (array) $decoded->data; // Return the payload's data part as an array
        } catch (UnexpectedValueException $e) { // Covers ExpiredException, SignatureInvalidException
            error_log("JWT Decoding Error (UnexpectedValueException): " . $e->getMessage());
            return null;
        } catch (DomainException $e) { // Covers BeforeValidException
             error_log("JWT Decoding Error (DomainException): " . $e->getMessage());
            return null;
        } catch (Exception $e) { // Other generic exceptions
            error_log("JWT Decoding Error (General Exception): " . $e->getMessage());
            return null;
        }
    }

    public static function getUserIdFromAuthHeader() {
        $authHeader = self::getAuthorizationHeader();
        if (!$authHeader) {
            return null;
        }

        list($jwt) = sscanf($authHeader, 'Bearer %s');
        if (!$jwt) {
            return null;
        }

        $decoded_data = self::decode($jwt);
        return $decoded_data['user_id'] ?? null;
    }

    public static function getUserRoleFromAuthHeader() {
        $authHeader = self::getAuthorizationHeader();
        if (!$authHeader) {
            return null;
        }

        list($jwt) = sscanf($authHeader, 'Bearer %s');
        if (!$jwt) {
            return null;
        }

        $decoded_data = self::decode($jwt);
        return $decoded_data['role'] ?? null;
    }

    public static function getAuthorizationHeader(){
        $headers = null;
        if (isset($_SERVER['Authorization'])) {
            $headers = trim($_SERVER["Authorization"]);
        } else if (isset($_SERVER['HTTP_AUTHORIZATION'])) { //Nginx or fast CGI
            $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
        } elseif (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            // Server-side fix for bug in old Android versions (a nice to have)
            if (isset($requestHeaders['Authorization'])) {
                $headers = trim($requestHeaders['Authorization']);
            }
        }
        return $headers;
    }

    public static function validateToken() {
        $authHeader = self::getAuthorizationHeader();
        if (!$authHeader) {
            return false;
        }
        list($jwt) = sscanf($authHeader, 'Bearer %s');
        if (!$jwt) {
            return false;
        }
        $decoded = self::decode($jwt);
        return $decoded !== null;
    }
}

// Initialize static properties when the class is loaded
// JWTHandler::init(); // This is not ideal, init() should be called at the start of encode/decode
// or explicitly by the application setup. For now, calling it within encode/decode.

?>
