<?php
namespace Architex;

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/JWTHandler.php';

class TimeLogService {
    private $db;

    public function __construct() {
        $this->db = get_db_connection();
    }

    // Create a new time log (Freelancer only for their assigned job cards)
    public function createTimeLog($data, $freelancer_user_id) {
        if (empty($data['job_card_id']) || empty($data['start_time']) || empty($data['end_time'])) {
            return ['success' => false, 'message' => 'Job card ID, start time, and end time are required.'];
        }

        // Validate job_card_id and ensure freelancer is assigned to it or the project
        $stmt_job_check = $this->db->prepare(
            "SELECT jc.project_id, jc.assigned_freelancer_id, paf.freelancer_id as project_assigned_freelancer
             FROM job_cards jc
             LEFT JOIN project_assigned_freelancers paf ON jc.project_id = paf.project_id AND paf.freelancer_id = :freelancer_user_id
             WHERE jc.job_card_id = :job_card_id"
        );
        $stmt_job_check->bindParam(':job_card_id', $data['job_card_id'], \PDO::PARAM_INT);
        $stmt_job_check->bindParam(':freelancer_user_id', $freelancer_user_id, \PDO::PARAM_INT);
        $stmt_job_check->execute();
        $job_info = $stmt_job_check->fetch();

        if (!$job_info) {
            return ['success' => false, 'message' => 'Job card not found.'];
        }
        // Check if freelancer is assigned to the job card directly OR assigned to the project generaly
        if ($job_info['assigned_freelancer_id'] != $freelancer_user_id && $job_info['project_assigned_freelancer'] != $freelancer_user_id) {
            return ['success' => false, 'message' => 'You are not authorized to log time for this job card.'];
        }

        // Validate start and end times
        try {
            $start_datetime = new \DateTime($data['start_time']);
            $end_datetime = new \DateTime($data['end_time']);
            if ($start_datetime >= $end_datetime) {
                return ['success' => false, 'message' => 'End time must be after start time.'];
            }
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Invalid start or end time format. Use YYYY-MM-DD HH:MM:SS.'];
        }


        $sql = "INSERT INTO time_logs (job_card_id, freelancer_id, start_time, end_time, notes)
                VALUES (:job_card_id, :freelancer_id, :start_time, :end_time, :notes)";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':job_card_id', $data['job_card_id'], \PDO::PARAM_INT);
        $stmt->bindParam(':freelancer_id', $freelancer_user_id, \PDO::PARAM_INT);
        $stmt->bindParam(':start_time', $data['start_time']); // Assumes 'YYYY-MM-DD HH:MM:SS'
        $stmt->bindParam(':end_time', $data['end_time']);   // Assumes 'YYYY-MM-DD HH:MM:SS'
        $notes = $data['notes'] ?? null;
        $stmt->bindParam(':notes', $notes);

        try {
            if ($stmt->execute()) {
                $time_log_id = $this->db->lastInsertId();
                return ['success' => true, 'message' => 'Time logged successfully.', 'time_log_id' => $time_log_id];
            } else {
                error_log("Time log creation failed for job card ID {$data['job_card_id']}, freelancer {$freelancer_user_id}");
                return ['success' => false, 'message' => 'Failed to log time.'];
            }
        } catch (\PDOException $e) {
            error_log("PDOException creating time log for job card ID {$data['job_card_id']}, freelancer {$freelancer_user_id}: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error while logging time.'];
        }
    }

    // Get time logs (filtered by various parameters)
    public function getTimeLogs($current_user_id, $current_user_role, $filters = []) {
        $sql = "SELECT tl.*, jc.title as job_card_title, p.project_id, p.title as project_title,
                       fu.username as freelancer_username, cu.username as client_username
                FROM time_logs tl
                JOIN job_cards jc ON tl.job_card_id = jc.job_card_id
                JOIN projects p ON jc.project_id = p.project_id
                JOIN users fu ON tl.freelancer_id = fu.user_id -- Freelancer who logged time
                JOIN users cu ON p.client_id = cu.user_id    -- Client who owns the project
                ";

        $where_clauses = [];
        $params = [];

        switch ($current_user_role) {
            case 'admin':
                // Admin can see all time logs, apply filters as provided
                break;
            case 'client':
                $where_clauses[] = "p.client_id = :current_user_id";
                $params[':current_user_id'] = $current_user_id;
                break;
            case 'freelancer':
                $where_clauses[] = "tl.freelancer_id = :current_user_id";
                $params[':current_user_id'] = $current_user_id;
                break;
            default:
                return ['success' => false, 'message' => 'Invalid role for viewing time logs.'];
        }

        if (!empty($filters['project_id'])) {
            $where_clauses[] = "p.project_id = :filter_project_id";
            $params[':filter_project_id'] = $filters['project_id'];
        }
        if (!empty($filters['freelancer_id']) && $current_user_role === 'admin') { // Admin can filter by freelancer
            $where_clauses[] = "tl.freelancer_id = :filter_freelancer_id";
            $params[':filter_freelancer_id'] = $filters['freelancer_id'];
        }
        if (!empty($filters['job_card_id'])) {
            $where_clauses[] = "tl.job_card_id = :filter_job_card_id";
            $params[':filter_job_card_id'] = $filters['job_card_id'];
        }
        if (!empty($filters['date_from'])) {
            $where_clauses[] = "DATE(tl.start_time) >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where_clauses[] = "DATE(tl.end_time) <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }


        if (!empty($where_clauses)) {
            $sql .= " WHERE " . implode(' AND ', $where_clauses);
        }
        $sql .= " ORDER BY tl.start_time DESC";
        // Add pagination later if needed

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $time_logs = $stmt->fetchAll();
        return ['success' => true, 'time_logs' => $time_logs];
    }

    // Update a time log (Freelancer who logged it, or Admin)
    public function updateTimeLog($time_log_id, $data, $current_user_id, $current_user_role) {
        $stmt_log_check = $this->db->prepare("SELECT tl.freelancer_id, jc.project_id
                                              FROM time_logs tl
                                              JOIN job_cards jc ON tl.job_card_id = jc.job_card_id
                                              WHERE tl.time_log_id = :time_log_id");
        $stmt_log_check->bindParam(':time_log_id', $time_log_id, \PDO::PARAM_INT);
        $stmt_log_check->execute();
        $log_info = $stmt_log_check->fetch();

        if (!$log_info) {
            return ['success' => false, 'message' => 'Time log not found.'];
        }

        if ($current_user_role !== 'admin' && $log_info['freelancer_id'] != $current_user_id) {
            return ['success' => false, 'message' => 'Unauthorized to update this time log.'];
        }
        // Add check: prevent editing if already billed/invoiced unless admin

        $fields_to_update = [];
        $params = [':time_log_id' => $time_log_id];

        if (isset($data['job_card_id'])) {
            // Validate new job_card_id belongs to same project and freelancer is authorized for it
            // (Simplified here, full validation like in createTimeLog would be needed)
            $fields_to_update[] = "job_card_id = :job_card_id";
            $params[':job_card_id'] = $data['job_card_id'];
        }
        if (isset($data['start_time'])) {
            $fields_to_update[] = "start_time = :start_time";
            $params[':start_time'] = $data['start_time'];
        }
        if (isset($data['end_time'])) {
            $fields_to_update[] = "end_time = :end_time";
            $params[':end_time'] = $data['end_time'];
        }
        if (isset($data['notes'])) {
            $fields_to_update[] = "notes = :notes";
            $params[':notes'] = $data['notes'];
        }

        if (isset($data['start_time']) || isset($data['end_time'])) {
            $s_time = $data['start_time'] ?? $this->db->query("SELECT start_time from time_logs where time_log_id = {$time_log_id}")->fetchColumn();
            $e_time = $data['end_time'] ?? $this->db->query("SELECT end_time from time_logs where time_log_id = {$time_log_id}")->fetchColumn();
            try {
                $start_datetime = new \DateTime($s_time);
                $end_datetime = new \DateTime($e_time);
                if ($start_datetime >= $end_datetime) {
                    return ['success' => false, 'message' => 'End time must be after start time.'];
                }
            } catch (\Exception $e) {
                return ['success' => false, 'message' => 'Invalid start or end time format. Use YYYY-MM-DD HH:MM:SS.'];
            }
        }


        if (empty($fields_to_update)) {
            return ['success' => false, 'message' => 'No valid fields provided for update.'];
        }

        $sql = "UPDATE time_logs SET " . implode(', ', $fields_to_update) . " WHERE time_log_id = :time_log_id";
        $stmt = $this->db->prepare($sql);

        try {
            if ($stmt->execute($params)) {
                 if ($stmt->rowCount() > 0) {
                    return ['success' => true, 'message' => 'Time log updated successfully.'];
                 } else {
                    return ['success' => false, 'message' => 'No changes made to the time log or log not found.'];
                 }
            } else {
                error_log("Time log update failed for ID {$time_log_id}");
                return ['success' => false, 'message' => 'Time log update failed.'];
            }
        } catch (\PDOException $e) {
            error_log("PDOException updating time log ID {$time_log_id}: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error updating time log.'];
        }
    }

    // Delete a time log (Freelancer who logged it, or Admin)
    public function deleteTimeLog($time_log_id, $current_user_id, $current_user_role) {
        $stmt_log_check = $this->db->prepare("SELECT freelancer_id FROM time_logs WHERE time_log_id = :time_log_id");
        $stmt_log_check->bindParam(':time_log_id', $time_log_id, \PDO::PARAM_INT);
        $stmt_log_check->execute();
        $log_info = $stmt_log_check->fetch();

        if (!$log_info) {
            return ['success' => false, 'message' => 'Time log not found.'];
        }

        if ($current_user_role !== 'admin' && $log_info['freelancer_id'] != $current_user_id) {
            return ['success' => false, 'message' => 'Unauthorized to delete this time log.'];
        }
        // Add check: prevent deleting if already billed/invoiced unless admin

        $sql = "DELETE FROM time_logs WHERE time_log_id = :time_log_id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':time_log_id', $time_log_id, \PDO::PARAM_INT);

        try {
            if ($stmt->execute()) {
                 if ($stmt->rowCount() > 0) {
                    return ['success' => true, 'message' => 'Time log deleted successfully.'];
                 } else {
                    return ['success' => false, 'message' => 'Time log not found or already deleted.'];
                 }
            } else {
                error_log("Time log deletion failed for ID {$time_log_id}");
                return ['success' => false, 'message' => 'Time log deletion failed.'];
            }
        } catch (\PDOException $e) {
            error_log("PDOException deleting time log ID {$time_log_id}: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error deleting time log.'];
        }
    }
}

class BillingService {
    private $db;

    public function __construct() {
        $this->db = get_db_connection();
    }

    // Create a billing record (typically Admin action, or automated)
    public function createBillingRecord($data, $current_user_role) {
        if ($current_user_role !== 'admin') {
            // Could be automated based on project completion or milestones,
            // but manual creation/verification by admin is common.
            return ['success' => false, 'message' => 'Unauthorized to create billing records directly.'];
        }

        if (empty($data['project_id']) || empty($data['client_id']) || empty($data['amount'])) {
            return ['success' => false, 'message' => 'Project ID, Client ID, and Amount are required.'];
        }
        // Validate project_id and client_id exist and are related
        $stmt_check = $this->db->prepare("SELECT client_id FROM projects WHERE project_id = :project_id");
        $stmt_check->bindParam(':project_id', $data['project_id'], \PDO::PARAM_INT);
        $stmt_check->execute();
        $project_client = $stmt_check->fetchColumn();
        if (!$project_client || $project_client != $data['client_id']) {
            return ['success' => false, 'message' => 'Project not found or client ID does not match project owner.'];
        }
        if (isset($data['freelancer_id'])) {
            // Validate freelancer exists
            $user_service = new UserService();
            $freelancer_check = $user_service->getUserProfile($data['freelancer_id']);
            if (!$freelancer_check['success'] || $freelancer_check['user']['role'] !== 'freelancer') {
                 return ['success' => false, 'message' => 'Invalid freelancer ID provided or user is not a freelancer.'];
            }
        }


        $sql = "INSERT INTO billing_info (project_id, client_id, freelancer_id, amount, invoice_number, status, due_date, issued_date, notes)
                VALUES (:project_id, :client_id, :freelancer_id, :amount, :invoice_number, :status, :due_date, :issued_date, :notes)";
        $stmt = $this->db->prepare($sql);

        $status = $data['status'] ?? 'pending';
        $issued_date = $data['issued_date'] ?? date('Y-m-d');
        $invoice_number = $data['invoice_number'] ?? ('INV-' . $data['project_id'] . '-' . time()); // Simple unique invoice number

        $stmt->bindParam(':project_id', $data['project_id'], \PDO::PARAM_INT);
        $stmt->bindParam(':client_id', $data['client_id'], \PDO::PARAM_INT);
        $stmt->bindParam(':freelancer_id', $data['freelancer_id']); // Can be null
        $stmt->bindParam(':amount', $data['amount']);
        $stmt->bindParam(':invoice_number', $invoice_number);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':due_date', $data['due_date']);
        $stmt->bindParam(':issued_date', $issued_date);
        $stmt->bindParam(':notes', $data['notes']);

        try {
            if ($stmt->execute()) {
                $billing_id = $this->db->lastInsertId();
                return ['success' => true, 'message' => 'Billing record created.', 'billing_id' => $billing_id];
            } else {
                error_log("Billing record creation failed for project ID {$data['project_id']}");
                return ['success' => false, 'message' => 'Failed to create billing record.'];
            }
        } catch (\PDOException $e) {
             if ($e->errorInfo[1] == 1062) { // Duplicate invoice_number
                return ['success' => false, 'message' => 'Invoice number already exists. Please use a unique invoice number.'];
            }
            error_log("PDOException creating billing record for project ID {$data['project_id']}: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error creating billing record.'];
        }
    }

    // Get billing records (filtered)
    public function getBillingRecords($current_user_id, $current_user_role, $filters = []) {
        $sql = "SELECT bi.*, p.title as project_title, cu.username as client_username, fu.username as freelancer_username
                FROM billing_info bi
                JOIN projects p ON bi.project_id = p.project_id
                JOIN users cu ON bi.client_id = cu.user_id
                LEFT JOIN users fu ON bi.freelancer_id = fu.user_id";

        $where_clauses = [];
        $params = [];

        switch ($current_user_role) {
            case 'admin':
                break;
            case 'client':
                $where_clauses[] = "bi.client_id = :current_user_id";
                $params[':current_user_id'] = $current_user_id;
                break;
            case 'freelancer':
                // Freelancers see records where they are the freelancer_id (related to their payouts)
                $where_clauses[] = "bi.freelancer_id = :current_user_id";
                $params[':current_user_id'] = $current_user_id;
                break;
            default:
                return ['success' => false, 'message' => 'Invalid role for viewing billing records.'];
        }

        if (!empty($filters['project_id'])) {
            $where_clauses[] = "bi.project_id = :filter_project_id";
            $params[':filter_project_id'] = $filters['project_id'];
        }
        if (!empty($filters['status'])) {
            $where_clauses[] = "bi.status = :filter_status";
            $params[':filter_status'] = $filters['status'];
        }
        if (!empty($filters['client_id']) && $current_user_role === 'admin') {
            $where_clauses[] = "bi.client_id = :filter_client_id";
            $params[':filter_client_id'] = $filters['client_id'];
        }
        if (!empty($filters['freelancer_id']) && $current_user_role === 'admin') {
            $where_clauses[] = "bi.freelancer_id = :filter_freelancer_id";
            $params[':filter_freelancer_id'] = $filters['freelancer_id'];
        }

        if (!empty($where_clauses)) {
            $sql .= " WHERE " . implode(' AND ', $where_clauses);
        }
        $sql .= " ORDER BY bi.issued_date DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $records = $stmt->fetchAll();
        return ['success' => true, 'billing_records' => $records];
    }

    // Update billing record status (Admin primarily)
    public function updateBillingRecordStatus($billing_id, $new_status, $paid_date = null, $current_user_role) {
        if ($current_user_role !== 'admin') {
            return ['success' => false, 'message' => 'Unauthorized to update billing status.'];
        }
        if (!in_array($new_status, ['pending', 'paid', 'overdue', 'cancelled'])) {
            return ['success' => false, 'message' => 'Invalid billing status.'];
        }

        $fields_to_update = ["status = :new_status"];
        $params = [':billing_id' => $billing_id, ':new_status' => $new_status];

        if ($new_status === 'paid') {
            $fields_to_update[] = "paid_date = :paid_date";
            $params[':paid_date'] = $paid_date ?: date('Y-m-d'); // Default to today if not provided
        } else {
            $fields_to_update[] = "paid_date = NULL"; // Clear paid_date if not 'paid'
        }

        $sql = "UPDATE billing_info SET " . implode(', ', $fields_to_update) . ", updated_at = CURRENT_TIMESTAMP WHERE billing_id = :billing_id";
        $stmt = $this->db->prepare($sql);

        try {
            if ($stmt->execute($params)) {
                if ($stmt->rowCount() > 0) {
                    return ['success' => true, 'message' => 'Billing record status updated.'];
                } else {
                    return ['success' => false, 'message' => 'Billing record not found or status unchanged.'];
                }
            } else {
                return ['success' => false, 'message' => 'Failed to update billing record status.'];
            }
        } catch (\PDOException $e) {
            error_log("PDOException updating billing record {$billing_id}: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error updating billing status.'];
        }
    }
}

?>
