<?php
namespace Architex;

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/JWTHandler.php'; // For role checks

class SkillService {
    private $db;

    public function __construct() {
        $this->db = get_db_connection();
    }

    // List all skills
    public function listSkills() {
        $stmt = $this->db->prepare("SELECT skill_id, skill_name, description FROM skills ORDER BY skill_name ASC");
        $stmt->execute();
        $skills = $stmt->fetchAll();
        return ['success' => true, 'skills' => $skills];
    }

    // Get a single skill by ID
    public function getSkillById($skill_id) {
        $stmt = $this->db->prepare("SELECT skill_id, skill_name, description FROM skills WHERE skill_id = :skill_id");
        $stmt->bindParam(':skill_id', $skill_id, \PDO::PARAM_INT);
        $stmt->execute();
        $skill = $stmt->fetch();

        if (!$skill) {
            return ['success' => false, 'message' => 'Skill not found.'];
        }
        return ['success' => true, 'skill' => $skill];
    }

    // Create a new skill (Admin only)
    public function createSkill($data, $current_user_role) {
        if ($current_user_role !== 'admin') {
            return ['success' => false, 'message' => 'Unauthorized to create skills.'];
        }

        if (empty($data['skill_name'])) {
            return ['success' => false, 'message' => 'Skill name is required.'];
        }

        // Check if skill name already exists (case-insensitive check might be good)
        $stmt_check = $this->db->prepare("SELECT skill_id FROM skills WHERE LOWER(skill_name) = LOWER(:skill_name)");
        $stmt_check->bindParam(':skill_name', $data['skill_name']);
        $stmt_check->execute();
        if ($stmt_check->fetch()) {
            return ['success' => false, 'message' => 'Skill name already exists.'];
        }

        $sql = "INSERT INTO skills (skill_name, description) VALUES (:skill_name, :description)";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':skill_name', $data['skill_name']);

        $description = $data['description'] ?? null;
        $stmt->bindParam(':description', $description);

        try {
            if ($stmt->execute()) {
                $skill_id = $this->db->lastInsertId();
                return ['success' => true, 'message' => 'Skill created successfully.', 'skill_id' => $skill_id];
            } else {
                error_log("Skill creation failed for skill name: " . $data['skill_name']);
                return ['success' => false, 'message' => 'Skill creation failed.'];
            }
        } catch (\PDOException $e) {
            error_log("PDOException during skill creation for " . $data['skill_name'] . ": " . $e->getMessage());
            // Check for unique constraint violation (though the manual check above should catch most)
            if ($e->errorInfo[1] == 1062) { // Error code for duplicate entry
                return ['success' => false, 'message' => 'Skill name already exists (database constraint).'];
            }
            return ['success' => false, 'message' => 'Database error during skill creation.'];
        }
    }

    // Update an existing skill (Admin only)
    public function updateSkill($skill_id, $data, $current_user_role) {
        if ($current_user_role !== 'admin') {
            return ['success' => false, 'message' => 'Unauthorized to update skills.'];
        }

        if (empty($data['skill_name'])) { // Description can be optional or emptied
            return ['success' => false, 'message' => 'Skill name cannot be empty.'];
        }

        // Check if the new skill name already exists for a *different* skill ID
        $stmt_check = $this->db->prepare("SELECT skill_id FROM skills WHERE LOWER(skill_name) = LOWER(:skill_name) AND skill_id != :skill_id");
        $stmt_check->bindParam(':skill_name', $data['skill_name']);
        $stmt_check->bindParam(':skill_id', $skill_id, \PDO::PARAM_INT);
        $stmt_check->execute();
        if ($stmt_check->fetch()) {
            return ['success' => false, 'message' => 'Another skill with this name already exists.'];
        }

        $sql = "UPDATE skills SET skill_name = :skill_name, description = :description, updated_at = CURRENT_TIMESTAMP WHERE skill_id = :skill_id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':skill_name', $data['skill_name']);
        $description = $data['description'] ?? null;
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':skill_id', $skill_id, \PDO::PARAM_INT);

        try {
            $affected_rows = $stmt->execute();
            if ($affected_rows) { // execute() returns true on success, check rowCount for actual change
                 if ($stmt->rowCount() > 0) {
                    return ['success' => true, 'message' => 'Skill updated successfully.'];
                 } else {
                    return ['success' => false, 'message' => 'Skill not found or no changes made.'];
                 }
            } else {
                error_log("Skill update failed for skill ID: " . $skill_id);
                return ['success' => false, 'message' => 'Skill update failed.'];
            }
        } catch (\PDOException $e) {
            error_log("PDOException during skill update for skill ID " . $skill_id . ": " . $e->getMessage());
            if ($e->errorInfo[1] == 1062) {
                return ['success' => false, 'message' => 'Skill name already exists (database constraint).'];
            }
            return ['success' => false, 'message' => 'Database error during skill update.'];
        }
    }

    // Delete a skill (Admin only)
    // Consider implications: what happens to users who have this skill?
    // Current schema: user_skills has ON DELETE CASCADE for skill_id, so they'd be removed.
    public function deleteSkill($skill_id, $current_user_role) {
        if ($current_user_role !== 'admin') {
            return ['success' => false, 'message' => 'Unauthorized to delete skills.'];
        }

        // Check if skill is in use by any users (optional, good for UX before deleting)
        // $stmt_check_usage = $this->db->prepare("SELECT COUNT(*) as count FROM user_skills WHERE skill_id = :skill_id");
        // $stmt_check_usage->bindParam(':skill_id', $skill_id, \PDO::PARAM_INT);
        // $stmt_check_usage->execute();
        // $usage_count = $stmt_check_usage->fetchColumn();
        // if ($usage_count > 0) {
        //     return ['success' => false, 'message' => "Skill is currently assigned to {$usage_count} user(s) and cannot be deleted directly. Consider reassigning skills first."];
        // }
        // Due to ON DELETE CASCADE, this check is more for informing admin. Direct deletion will work.

        $sql = "DELETE FROM skills WHERE skill_id = :skill_id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':skill_id', $skill_id, \PDO::PARAM_INT);

        try {
            if ($stmt->execute()) {
                if ($stmt->rowCount() > 0) {
                    return ['success' => true, 'message' => 'Skill deleted successfully.'];
                } else {
                    return ['success' => false, 'message' => 'Skill not found.'];
                }
            } else {
                error_log("Skill deletion failed for skill ID: " . $skill_id);
                return ['success' => false, 'message' => 'Skill deletion failed.'];
            }
        } catch (\PDOException $e) {
            // Foreign key constraints might prevent deletion if not handled by ON DELETE CASCADE or if skill is linked elsewhere
            error_log("PDOException during skill deletion for skill ID " . $skill_id . ": " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error during skill deletion. Ensure skill is not in use if cascades are not set.'];
        }
    }
}
?>
