<?php
namespace Architex;

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/JWTHandler.php';

class AuthService {
    private $db;

    public function __construct() {
        $this->db = get_db_connection();
    }

    public function registerUser($data) {
        if (empty($data['username']) || empty($data['email']) || empty($data['password']) || empty($data['role'])) {
            return ['success' => false, 'message' => 'All fields (username, email, password, role) are required.'];
        }

        if (!in_array($data['role'], ['client', 'freelancer', 'admin'])) {
            return ['success' => false, 'message' => 'Invalid role specified. Must be client, freelancer, or admin.'];
        }

        // Prevent non-admins from creating admin users through public registration
        if ($data['role'] === 'admin') {
            // A more robust check would involve checking the current user's role if this method
            // could be called by an authenticated user. For public registration, simply deny.
            // Or, only allow admin creation via a separate, admin-only interface.
            $currentUserRole = JWTHandler::getUserRoleFromAuthHeader();
            if ($currentUserRole !== 'admin') {
                 return ['success' => false, 'message' => 'Admin role cannot be self-assigned during public registration.'];
            }
        }


        // Check if username or email already exists
        $stmt = $this->db->prepare("SELECT user_id FROM users WHERE username = :username OR email = :email");
        $stmt->bindParam(':username', $data['username']);
        $stmt->bindParam(':email', $data['email']);
        $stmt->execute();
        if ($stmt->fetch()) {
            return ['success' => false, 'message' => 'Username or email already exists.'];
        }

        // Hash the password
        $password_hash = password_hash($data['password'], PASSWORD_BCRYPT);
        if (!$password_hash) {
             error_log("Password hashing failed for user: " . $data['email']);
             return ['success' => false, 'message' => 'Error processing registration. Please try again.'];
        }

        // Insert user into database
        $sql = "INSERT INTO users (username, email, password_hash, role, first_name, last_name, company_name, bio, profile_picture_url, is_active)
                VALUES (:username, :email, :password_hash, :role, :first_name, :last_name, :company_name, :bio, :profile_picture_url, TRUE)";

        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':username', $data['username']);
        $stmt->bindParam(':email', $data['email']);
        $stmt->bindParam(':password_hash', $password_hash);
        $stmt->bindParam(':role', $data['role']);

        // Optional fields
        $firstName = $data['first_name'] ?? null;
        $lastName = $data['last_name'] ?? null;
        $companyName = $data['company_name'] ?? null;
        $bio = $data['bio'] ?? null;
        $profilePictureUrl = $data['profile_picture_url'] ?? null;

        $stmt->bindParam(':first_name', $firstName);
        $stmt->bindParam(':last_name', $lastName);
        $stmt->bindParam(':company_name', $companyName);
        $stmt->bindParam(':bio', $bio);
        $stmt->bindParam(':profile_picture_url', $profilePictureUrl);

        try {
            if ($stmt->execute()) {
                $user_id = $this->db->lastInsertId();
                // Optionally, log the user in directly by generating a JWT
                // $jwt = JWTHandler::encode(['user_id' => $user_id, 'role' => $data['role']]);
                // return ['success' => true, 'message' => 'User registered successfully.', 'user_id' => $user_id, 'token' => $jwt];
                return ['success' => true, 'message' => 'User registered successfully. Please log in.', 'user_id' => $user_id];
            } else {
                error_log("User registration failed: Statement execution error for email: " . $data['email']);
                return ['success' => false, 'message' => 'Registration failed. Please try again.'];
            }
        } catch (\PDOException $e) {
            error_log("User registration PDOException for email " . $data['email'] . ": " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error during registration. Please try again.'];
        }
    }

    public function loginUser($data) {
        if (empty($data['email']) || empty($data['password'])) {
            return ['success' => false, 'message' => 'Email and password are required.'];
        }

        $stmt = $this->db->prepare("SELECT user_id, username, email, password_hash, role, is_active FROM users WHERE email = :email");
        $stmt->bindParam(':email', $data['email']);
        $stmt->execute();
        $user = $stmt->fetch();

        if ($user) {
            if (!$user['is_active']) {
                return ['success' => false, 'message' => 'Account is deactivated. Please contact support.'];
            }

            if (password_verify($data['password'], $user['password_hash'])) {
                // Password is correct, generate JWT
                $jwt_payload = [
                    'user_id' => $user['user_id'],
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'role' => $user['role']
                ];
                $jwt = JWTHandler::encode($jwt_payload);

                if ($jwt) {
                    // Update last_login_at
                    $updateStmt = $this->db->prepare("UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE user_id = :user_id");
                    $updateStmt->bindParam(':user_id', $user['user_id']);
                    $updateStmt->execute();

                    // Prepare user data to return (excluding sensitive info like password_hash)
                    $userData = [
                        'user_id' => $user['user_id'],
                        'username' => $user['username'],
                        'email' => $user['email'],
                        'role' => $user['role']
                        // Add other relevant fields like first_name, last_name if needed by frontend
                    ];

                    return [
                        'success' => true,
                        'message' => 'Login successful.',
                        'token' => $jwt,
                        'user' => $userData
                    ];
                } else {
                    error_log("JWT generation failed for user: " . $data['email']);
                    return ['success' => false, 'message' => 'Login failed: Could not generate authentication token.'];
                }
            } else {
                return ['success' => false, 'message' => 'Invalid email or password.'];
            }
        } else {
            return ['success' => false, 'message' => 'Invalid email or password.'];
        }
    }

    // Placeholder for logout - JWTs are typically handled client-side for stateless auth.
    // If using a server-side token blocklist, that logic would go here.
    public function logoutUser() {
        // For stateless JWT, client just deletes the token.
        // If you implement a token blacklist, you'd add the token here.
        return ['success' => true, 'message' => 'Logout successful. Please discard your token.'];
    }
}
?>
