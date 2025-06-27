<?php
namespace Architex;

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/JWTHandler.php';

class UserService {
    private $db;

    public function __construct() {
        $this->db = get_db_connection();
    }

    // Get user profile by ID
    public function getUserProfile($user_id) {
        $stmt = $this->db->prepare("SELECT user_id, username, email, role, first_name, last_name, profile_picture_url, bio, company_name, is_active, created_at, last_login_at FROM users WHERE user_id = :user_id");
        $stmt->bindParam(':user_id', $user_id, \PDO::PARAM_INT);
        $stmt->execute();
        $user = $stmt->fetch();

        if (!$user) {
            return ['success' => false, 'message' => 'User not found.'];
        }

        // Fetch user skills
        $skills_stmt = $this->db->prepare("SELECT s.skill_id, s.skill_name FROM user_skills us JOIN skills s ON us.skill_id = s.skill_id WHERE us.user_id = :user_id");
        $skills_stmt->bindParam(':user_id', $user_id, \PDO::PARAM_INT);
        $skills_stmt->execute();
        $user['skills'] = $skills_stmt->fetchAll();

        return ['success' => true, 'user' => $user];
    }

    // Update user profile
    public function updateUserProfile($user_id_to_update, $data, $current_user_id, $current_user_role) {
        if ($current_user_id != $user_id_to_update && $current_user_role !== 'admin') {
            return ['success' => false, 'message' => 'Unauthorized to update this profile.'];
        }

        // Fetch current user data to compare
        $current_profile_response = $this->getUserProfile($user_id_to_update);
        if (!$current_profile_response['success']) {
            return $current_profile_response; // User not found
        }
        $current_user_data = $current_profile_response['user'];

        $fields_to_update = [];
        $params = [':user_id' => $user_id_to_update];

        // Fields that can be updated by user or admin
        if (isset($data['first_name'])) { $fields_to_update[] = "first_name = :first_name"; $params[':first_name'] = $data['first_name']; }
        if (isset($data['last_name'])) { $fields_to_update[] = "last_name = :last_name"; $params[':last_name'] = $data['last_name']; }
        if (isset($data['bio'])) { $fields_to_update[] = "bio = :bio"; $params[':bio'] = $data['bio']; }
        if (isset($data['profile_picture_url'])) { $fields_to_update[] = "profile_picture_url = :profile_picture_url"; $params[':profile_picture_url'] = $data['profile_picture_url']; }
        if (isset($data['company_name']) && ($current_user_data['role'] === 'client' || $current_user_role === 'admin')) {
            $fields_to_update[] = "company_name = :company_name"; $params[':company_name'] = $data['company_name'];
        }

        // Email update - requires careful handling (e.g., re-verification)
        if (isset($data['email']) && $data['email'] !== $current_user_data['email']) {
            // Check if new email is already in use by another user
            $stmt_check_email = $this->db->prepare("SELECT user_id FROM users WHERE email = :email AND user_id != :user_id_to_update");
            $stmt_check_email->bindParam(':email', $data['email']);
            $stmt_check_email->bindParam(':user_id_to_update', $user_id_to_update, \PDO::PARAM_INT);
            $stmt_check_email->execute();
            if ($stmt_check_email->fetch()) {
                return ['success' => false, 'message' => 'Email already in use by another account.'];
            }
            $fields_to_update[] = "email = :email"; $params[':email'] = $data['email'];
            // Consider adding an email verification step here in a real app
        }

        // Password update
        if (isset($data['password']) && !empty($data['password'])) {
            $new_password_hash = password_hash($data['password'], PASSWORD_BCRYPT);
            if (!$new_password_hash) {
                error_log("Password hashing failed during profile update for user ID: " . $user_id_to_update);
                return ['success' => false, 'message' => 'Error processing password update.'];
            }
            $fields_to_update[] = "password_hash = :password_hash"; $params[':password_hash'] = $new_password_hash;
        }


        // Admin-only updatable fields
        if ($current_user_role === 'admin') {
            if (isset($data['username']) && $data['username'] !== $current_user_data['username']) {
                 // Check if new username is already in use by another user
                $stmt_check_username = $this->db->prepare("SELECT user_id FROM users WHERE username = :username AND user_id != :user_id_to_update");
                $stmt_check_username->bindParam(':username', $data['username']);
                $stmt_check_username->bindParam(':user_id_to_update', $user_id_to_update, \PDO::PARAM_INT);
                $stmt_check_username->execute();
                if ($stmt_check_username->fetch()) {
                    return ['success' => false, 'message' => 'Username already in use by another account.'];
                }
                $fields_to_update[] = "username = :username"; $params[':username'] = $data['username'];
            }
            if (isset($data['role']) && in_array($data['role'], ['client', 'freelancer', 'admin'])) {
                $fields_to_update[] = "role = :role"; $params[':role'] = $data['role'];
            }
            if (isset($data['is_active']) && is_bool($data['is_active'])) {
                $fields_to_update[] = "is_active = :is_active"; $params[':is_active'] = (int)$data['is_active']; // Store as 0 or 1
            }
        }

        if (empty($fields_to_update)) {
            return ['success' => false, 'message' => 'No valid fields provided for update.'];
        }

        $sql = "UPDATE users SET " . implode(', ', $fields_to_update) . ", updated_at = CURRENT_TIMESTAMP WHERE user_id = :user_id";
        $stmt = $this->db->prepare($sql);

        try {
            if ($stmt->execute($params)) {
                // Handle user skills update (if provided and user is a freelancer or admin is editing)
                if (isset($data['skills']) && is_array($data['skills']) && ($current_user_data['role'] === 'freelancer' || $current_user_role === 'admin')) {
                    $this->updateUserSkills($user_id_to_update, $data['skills']);
                }
                return ['success' => true, 'message' => 'Profile updated successfully.'];
            } else {
                error_log("Profile update failed for user ID: " . $user_id_to_update . " - Statement execution error.");
                return ['success' => false, 'message' => 'Profile update failed.'];
            }
        } catch (\PDOException $e) {
            error_log("Profile update PDOException for user ID " . $user_id_to_update . ": " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error during profile update.'];
        }
    }

    // List users (for Admins)
    public function listUsers($current_user_role, $filters = []) {
        if ($current_user_role !== 'admin') {
            return ['success' => false, 'message' => 'Unauthorized to list users.'];
        }

        $sql = "SELECT user_id, username, email, role, first_name, last_name, is_active, created_at, last_login_at FROM users";
        $where_clauses = [];
        $params = [];

        if (!empty($filters['role'])) {
            $where_clauses[] = "role = :role";
            $params[':role'] = $filters['role'];
        }
        if (isset($filters['is_active'])) { // Check for existence, as is_active can be true/false
            $where_clauses[] = "is_active = :is_active";
            $params[':is_active'] = (int)$filters['is_active'];
        }
        if (!empty($filters['search'])) {
            $search_term = '%' . $filters['search'] . '%';
            $where_clauses[] = "(username LIKE :search OR email LIKE :search OR first_name LIKE :search OR last_name LIKE :search)";
            $params[':search'] = $search_term;
        }

        if (!empty($where_clauses)) {
            $sql .= " WHERE " . implode(' AND ', $where_clauses);
        }
        $sql .= " ORDER BY created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();

        return ['success' => true, 'users' => $users];
    }

    // Deactivate user (Admin only)
    public function setUserActiveStatus($user_id_to_change, $is_active_status, $current_user_role) {
        if ($current_user_role !== 'admin') {
            return ['success' => false, 'message' => 'Unauthorized action.'];
        }
        if (!is_bool($is_active_status)) {
             return ['success' => false, 'message' => 'Invalid active status provided.'];
        }

        $sql = "UPDATE users SET is_active = :is_active, updated_at = CURRENT_TIMESTAMP WHERE user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':is_active', $is_active_status, \PDO::PARAM_BOOL);
        $stmt->bindParam(':user_id', $user_id_to_change, \PDO::PARAM_INT);

        try {
            if ($stmt->execute()) {
                $action = $is_active_status ? 'activated' : 'deactivated';
                return ['success' => true, 'message' => "User successfully {$action}."];
            } else {
                error_log("Failed to change active status for user ID: " . $user_id_to_change);
                return ['success' => false, 'message' => 'Failed to update user status.'];
            }
        } catch (\PDOException $e) {
            error_log("PDOException changing active status for user ID " . $user_id_to_change . ": " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error updating user status.'];
        }
    }

    // Manage user skills (add/remove)
    public function updateUserSkills($user_id, $skill_ids) {
        // Only freelancers should have skills, or admins editing them.
        // This check might be better placed in the calling function (updateUserProfile)

        // Start transaction
        $this->db->beginTransaction();
        try {
            // Remove existing skills for the user
            $stmt_delete = $this->db->prepare("DELETE FROM user_skills WHERE user_id = :user_id");
            $stmt_delete->bindParam(':user_id', $user_id, \PDO::PARAM_INT);
            $stmt_delete->execute();

            // Add new skills
            if (!empty($skill_ids)) {
                $stmt_insert = $this->db->prepare("INSERT INTO user_skills (user_id, skill_id) VALUES (:user_id, :skill_id)");
                foreach ($skill_ids as $skill_id) {
                    // Optionally, validate if skill_id exists in 'skills' table first
                    $stmt_insert->bindParam(':user_id', $user_id, \PDO::PARAM_INT);
                    $stmt_insert->bindParam(':skill_id', $skill_id, \PDO::PARAM_INT);
                    $stmt_insert->execute();
                }
            }
            $this->db->commit();
            return ['success' => true, 'message' => 'User skills updated successfully.'];
        } catch (\PDOException $e) {
            $this->db->rollBack();
            error_log("PDOException updating user skills for user ID " . $user_id . ": " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error updating user skills.'];
        }
    }

    // Get messageable users (for DM functionality)
    public function getMessageableUsers($current_user_id, $current_user_role) {
        $sql = "";
        $params = [':current_user_id' => $current_user_id];

        switch ($current_user_role) {
            case 'admin':
                // Admins can message anyone except themselves
                $sql = "SELECT user_id, username, role, first_name, last_name FROM users WHERE user_id != :current_user_id AND is_active = TRUE ORDER BY username ASC";
                break;
            case 'client':
            case 'freelancer':
                // Clients and Freelancers can only message Admins (and not themselves if they are an admin somehow)
                $sql = "SELECT user_id, username, role, first_name, last_name FROM users WHERE role = 'admin' AND user_id != :current_user_id AND is_active = TRUE ORDER BY username ASC";
                break;
            default:
                return ['success' => false, 'message' => 'Invalid user role for messaging.'];
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $users = $stmt->fetchAll();
        return ['success' => true, 'users' => $users];
    }
}
?>
