<?php
namespace Architex;

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/JWTHandler.php';

class ProjectCrudService {
    private $db;

    public function __construct() {
        $this->db = get_db_connection();
    }

    // Create a new project (Client only, or Admin on behalf of client)
    public function createProject($data, $client_user_id, $current_user_role) {
        if ($current_user_role !== 'client' && $current_user_role !== 'admin') {
            return ['success' => false, 'message' => 'Unauthorized to create projects.'];
        }

        $project_client_id = ($current_user_role === 'admin' && isset($data['client_id'])) ? $data['client_id'] : $client_user_id;

        // Validate client_id if admin is creating for someone else
        if ($current_user_role === 'admin' && isset($data['client_id'])) {
            $user_service = new UserService(); // Assuming UserService is available
            $client_check = $user_service->getUserProfile($data['client_id']);
            if (!$client_check['success'] || $client_check['user']['role'] !== 'client') {
                return ['success' => false, 'message' => 'Invalid client ID provided or user is not a client.'];
            }
        }


        if (empty($data['title']) || empty($data['description'])) {
            return ['success' => false, 'message' => 'Title and description are required.'];
        }

        $sql = "INSERT INTO projects (client_id, title, description, budget, deadline, status)
                VALUES (:client_id, :title, :description, :budget, :deadline, :status)";
        $stmt = $this->db->prepare($sql);

        $status = $data['status'] ?? 'open'; // Default status
        if ($current_user_role !== 'admin' && $status !== 'open' && $status !== 'pending_approval') { // Clients can only create open/pending projects
            $status = 'open';
        }


        $stmt->bindParam(':client_id', $project_client_id, \PDO::PARAM_INT);
        $stmt->bindParam(':title', $data['title']);
        $stmt->bindParam(':description', $data['description']);
        $stmt->bindParam(':budget', $data['budget']); // PDO handles null if $data['budget'] is not set
        $stmt->bindParam(':deadline', $data['deadline']); // PDO handles null
        $stmt->bindParam(':status', $status);


        try {
            if ($stmt->execute()) {
                $project_id = $this->db->lastInsertId();
                // Potentially create default message threads here (Type B: Admin-Client)
                // $messagingService = new MessagingService();
                // $messagingService->createProjectThread($project_id, 'project_admin_client', [$project_client_id, ...admin_ids]);

                return ['success' => true, 'message' => 'Project created successfully.', 'project_id' => $project_id];
            } else {
                error_log("Project creation failed for client ID: " . $project_client_id);
                return ['success' => false, 'message' => 'Project creation failed.'];
            }
        } catch (\PDOException $e) {
            error_log("PDOException during project creation for client ID " . $project_client_id . ": " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error during project creation.'];
        }
    }

    // Get project details by ID
    public function getProjectById($project_id, $current_user_id, $current_user_role) {
        $sql = "SELECT p.*, u.username as client_username, u.email as client_email
                FROM projects p
                JOIN users u ON p.client_id = u.user_id
                WHERE p.project_id = :project_id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':project_id', $project_id, \PDO::PARAM_INT);
        $stmt->execute();
        $project = $stmt->fetch();

        if (!$project) {
            return ['success' => false, 'message' => 'Project not found.'];
        }

        // Access control:
        // Admin can see any project.
        // Client can see their own projects.
        // Freelancer can see projects they applied to or are assigned to.
        $is_assigned_freelancer = false;
        $has_applied = false;

        if ($current_user_role === 'freelancer') {
            $stmt_assigned = $this->db->prepare("SELECT assignment_id FROM project_assigned_freelancers WHERE project_id = :project_id AND freelancer_id = :freelancer_id");
            $stmt_assigned->bindParam(':project_id', $project_id, \PDO::PARAM_INT);
            $stmt_assigned->bindParam(':freelancer_id', $current_user_id, \PDO::PARAM_INT);
            $stmt_assigned->execute();
            if ($stmt_assigned->fetch()) {
                $is_assigned_freelancer = true;
            }

            $stmt_applied = $this->db->prepare("SELECT application_id FROM project_applications WHERE project_id = :project_id AND freelancer_id = :freelancer_id");
            $stmt_applied->bindParam(':project_id', $project_id, \PDO::PARAM_INT);
            $stmt_applied->bindParam(':freelancer_id', $current_user_id, \PDO::PARAM_INT);
            $stmt_applied->execute();
            if ($stmt_applied->fetch()) {
                $has_applied = true;
            }
        }

        if ($current_user_role !== 'admin' &&
            ($current_user_role === 'client' && $project['client_id'] != $current_user_id) &&
            ($current_user_role === 'freelancer' && !$is_assigned_freelancer && !$has_applied && $project['status'] !== 'open') && // Freelancers can see open projects
            ($current_user_role === 'freelancer' && $project['status'] === 'open' && !$is_assigned_freelancer && !$has_applied ) // Allow freelancers to see open project details
           ) {
            // Correction: Freelancers should be able to see details of 'open' projects to apply.
            // If it's not open, and they are not assigned or haven't applied, then deny.
            if ($project['status'] !== 'open' || ($project['status'] === 'open' && $current_user_role !== 'freelancer')) {
                 if (!($current_user_role === 'freelancer' && $project['status'] === 'open')) { // If it's an open project, freelancer can view
                    return ['success' => false, 'message' => 'Unauthorized to view this project.'];
                 }
            }
        }


        // Fetch assigned freelancers
        $stmt_freelancers = $this->db->prepare("SELECT u.user_id, u.username, u.email, paf.role_in_project, paf.assigned_at
                                               FROM project_assigned_freelancers paf
                                               JOIN users u ON paf.freelancer_id = u.user_id
                                               WHERE paf.project_id = :project_id");
        $stmt_freelancers->bindParam(':project_id', $project_id, \PDO::PARAM_INT);
        $stmt_freelancers->execute();
        $project['assigned_freelancers'] = $stmt_freelancers->fetchAll();

        // Fetch job cards for this project
        $stmt_job_cards = $this->db->prepare("SELECT jc.*, u.username as assigned_freelancer_username
                                             FROM job_cards jc
                                             LEFT JOIN users u ON jc.assigned_freelancer_id = u.user_id
                                             WHERE jc.project_id = :project_id ORDER BY jc.created_at ASC");
        $stmt_job_cards->bindParam(':project_id', $project_id, \PDO::PARAM_INT);
        $stmt_job_cards->execute();
        $project['job_cards'] = $stmt_job_cards->fetchAll();


        return ['success' => true, 'project' => $project];
    }

    // List projects (with filters for different roles)
    public function listProjects($current_user_id, $current_user_role, $filters = []) {
        $base_sql = "SELECT p.project_id, p.title, p.status, p.deadline, p.budget, u.username as client_username, p.created_at
                     FROM projects p
                     JOIN users u ON p.client_id = u.user_id";
        $where_clauses = [];
        $params = [];

        switch ($current_user_role) {
            case 'admin':
                // Admin sees all projects
                break;
            case 'client':
                $where_clauses[] = "p.client_id = :current_user_id";
                $params[':current_user_id'] = $current_user_id;
                break;
            case 'freelancer':
                // Freelancer sees 'open' projects, or projects they are assigned to or applied to.
                $freelancer_project_ids_sql = "(
                    SELECT project_id FROM project_assigned_freelancers WHERE freelancer_id = :current_user_id
                    UNION
                    SELECT project_id FROM project_applications WHERE freelancer_id = :current_user_id
                )";
                $where_clauses[] = "(p.status = 'open' OR p.project_id IN ($freelancer_project_ids_sql))";
                $params[':current_user_id'] = $current_user_id;
                break;
            default:
                return ['success' => false, 'message' => 'Invalid role for listing projects.'];
        }

        if (!empty($filters['status'])) {
            $where_clauses[] = "p.status = :status_filter";
            $params[':status_filter'] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $search_term = '%' . $filters['search'] . '%';
            $where_clauses[] = "(p.title LIKE :search OR p.description LIKE :search)";
            $params[':search'] = $search_term;
        }
        // Add more filters as needed (e.g., skill requirements, budget range)

        $sql = $base_sql;
        if (!empty($where_clauses)) {
            $sql .= " WHERE " . implode(' AND ', $where_clauses);
        }
        $sql .= " ORDER BY p.created_at DESC";
        // Add pagination later if needed

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $projects = $stmt->fetchAll();
        return ['success' => true, 'projects' => $projects];
    }

    // Update project details
    public function updateProject($project_id, $data, $current_user_id, $current_user_role) {
        $project_details_response = $this->getProjectById($project_id, $current_user_id, $current_user_role);
        if (!$project_details_response['success']) {
            return $project_details_response; // Project not found or unauthorized initial access
        }
        $project = $project_details_response['project'];

        // Authorization: Only client who owns it or an admin can update
        if ($current_user_role !== 'admin' && $project['client_id'] != $current_user_id) {
            return ['success' => false, 'message' => 'Unauthorized to update this project.'];
        }

        $fields_to_update = [];
        $params = [':project_id' => $project_id];

        if (isset($data['title'])) { $fields_to_update[] = "title = :title"; $params[':title'] = $data['title']; }
        if (isset($data['description'])) { $fields_to_update[] = "description = :description"; $params[':description'] = $data['description']; }
        if (isset($data['budget'])) { $fields_to_update[] = "budget = :budget"; $params[':budget'] = $data['budget']; }
        if (isset($data['deadline'])) { $fields_to_update[] = "deadline = :deadline"; $params[':deadline'] = $data['deadline']; }

        // Status changes require admin or specific logic
        if (isset($data['status'])) {
            if ($current_user_role === 'admin' ||
                // Allow client to change status under certain conditions (e.g. complete, cancel if no work done)
                ($project['client_id'] == $current_user_id && in_array($data['status'], ['completed', 'cancelled', 'on_hold']))
            ) {
                // Add more validation for status transitions if needed
                $fields_to_update[] = "status = :status"; $params[':status'] = $data['status'];
            } else {
                 return ['success' => false, 'message' => 'Unauthorized to change project status to ' . $data['status'] . '.'];
            }
        }

        // Admin can change client_id
        if ($current_user_role === 'admin' && isset($data['client_id'])) {
            $user_service = new UserService();
            $client_check = $user_service->getUserProfile($data['client_id']);
             if (!$client_check['success'] || $client_check['user']['role'] !== 'client') {
                return ['success' => false, 'message' => 'Invalid new client ID provided or user is not a client.'];
            }
            $fields_to_update[] = "client_id = :client_id"; $params[':client_id'] = $data['client_id'];
        }


        if (empty($fields_to_update)) {
            return ['success' => false, 'message' => 'No valid fields provided for update.'];
        }

        $sql = "UPDATE projects SET " . implode(', ', $fields_to_update) . ", updated_at = CURRENT_TIMESTAMP WHERE project_id = :project_id";
        $stmt = $this->db->prepare($sql);

        try {
            if ($stmt->execute($params)) {
                 if ($stmt->rowCount() > 0) {
                    // If status changed to 'completed', 'cancelled', handle related tasks (e.g., notifications, unassign freelancers if needed)
                    if (isset($data['status']) && in_array($data['status'], ['completed', 'cancelled'])) {
                        // Potentially archive related job cards, notify users, etc.
                    }
                    return ['success' => true, 'message' => 'Project updated successfully.'];
                 } else {
                    return ['success' => false, 'message' => 'No changes made to the project or project not found.'];
                 }
            } else {
                error_log("Project update failed for project ID: " . $project_id);
                return ['success' => false, 'message' => 'Project update failed.'];
            }
        } catch (\PDOException $e) {
            error_log("PDOException during project update for project ID " . $project_id . ": " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error during project update.'];
        }
    }

    // Delete/Archive project (Admin only, or Client under certain conditions)
    public function deleteProject($project_id, $current_user_id, $current_user_role) {
        // Soft delete (archiving by changing status) is often preferred over hard delete.
        // For this example, we'll use the status update logic.
        $project_details_response = $this->getProjectById($project_id, $current_user_id, $current_user_role);
        if (!$project_details_response['success']) {
            return $project_details_response;
        }
        $project = $project_details_response['project'];

        // Allow client to cancel if project is 'open' and no freelancers assigned/no work started
        if ($current_user_role === 'client' && $project['client_id'] == $current_user_id && $project['status'] === 'open' && empty($project['assigned_freelancers'])) {
            return $this->updateProject($project_id, ['status' => 'cancelled'], $current_user_id, $current_user_role);
        } elseif ($current_user_role === 'admin') {
            // Admin can cancel or use a more destructive delete if necessary (not implemented here for safety)
            // For now, admin also uses 'cancelled' status.
            // A true delete would be: DELETE FROM projects WHERE project_id = :project_id
            // This would require handling cascades for related tables (applications, assignments, job_cards, etc.)
            return $this->updateProject($project_id, ['status' => 'cancelled'], $current_user_id, $current_user_role);
        } else {
            return ['success' => false, 'message' => 'Unauthorized to delete/cancel this project or conditions not met.'];
        }
    }

    // --- Project Application Management ---

    public function applyToProject($project_id, $freelancer_id, $proposal_data) {
        // Check if project exists and is open for applications
        $stmt_proj = $this->db->prepare("SELECT status FROM projects WHERE project_id = :project_id");
        $stmt_proj->bindParam(':project_id', $project_id, \PDO::PARAM_INT);
        $stmt_proj->execute();
        $project = $stmt_proj->fetch();

        if (!$project) {
            return ['success' => false, 'message' => 'Project not found.'];
        }
        if ($project['status'] !== 'open') {
            return ['success' => false, 'message' => 'This project is not currently open for applications.'];
        }

        // Check if freelancer has already applied
        $stmt_check = $this->db->prepare("SELECT application_id FROM project_applications WHERE project_id = :project_id AND freelancer_id = :freelancer_id");
        $stmt_check->bindParam(':project_id', $project_id, \PDO::PARAM_INT);
        $stmt_check->bindParam(':freelancer_id', $freelancer_id, \PDO::PARAM_INT);
        $stmt_check->execute();
        if ($stmt_check->fetch()) {
            return ['success' => false, 'message' => 'You have already applied to this project.'];
        }

        $sql = "INSERT INTO project_applications (project_id, freelancer_id, proposal_text, bid_amount, estimated_timeline, status)
                VALUES (:project_id, :freelancer_id, :proposal_text, :bid_amount, :estimated_timeline, 'pending')";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':project_id', $project_id, \PDO::PARAM_INT);
        $stmt->bindParam(':freelancer_id', $freelancer_id, \PDO::PARAM_INT);
        $stmt->bindParam(':proposal_text', $proposal_data['proposal_text']);
        $stmt->bindParam(':bid_amount', $proposal_data['bid_amount']);
        $stmt->bindParam(':estimated_timeline', $proposal_data['estimated_timeline']);

        try {
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Application submitted successfully.'];
            } else {
                error_log("Application submission failed for project ID {$project_id}, freelancer ID {$freelancer_id}");
                return ['success' => false, 'message' => 'Failed to submit application.'];
            }
        } catch (\PDOException $e) {
            error_log("PDOException submitting application for project ID {$project_id}, freelancer ID {$freelancer_id}: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error during application submission.'];
        }
    }

    public function listProjectApplications($project_id, $current_user_id, $current_user_role) {
        // Only client who owns the project or an admin can view applications
        $project_details_response = $this->getProjectById($project_id, $current_user_id, $current_user_role);
         if (!$project_details_response['success']) { // handles not found
            return $project_details_response;
        }
        $project = $project_details_response['project'];

        if ($current_user_role !== 'admin' && $project['client_id'] != $current_user_id) {
            return ['success' => false, 'message' => 'Unauthorized to view applications for this project.'];
        }

        $sql = "SELECT pa.*, u.username as freelancer_username, u.email as freelancer_email, u.profile_picture_url
                FROM project_applications pa
                JOIN users u ON pa.freelancer_id = u.user_id
                WHERE pa.project_id = :project_id ORDER BY pa.applied_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':project_id', $project_id, \PDO::PARAM_INT);
        $stmt->execute();
        $applications = $stmt->fetchAll();
        return ['success' => true, 'applications' => $applications];
    }

    public function listMyApplications($freelancer_id) {
        $sql = "SELECT pa.*, p.title as project_title
                FROM project_applications pa
                JOIN projects p ON pa.project_id = p.project_id
                WHERE pa.freelancer_id = :freelancer_id ORDER BY pa.applied_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':freelancer_id', $freelancer_id, \PDO::PARAM_INT);
        $stmt->execute();
        $applications = $stmt->fetchAll();
        return ['success' => true, 'applications' => $applications];
    }


