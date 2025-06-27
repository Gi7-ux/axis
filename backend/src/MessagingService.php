<?php
namespace Architex;

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/JWTHandler.php';

class MessagingService {
    private $db;

    public function __construct() {
        $this->db = get_db_connection();
    }

    // Get messageable users (moved to UserService, but might be needed here for context)
    // For now, assume calling code gets the participants and passes them.

    // Find or Create a Direct Message Thread
    public function findOrCreateDirectMessageThread($user1_id, $user2_id) {
        if ($user1_id == $user2_id) {
            return ['success' => false, 'message' => 'Cannot create a DM thread with oneself.'];
        }
        // Ensure users are ordered to always find the same thread for the same pair
        $u1 = min($user1_id, $user2_id);
        $u2 = max($user1_id, $user2_id);

        // Check if a DM thread already exists between these two users
        $sql_find = "SELECT t.thread_id
                     FROM message_threads t
                     JOIN thread_participants tp1 ON t.thread_id = tp1.thread_id AND tp1.user_id = :u1
                     JOIN thread_participants tp2 ON t.thread_id = tp2.thread_id AND tp2.user_id = :u2
                     WHERE t.type = 'direct'
                     AND (SELECT COUNT(*) FROM thread_participants tp_count WHERE tp_count.thread_id = t.thread_id) = 2"; // Ensure it's only between these two

        $stmt_find = $this->db->prepare($sql_find);
        $stmt_find->bindParam(':u1', $u1, \PDO::PARAM_INT);
        $stmt_find->bindParam(':u2', $u2, \PDO::PARAM_INT);
        $stmt_find->execute();
        $existing_thread = $stmt_find->fetch();

        if ($existing_thread) {
            return ['success' => true, 'thread_id' => $existing_thread['thread_id'], 'existed' => true];
        }

        // Create new DM thread
        $this->db->beginTransaction();
        try {
            $sql_create_thread = "INSERT INTO message_threads (type, title) VALUES ('direct', :title)";
            $stmt_create_thread = $this->db->prepare($sql_create_thread);
            // You might want a more descriptive title, e.g., names of participants
            $thread_title = "DM between user {$u1} and user {$u2}";
            $stmt_create_thread->bindParam(':title', $thread_title);
            $stmt_create_thread->execute();
            $thread_id = $this->db->lastInsertId();

            // Add participants
            $sql_add_participant = "INSERT INTO thread_participants (thread_id, user_id) VALUES (:thread_id, :user_id)";
            $stmt_add_participant = $this->db->prepare($sql_add_participant);

            $stmt_add_participant->bindParam(':thread_id', $thread_id, \PDO::PARAM_INT);
            $stmt_add_participant->bindParam(':user_id', $u1, \PDO::PARAM_INT);
            $stmt_add_participant->execute();

            $stmt_add_participant->bindParam(':thread_id', $thread_id, \PDO::PARAM_INT);
            $stmt_add_participant->bindParam(':user_id', $u2, \PDO::PARAM_INT);
            $stmt_add_participant->execute();

            $this->db->commit();
            return ['success' => true, 'thread_id' => $thread_id, 'existed' => false];
        } catch (\PDOException $e) {
            $this->db->rollBack();
            error_log("PDOException creating DM thread for users {$u1}, {$u2}: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error creating DM thread.'];
        }
    }

    // Find or Create a Project-Specific Thread
    // $participant_ids should include all relevant users for the thread type
    public function findOrCreateProjectThread($project_id, $thread_type, $participant_ids, $title = null) {
        if (!in_array($thread_type, ['project_client_admin_freelancer', 'project_admin_client', 'project_admin_freelancer'])) {
            return ['success' => false, 'message' => 'Invalid project thread type.'];
        }
        if (empty($participant_ids)) {
             return ['success' => false, 'message' => 'Participant IDs cannot be empty for project thread.'];
        }

        // Check if a thread of this type already exists for this project
        // This check might need to be more sophisticated if multiple threads of same type per project are allowed (not typical for these types)
        $sql_find = "SELECT thread_id FROM message_threads WHERE project_id = :project_id AND type = :type";
        $stmt_find = $this->db->prepare($sql_find);
        $stmt_find->bindParam(':project_id', $project_id, \PDO::PARAM_INT);
        $stmt_find->bindParam(':type', $thread_type);
        $stmt_find->execute();
        $existing_thread = $stmt_find->fetch();

        if ($existing_thread) {
            // Ensure all current participants are in the existing thread
            $this->ensureParticipantsInThread($existing_thread['thread_id'], $participant_ids);
            return ['success' => true, 'thread_id' => $existing_thread['thread_id'], 'existed' => true];
        }

        // Create new project thread
        $this->db->beginTransaction();
        try {
            $default_title = $title ?: ucfirst(str_replace('_', ' ', $thread_type)) . " - Project " . $project_id;
            $sql_create_thread = "INSERT INTO message_threads (project_id, type, title, linked_folder_path) VALUES (:project_id, :type, :title, :linked_folder_path)";
            $stmt_create_thread = $this->db->prepare($sql_create_thread);

            // Basic linked folder path, could be made more robust
            $linked_folder_path = "uploads/projects/{$project_id}/threads/{$thread_type}";
            // Ensure this directory is created by a separate mechanism if it needs to exist on filesystem.

            $stmt_create_thread->bindParam(':project_id', $project_id, \PDO::PARAM_INT);
            $stmt_create_thread->bindParam(':type', $thread_type);
            $stmt_create_thread->bindParam(':title', $default_title);
            $stmt_create_thread->bindParam(':linked_folder_path', $linked_folder_path);
            $stmt_create_thread->execute();
            $thread_id = $this->db->lastInsertId();

            $this->ensureParticipantsInThread($thread_id, $participant_ids);

            $this->db->commit();
            return ['success' => true, 'thread_id' => $thread_id, 'existed' => false];
        } catch (\PDOException $e) {
            $this->db->rollBack();
            error_log("PDOException creating project thread for project {$project_id}, type {$thread_type}: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error creating project thread.'];
        }
    }

    private function ensureParticipantsInThread($thread_id, $participant_ids) {
        $sql_add_participant = "INSERT IGNORE INTO thread_participants (thread_id, user_id) VALUES (:thread_id, :user_id)";
        $stmt_add_participant = $this->db->prepare($sql_add_participant); // IGNORE to avoid error if participant already exists
        foreach (array_unique($participant_ids) as $user_id) {
            $stmt_add_participant->bindParam(':thread_id', $thread_id, \PDO::PARAM_INT);
            $stmt_add_participant->bindParam(':user_id', $user_id, \PDO::PARAM_INT);
            $stmt_add_participant->execute();
        }
    }


    // Send a message
    public function sendMessage($thread_id, $sender_id, $content, $attachment_url = null, $current_user_role) {
        // Validate thread existence and sender participation
        $stmt_thread_check = $this->db->prepare("SELECT mt.type, mt.project_id, tp.user_id as participant
                                                FROM message_threads mt
                                                LEFT JOIN thread_participants tp ON mt.thread_id = tp.thread_id AND tp.user_id = :sender_id
                                                WHERE mt.thread_id = :thread_id");
        $stmt_thread_check->bindParam(':thread_id', $thread_id, \PDO::PARAM_INT);
        $stmt_thread_check->bindParam(':sender_id', $sender_id, \PDO::PARAM_INT);
        $stmt_thread_check->execute();
        $thread_info = $stmt_thread_check->fetch();

        if (!$thread_info) {
            return ['success' => false, 'message' => 'Thread not found.'];
        }
        if (empty($thread_info['participant'])) { // Sender is not part of this thread
             return ['success' => false, 'message' => 'You are not a participant of this thread.'];
        }
        if (empty(trim($content)) && empty($attachment_url)) {
            return ['success' => false, 'message' => 'Message content cannot be empty unless an attachment is provided.'];
        }

        $requires_approval = false;
        $approval_status = null;

        // Type A: Client-Admin-Freelancer thread specific logic
        if ($thread_info['type'] === 'project_client_admin_freelancer' && $current_user_role === 'freelancer') {
            $requires_approval = true;
            $approval_status = 'pending';
        }

        $sql = "INSERT INTO messages (thread_id, sender_id, content, attachment_url, requires_approval, approval_status)
                VALUES (:thread_id, :sender_id, :content, :attachment_url, :requires_approval, :approval_status)";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':thread_id', $thread_id, \PDO::PARAM_INT);
        $stmt->bindParam(':sender_id', $sender_id, \PDO::PARAM_INT);
        $stmt->bindParam(':content', $content);
        $stmt->bindParam(':attachment_url', $attachment_url);
        $stmt->bindParam(':requires_approval', $requires_approval, \PDO::PARAM_BOOL);
        $stmt->bindParam(':approval_status', $approval_status);

        try {
            if ($stmt->execute()) {
                $message_id = $this->db->lastInsertId();
                // Update thread's updated_at timestamp
                $stmt_update_thread = $this->db->prepare("UPDATE message_threads SET updated_at = CURRENT_TIMESTAMP WHERE thread_id = :thread_id");
                $stmt_update_thread->bindParam(':thread_id', $thread_id, \PDO::PARAM_INT);
                $stmt_update_thread->execute();

                // Potentially trigger notifications here

                return ['success' => true, 'message' => 'Message sent.', 'message_id' => $message_id, 'approval_status' => $approval_status];
            } else {
                error_log("Message sending failed for thread ID {$thread_id}, sender ID {$sender_id}");
                return ['success' => false, 'message' => 'Failed to send message.'];
            }
        } catch (\PDOException $e) {
            error_log("PDOException sending message for thread ID {$thread_id}, sender ID {$sender_id}: " . $e->getMessage());
            return ['success' => false, 'message' => 'Database error sending message.'];
        }
    }

    // Get messages for a thread (with pagination)
    public function getThreadMessages($thread_id, $current_user_id, $current_user_role, $limit = 20, $offset = 0) {
        // Validate thread existence and user participation
        $stmt_thread_check = $this->db->prepare("SELECT mt.type, mt.project_id, tp.user_id as participant
                                                FROM message_threads mt
                                                LEFT JOIN thread_participants tp ON mt.thread_id = tp.thread_id AND tp.user_id = :current_user_id
                                                WHERE mt.thread_id = :thread_id");
        $stmt_thread_check->bindParam(':thread_id', $thread_id, \PDO::PARAM_INT);
        $stmt_thread_check->bindParam(':current_user_id', $current_user_id, \PDO::PARAM_INT);
        $stmt_thread_check->execute();
        $thread_info = $stmt_thread_check->fetch();

        if (!$thread_info) {
            return ['success' => false, 'message' => 'Thread not found.'];
        }
        if (empty($thread_info['participant'])) {
             return ['success' => false, 'message' => 'You are not authorized to view messages in this thread.'];
        }

        $sql = "SELECT m.*, u.username as sender_username, u.role as sender_role, u.profile_picture_url as sender_avatar
                FROM messages m
                JOIN users u ON m.sender_id = u.user_id
                WHERE m.thread_id = :thread_id";

        // Visibility logic for Type A threads
        if ($thread_info['type'] === 'project_client_admin_freelancer') {
            if ($current_user_role === 'client') {
                // Client sees own messages, admin messages, and approved freelancer messages
                $sql .= " AND (m.sender_id = :current_user_id OR u.role = 'admin' OR (u.role = 'freelancer' AND m.approval_status = 'approved'))";
            } elseif ($current_user_role === 'freelancer') {
                // Freelancer sees own messages, admin messages, client messages, and other freelancers' approved messages (or all if policy allows)
                // For simplicity now: freelancer sees their own, admin, client, and approved messages from other freelancers
                 $sql .= " AND (m.sender_id = :current_user_id OR u.role = 'admin' OR u.role = 'client' OR (m.sender_id != :current_user_id AND u.role = 'freelancer' AND m.approval_status = 'approved'))";
            }
            // Admin sees all messages in Type A (no additional filter needed beyond base)
        }
        // For Type B (Admin-Client), Type C (Admin-Freelancer), and Direct, all participants see all messages.

        $sql .= " ORDER BY m.sent_at ASC LIMIT :limit OFFSET :offset";

        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':thread_id', $thread_id, \PDO::PARAM_INT);
        if (strpos($sql, ':current_user_id') !== false) { // Bind only if placeholder exists
             $stmt->bindParam(':current_user_id', $current_user_id, \PDO::PARAM_INT);
        }
        $stmt->bindParam(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindParam(':offset', $offset, \PDO::PARAM_INT);

        $stmt->execute();
        $messages = $stmt->fetchAll();

        // Update last_read_at for the current user in this thread
        $stmt_update_read = $this->db->prepare("UPDATE thread_participants SET last_read_at = CURRENT_TIMESTAMP WHERE thread_id = :thread_id AND user_id = :current_user_id");
        $stmt_update_read->bindParam(':thread_id', $thread_id, \PDO::PARAM_INT);
        $stmt_update_read->bindParam(':current_user_id', $current_user_id, \PDO::PARAM_INT);
        $stmt_update_read->execute();

        return ['success' => true, 'messages' => $messages, 'thread_type' => $thread_info['type']];
    }

    // List threads for a user (with last message preview)
    public function listUserThreads($user_id, $current_user_role) {
        // Subquery to get the last message details for each thread
        // This can be complex and performance-intensive on large datasets. Consider optimizations.
        $sql = "SELECT
                    mt.thread_id,
                    mt.type,
                    mt.title as thread_title,
                    mt.project_id,
                    p.title as project_title,
                    mt.updated_at as thread_updated_at,
                    (SELECT GROUP_CONCAT(u_other.username SEPARATOR ', ')
                        FROM thread_participants tp_other
                        JOIN users u_other ON tp_other.user_id = u_other.user_id
                        WHERE tp_other.thread_id = mt.thread_id AND tp_other.user_id != :user_id
                    ) as other_participants_usernames,
                    (SELECT COUNT(DISTINCT u_other.user_id)
                        FROM thread_participants tp_other
                        JOIN users u_other ON tp_other.user_id = u_other.user_id
                        WHERE tp_other.thread_id = mt.thread_id AND tp_other.user_id != :user_id
                    ) as other_participants_count,
                    (SELECT m.content FROM messages m WHERE m.thread_id = mt.thread_id ORDER BY m.sent_at DESC LIMIT 1) as last_message_content,
                    (SELECT m.sent_at FROM messages m WHERE m.thread_id = mt.thread_id ORDER BY m.sent_at DESC LIMIT 1) as last_message_sent_at,
                    (SELECT u_lm.username FROM messages m_lm JOIN users u_lm ON m_lm.sender_id = u_lm.user_id WHERE m_lm.thread_id = mt.thread_id ORDER BY m_lm.sent_at DESC LIMIT 1) as last_message_sender_username,
                    (SELECT COUNT(m_unread.message_id)
                        FROM messages m_unread
                        WHERE m_unread.thread_id = mt.thread_id
                        AND m_unread.sent_at > IFNULL(tp.last_read_at, '1970-01-01')
                        AND m_unread.sender_id != :user_id
                        -- Apply visibility rules for unread count for Type A threads
                        AND (mt.type != 'project_client_admin_freelancer'
                             OR ('$current_user_role' = 'admin')
                             OR ('$current_user_role' = 'client' AND (SELECT ur.role FROM users ur WHERE ur.user_id = m_unread.sender_id) != 'freelancer')
                             OR ('$current_user_role' = 'client' AND (SELECT ur.role FROM users ur WHERE ur.user_id = m_unread.sender_id) = 'freelancer' AND m_unread.approval_status = 'approved')
                             OR ('$current_user_role' = 'freelancer' AND (SELECT ur.role FROM users ur WHERE ur.user_id = m_unread.sender_id) != 'freelancer')
                             OR ('$current_user_role' = 'freelancer' AND (SELECT ur.role FROM users ur WHERE ur.user_id = m_unread.sender_id) = 'freelancer' AND m_unread.approval_status = 'approved')
                             OR ('$current_user_role' = 'freelancer' AND m_unread.sender_id = :user_id) -- See own messages as read effectively
                            )
                    ) as unread_messages_count
                FROM message_threads mt
                JOIN thread_participants tp ON mt.thread_id = tp.thread_id
                LEFT JOIN projects p ON mt.project_id = p.project_id
                WHERE tp.user_id = :user_id
                ORDER BY mt.updated_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':user_id', $user_id, \PDO::PARAM_INT);
        $stmt->execute();
        $threads = $stmt->fetchAll();
        return ['success' => true, 'threads' => $threads];
    }

    // Moderate a project message (Admin only)
    public function moderateProjectMessage($message_id, $new_approval_status, $admin_user_id) {
        if (!in_array($new_approval_status, ['approved', 'rejected'])) {
            return ['success' => false, 'message' => "Invalid approval status. Must be 'approved' or 'rejected'."];
        }

        // Check if message exists, requires approval, and is pending
        $stmt_check = $this->db->prepare("SELECT m.message_id, m.requires_approval, m.approval_status, m.thread_id, mt.type as thread_type, mt.project_id, m.sender_id
                                          FROM messages m
                                          JOIN message_threads mt ON m.thread_id = mt.thread_id
                                          WHERE m.message_id = :message_id");
        $stmt_check->bindParam(':message_id', $message_id, \PDO::PARAM_INT);
        $stmt_check->execute();
        $message_info = $stmt_check->fetch();

        if (!$message_info) {
            return ['success' => false, 'message' => 'Message not found.'];
        }
        if (!$message_info['requires_approval'] || $message_info['approval_status'] !== 'pending') {
            return ['success' => false, 'message' => 'Message does not require approval or is not pending. Current status: ' . $message_info['approval_status']];
        }
        if ($message_info['thread_type'] !== 'project_client_admin_freelancer') {
            return ['success' => false, 'message' => 'Message is not in a moderatable thread type.'];
        }


        $sql = "UPDATE messages SET approval_status = :new_approval_status, approved_by_admin_id = :admin_user_id
                WHERE message_id = :message_id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':new_approval_status', $new_approval_status);
        $stmt->bindParam(':admin_user_id', $admin_user_id, \PDO::PARAM_INT);
        $stmt->bindParam(':message_id', $message_id, \PDO::PARAM_INT);

        try {
            if ($stmt->execute()) {
                 if ($stmt->rowCount() > 0) {
                    // Trigger notification to Client (if approved) or Freelancer (if rejected)
                    // Notification logic would go here.
                    // Example: if ($new_approval_status === 'approved') { notifyClientOfNewMessage($message_info['project_id'], $message_id); }
                    return ['success' => true, 'message' => "Message {$new_approval_status}."];
                 } else {
                     return ['success' => false, 'message' => 'Message status not changed. It might have been moderated already.'];
                 }
            } else {
                error_log("Message moderation failed for message ID {$message_id} by admin {$admin_user_id}");
                return ['success' => false, 'message' => 'Failed to moderate message.'];
            }
        } catch (\PDOException $e) {
            error_log("PDOException moderating message ID {$message_id} by admin {$admin_user_id}: " . $e->getMessage());
            return ['success' => false, 'message'-> 'Database error during message moderation.'];
        }
    }

    public function getPendingApprovalMessages($admin_user_id) {
        // Admin can see all pending messages
        $sql = "SELECT m.*, u_sender.username as sender_username, p.title as project_title, mt.title as thread_title
                FROM messages m
                JOIN users u_sender ON m.sender_id = u_sender.user_id
                JOIN message_threads mt ON m.thread_id = mt.thread_id
                LEFT JOIN projects p ON mt.project_id = p.project_id
                WHERE m.requires_approval = TRUE AND m.approval_status = 'pending'
                AND mt.type = 'project_client_admin_freelancer'
                ORDER BY m.sent_at ASC";
        // Could add project filtering if admin is assigned to specific projects.
        // For now, assumes admin sees all.
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $messages = $stmt->fetchAll();
        return ['success' => true, 'messages' => $messages];
    }

}
?>