    public function acceptApplication($application_id, $current_user_id, $current_user_role) {
        // Fetch application details to get project_id and freelancer_id
        $stmt_app = $this->db->prepare("SELECT pa.project_id, pa.freelancer_id, p.client_id, p.status as project_status
                                        FROM project_applications pa
                                        JOIN projects p ON pa.project_id = p.project_id
                                        WHERE pa.application_id = :application_id");
        $stmt_app->bindParam(':application_id', $application_id, \PDO::PARAM_INT);
        $stmt_app->execute();
        $application = $stmt_app->fetch();

        if (!$application) {
            return ['success' => false, 'message' => 'Application not found.'];
        }

        // Authorization: Client who owns the project or an Admin
        if ($current_user_role !== 'admin' && $application['client_id'] != $current_user_id) {
            return ['success' => false, 'message' => 'Unauthorized to accept this application.'];
        }

        if ($application['project_status'] !== 'open' && $application['project_status'] !== 'pending_approval') {
             return ['success' => false, 'message' => 'Project is not in a state where applications can be accepted (current state: '.$application['project_status'].').'];
        }

        $this->db->beginTransaction();
        try {
            // Update application status
            $stmt_update_app = $this->db->prepare("UPDATE project_applications SET status = 'accepted' WHERE application_id = :application_id");
            $stmt_update_app->bindParam(':application_id', $application_id, \PDO::PARAM_INT);
            $stmt_update_app->execute();

            // Add to project_assigned_freelancers
            $stmt_assign = $this->db->prepare("INSERT INTO project_assigned_freelancers (project_id, freelancer_id, role_in_project)
                                              VALUES (:project_id, :freelancer_id, :role_in_project)
                                              ON DUPLICATE KEY UPDATE role_in_project = VALUES(role_in_project)"); // Avoid error if somehow already assigned
            $role_in_project = "Freelancer"; // Default role, could be expanded
            $stmt_assign->bindParam(':project_id', $application['project_id'], \PDO::PARAM_INT);
            $stmt_assign->bindParam(':freelancer_id', $application['freelancer_id'], \PDO::PARAM_INT);
            $stmt_assign->bindParam(':role_in_project', $role_in_project);
            $stmt_assign->execute();

            // Update project status to 'in_progress' if it's 'open'
            if ($application['project_status'] === 'open' || $application['project_status'] === 'pending_approval') {
                $stmt_update_proj = $this->db->prepare("UPDATE projects SET status = 'in_progress', updated_at = CURRENT_TIMESTAMP WHERE project_id = :project_id");
                $stmt_update_proj->bindParam(':project_id', $application['project_id'], \PDO::PARAM_INT);
                $stmt_update_proj->execute();
            }

            // Potentially create Type A message thread (Client-Admin-Freelancer) if not exists
            $messagingService = new MessagingService(); // Assumed
            $thread_participants = [$application['client_id'], $application['freelancer_id']];
            // Add all admins to Type A thread (or a designated project admin)
            $admin_users = $this->getAdminUserIds(); // Helper function to get all admin IDs
            $thread_participants = array_unique(array_merge($thread_participants, $admin_users));

            $messagingService->findOrCreateProjectThread(
                $application['project_id'],
                'project_client_admin_freelancer',
                $thread_participants,
                "Project Discussion - " . $application['project_id'] // Example title
            );


            $this->db->commit();
            // Send notifications to freelancer
            return ['success' => true, 'message' => 'Application accepted and freelancer assigned.'];

        } catch (\PDOException $e) {
            $this->db->rollBack();
            error_log("PDOException accepting application ID {$application_id}: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error while accepting application.'];
        }
    }

    private function getAdminUserIds() {
        $stmt = $this->db->query("SELECT user_id FROM users WHERE role = 'admin' AND is_active = TRUE");
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function rejectApplication($application_id, $current_user_id, $current_user_role) {
        $stmt_app = $this->db->prepare("SELECT pa.project_id, p.client_id
                                        FROM project_applications pa
                                        JOIN projects p ON pa.project_id = p.project_id
                                        WHERE pa.application_id = :application_id AND pa.status = 'pending'"); // Can only reject pending
        $stmt_app->bindParam(':application_id', $application_id, \PDO::PARAM_INT);
        $stmt_app->execute();
        $application = $stmt_app->fetch();

        if (!$application) {
            return ['success' => false, 'message' => 'Pending application not found.'];
        }

        if ($current_user_role !== 'admin' && $application['client_id'] != $current_user_id) {
            return ['success' => false, 'message' => 'Unauthorized to reject this application.'];
        }

        $stmt_update_app = $this->db->prepare("UPDATE project_applications SET status = 'rejected' WHERE application_id = :application_id");
        $stmt_update_app->bindParam(':application_id', $application_id, \PDO::PARAM_INT);

        try {
            if($stmt_update_app->execute()){
                 // Send notification to freelancer
                return ['success' => true, 'message' => 'Application rejected.'];
            } else {
                return ['success' => false, 'message' => 'Failed to reject application.'];
            }
        } catch (\PDOException $e) {
            error_log("PDOException rejecting application ID {$application_id}: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error while rejecting application.'];
        }
    }

    // --- Job Card Management ---
    public function createJobCard($project_id, $data, $current_user_id, $current_user_role) {
        $project_details = $this->getProjectById($project_id, $current_user_id, $current_user_role);
        if (!$project_details['success']) {
            return $project_details;
        }
        // Only client or admin can add job cards directly to project
        // Freelancers might add sub-tasks to job_cards they are assigned, if feature is expanded.
        if ($current_user_role !== 'admin' && $project_details['project']['client_id'] != $current_user_id) {
            return ['success' => false, 'message' => 'Unauthorized to add job cards to this project.'];
        }

        if (empty($data['title'])) {
            return ['success' => false, 'message' => 'Job card title is required.'];
        }

        $sql = "INSERT INTO job_cards (project_id, assigned_freelancer_id, title, description, status, due_date)
                VALUES (:project_id, :assigned_freelancer_id, :title, :description, :status, :due_date)";
        $stmt = $this->db->prepare($sql);

        $assigned_freelancer_id = $data['assigned_freelancer_id'] ?? null;
        // Validate if assigned_freelancer_id is actually assigned to the project if provided
        if ($assigned_freelancer_id) {
            $is_assigned = false;
            foreach($project_details['project']['assigned_freelancers'] as $assigned_f) {
                if ($assigned_f['user_id'] == $assigned_freelancer_id) {
                    $is_assigned = true;
                    break;
                }
            }
            if (!$is_assigned) {
                return ['success' => false, 'message' => 'Assigned freelancer is not part of this project.'];
            }
        }

        $stmt->bindParam(':project_id', $project_id, \PDO::PARAM_INT);
        $stmt->bindParam(':assigned_freelancer_id', $assigned_freelancer_id, \PDO::PARAM_INT);
        $stmt->bindParam(':title', $data['title']);
        $stmt->bindParam(':description', $data['description']);
        $status = $data['status'] ?? 'todo';
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':due_date', $data['due_date']);

        try {
            if ($stmt->execute()) {
                $job_card_id = $this->db->lastInsertId();
                return ['success' => true, 'message' => 'Job card created.', 'job_card_id' => $job_card_id];
            } else {
                return ['success' => false, 'message' => 'Failed to create job card.'];
            }
        } catch (\PDOException $e) {
            error_log("PDO creating job card for project {$project_id}: " . $e->getMessage());
            return ['success' => false, 'message' => 'DB error creating job card.'];
        }
    }

    public function updateJobCard($job_card_id, $data, $current_user_id, $current_user_role) {
        // Fetch job card to get project_id for authorization
        $stmt_jc = $this->db->prepare("SELECT jc.*, p.client_id FROM job_cards jc JOIN projects p ON jc.project_id = p.project_id WHERE jc.job_card_id = :job_card_id");
        $stmt_jc->bindParam(':job_card_id', $job_card_id, \PDO::PARAM_INT);
        $stmt_jc->execute();
        $job_card = $stmt_jc->fetch();

        if (!$job_card) {
            return ['success' => false, 'message' => 'Job card not found.'];
        }

        $can_update = false;
        if ($current_user_role === 'admin' || ($current_user_role === 'client' && $job_card['client_id'] == $current_user_id)) {
            $can_update = true;
        } elseif ($current_user_role === 'freelancer' && $job_card['assigned_freelancer_id'] == $current_user_id) {
            // Freelancer can update status, or description of their own tasks
            $can_update = true;
            // Restrict what freelancer can update
            if (isset($data['title']) || isset($data['due_date']) || isset($data['assigned_freelancer_id'])) {
                 if (!($current_user_role === 'admin' || ($current_user_role === 'client' && $job_card['client_id'] == $current_user_id))) {
                    return ['success' => false, 'message' => 'Freelancers can only update description and status of their assigned job cards.'];
                 }
            }
        }

        if (!$can_update) {
            return ['success' => false, 'message' => 'Unauthorized to update this job card.'];
        }

        $fields = []; $params = [':job_card_id' => $job_card_id];
        if (isset($data['title'])) { $fields[] = "title = :title"; $params[':title'] = $data['title']; }
        if (isset($data['description'])) { $fields[] = "description = :description"; $params[':description'] = $data['description']; }
        if (isset($data['status'])) { $fields[] = "status = :status"; $params[':status'] = $data['status']; } // Add ENUM validation
        if (isset($data['due_date'])) { $fields[] = "due_date = :due_date"; $params[':due_date'] = $data['due_date']; }
        if (isset($data['assigned_freelancer_id'])) {
            // Validate this freelancer is assigned to the project
            $project_details = $this->getProjectById($job_card['project_id'], $current_user_id, $current_user_role); // Re-fetch for latest assigned freelancers
             if ($data['assigned_freelancer_id'] !== null) { // Allow unassigning
                $is_assigned_to_project = false;
                foreach($project_details['project']['assigned_freelancers'] as $assigned_f) {
                    if ($assigned_f['user_id'] == $data['assigned_freelancer_id']) {
                        $is_assigned_to_project = true;
                        break;
                    }
                }
                if (!$is_assigned_to_project) {
                    return ['success' => false, 'message' => 'Cannot assign job card to a freelancer not on this project.'];
                }
             }
            $fields[] = "assigned_freelancer_id = :assigned_freelancer_id"; $params[':assigned_freelancer_id'] = $data['assigned_freelancer_id'];
        }

        if (empty($fields)) return ['success' => false, 'message' => 'No fields to update.'];

        $sql = "UPDATE job_cards SET " . implode(', ', $fields) . ", updated_at = CURRENT_TIMESTAMP WHERE job_card_id = :job_card_id";
        $stmt = $this->db->prepare($sql);
        try {
            if ($stmt->execute($params) && $stmt->rowCount() > 0) {
                return ['success' => true, 'message' => 'Job card updated.'];
            } else {
                return ['success' => false, 'message' => 'Job card update failed or no changes made.'];
            }
        } catch (\PDOException $e) {
            error_log("PDO updating job card {$job_card_id}: " . $e->getMessage());
            return ['success' => false, 'message' => 'DB error updating job card.'];
        }
    }

    public function deleteJobCard($job_card_id, $current_user_id, $current_user_role) {
        $stmt_jc = $this->db->prepare("SELECT jc.project_id, p.client_id FROM job_cards jc JOIN projects p ON jc.project_id = p.project_id WHERE jc.job_card_id = :job_card_id");
        $stmt_jc->bindParam(':job_card_id', $job_card_id, \PDO::PARAM_INT);
        $stmt_jc->execute();
        $job_card = $stmt_jc->fetch();

        if (!$job_card) {
            return ['success' => false, 'message' => 'Job card not found.'];
        }
         if ($current_user_role !== 'admin' && ($current_user_role !== 'client' || $job_card['client_id'] != $current_user_id)) {
            return ['success' => false, 'message' => 'Unauthorized to delete this job card.'];
        }
        // Consider time logs associated with this job card. ON DELETE CASCADE is set for time_logs.
        $sql = "DELETE FROM job_cards WHERE job_card_id = :job_card_id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':job_card_id', $job_card_id, \PDO::PARAM_INT);
        try {
            if ($stmt->execute() && $stmt->rowCount() > 0) {
                return ['success' => true, 'message' => 'Job card deleted.'];
            } else {
                return ['success' => false, 'message' => 'Failed to delete job card or not found.'];
            }
        } catch (\PDOException $e) {
            error_log("PDO deleting job card {$job_card_id}: " . $e->getMessage());
            return ['success' => false, 'message' => 'DB error deleting job card.'];
        }
    }
     public function listMyJobCards($freelancer_id) {
        $sql = "SELECT jc.*, p.title as project_title
                FROM job_cards jc
                JOIN projects p ON jc.project_id = p.project_id
                WHERE jc.assigned_freelancer_id = :freelancer_id
                ORDER BY jc.due_date ASC, jc.created_at ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':freelancer_id', $freelancer_id, \PDO::PARAM_INT);
        $stmt->execute();
        $job_cards = $stmt->fetchAll();
        return ['success' => true, 'job_cards' => $job_cards];
    }

    public function listAssignedProjects($freelancer_id) {
        $sql = "SELECT p.project_id, p.title, p.status, p.deadline, u.username as client_username, paf.assigned_at
                FROM projects p
                JOIN project_assigned_freelancers paf ON p.project_id = paf.project_id
                JOIN users u ON p.client_id = u.user_id
                WHERE paf.freelancer_id = :freelancer_id
                ORDER BY paf.assigned_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':freelancer_id', $freelancer_id, \PDO::PARAM_INT);
        $stmt->execute();
        $projects = $stmt->fetchAll();
        return ['success' => true, 'projects' => $projects];
    }


}
?>
