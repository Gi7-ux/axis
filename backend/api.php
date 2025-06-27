<?php
// Set default timezone (optional, but good practice)
date_default_timezone_set('UTC');

// Basic error reporting (turn off for production, log instead)
ini_set('display_errors', 1); // TODO: Set to 0 for production
ini_set('display_startup_errors', 1); // TODO: Set to 0 for production
error_reporting(E_ALL); // TODO: Adjust for production

// Autoload Composer dependencies (if JWTHandler or other libs are namespaced and handled by composer)
// For now, direct includes are used in services, but this would be for `firebase/php-jwt`
require_once __DIR__ . '/vendor/autoload.php'; // Ensure this path is correct

// Include service files
require_once __DIR__ . '/src/db_connect.php';
require_once __DIR__ . '/src/JWTHandler.php';
require_once __DIR__ . '/src/AuthService.php';
require_once __DIR__ . '/src/UserService.php';
require_once __DIR__ . '/src/SkillService.php';
require_once __DIR__ . '/src/ProjectCrudService.php';
require_once __DIR__ . '/src/MessagingService.php';
require_once __DIR__ . '/src/TimeLogService.php'; // Contains TimeLogService and BillingService

// --- Response Helper ---
function json_response($data, $status_code = 200) {
    http_response_code($status_code);
    header('Content-Type: application/json');
    // Basic CORS headers - adjust for your specific needs and security policy
    header("Access-Control-Allow-Origin: *"); // TODO: Restrict to your frontend domain in production
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

    if ($status_code >= 400 && !isset($data['success'])) {
        // Ensure error responses have a 'success: false' field if not already set
        if (is_array($data) && !isset($data['success'])) {
            $data['success'] = false;
        } elseif (is_string($data)) {
            $data = ['success' => false, 'message' => $data];
        }
    }
    echo json_encode($data);
    exit;
}

// Handle OPTIONS pre-flight requests for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    json_response(null, 204); // No Content
}


// --- Request Handling ---
$action = $_GET['action'] ?? null;
$request_method = $_SERVER['REQUEST_METHOD'];
$request_data = [];

if ($request_method === 'POST' || $request_method === 'PUT') {
    $json_input = file_get_contents('php://input');
    $request_data = json_decode($json_input, true);
    if (json_last_error() !== JSON_ERROR_NONE && !empty($json_input)) {
        json_response(['message' => 'Invalid JSON input: ' . json_last_error_msg()], 400);
    }
} elseif ($request_method === 'GET' || $request_method === 'DELETE') {
    // For GET/DELETE, data usually comes from query string, handled per action.
    // $_GET is already available. For DELETE, sometimes body is used, but less standard.
    // If DELETE uses body, it would be similar to POST/PUT.
}


// --- Authentication & Authorization ---
$current_user_id = null;
$current_user_role = null;

// List of actions that do NOT require authentication
$public_actions = [
    'login_user',
    'register_user',
    'list_open_projects', // Example: public listing of open projects
    'get_public_project_details', // Example
    'list_skills' // Skills list is public
];

if (!in_array($action, $public_actions)) {
    $jwt_valid = Architex\JWTHandler::validateToken();
    if (!$jwt_valid) {
        json_response(['message' => 'Authentication required or token invalid.'], 401);
    }
    $current_user_id = Architex\JWTHandler::getUserIdFromAuthHeader();
    $current_user_role = Architex\JWTHandler::getUserRoleFromAuthHeader();

    if (!$current_user_id || !$current_user_role) {
        // This case should ideally be caught by validateToken if token is malformed
        json_response(['message' => 'Authentication token is valid but user data is missing.'], 401);
    }
}

// --- Service Instantiation ---
$auth_service = new Architex\AuthService();
$user_service = new Architex\UserService();
$skill_service = new Architex\SkillService();
$project_service = new Architex\ProjectCrudService();
$messaging_service = new Architex\MessagingService();
$time_log_service = new Architex\TimeLogService();
$billing_service = new Architex\BillingService();


// --- Routing ---
try {
    switch ($action) {
        // --- Auth ---
        case 'register_user': // POST
            if ($request_method !== 'POST') json_response(['message' => 'Method Not Allowed'], 405);
            $result = $auth_service->registerUser($request_data);
            json_response($result, $result['success'] ? 201 : 400);
            break;
        case 'login_user': // POST
            if ($request_method !== 'POST') json_response(['message' => 'Method Not Allowed'], 405);
            $result = $auth_service->loginUser($request_data);
            json_response($result, $result['success'] ? 200 : 401);
            break;
        case 'logout_user': // POST (optional, for token blocklisting if implemented)
             if ($request_method !== 'POST') json_response(['message' => 'Method Not Allowed'], 405);
            // $result = $auth_service->logoutUser($current_user_id); // Needs token from header
            json_response(['success' => true, 'message' => 'Logged out client-side.'], 200); // Stateless logout
            break;

        // --- User ---
        case 'get_user_profile': // GET user_id={id}
            if ($request_method !== 'GET') json_response(['message' => 'Method Not Allowed'], 405);
            $user_id_to_view = $_GET['user_id'] ?? $current_user_id; // Default to own profile
            $result = $user_service->getUserProfile((int)$user_id_to_view);
            json_response($result, $result['success'] ? 200 : ($result['message'] === 'User not found.' ? 404 : 400));
            break;
        case 'update_user_profile': // PUT user_id={id}
            if ($request_method !== 'PUT') json_response(['message' => 'Method Not Allowed'], 405);
            $user_id_to_update = $_GET['user_id'] ?? $current_user_id;
            if (empty($user_id_to_update)) json_response(['message' => 'User ID for update is required.'], 400);
            $result = $user_service->updateUserProfile((int)$user_id_to_update, $request_data, $current_user_id, $current_user_role);
            json_response($result, $result['success'] ? 200 : 400);
            break;
        case 'list_users': // GET (Admin only)
            if ($request_method !== 'GET') json_response(['message' => 'Method Not Allowed'], 405);
            if ($current_user_role !== 'admin') json_response(['message' => 'Unauthorized'], 403);
            $filters = $_GET; // Pass all GET params as filters
            $result = $user_service->listUsers($current_user_role, $filters);
            json_response($result, $result['success'] ? 200 : 400);
            break;
        case 'set_user_active_status': // PUT (Admin only) user_id={id}
            if ($request_method !== 'PUT') json_response(['message' => 'Method Not Allowed'], 405);
            if ($current_user_role !== 'admin') json_response(['message' => 'Unauthorized'], 403);
            $user_id_to_change = $_GET['user_id'] ?? null;
            $is_active = $request_data['is_active'] ?? null;
            if (empty($user_id_to_change) || !is_bool($is_active)) json_response(['message' => 'User ID and is_active (boolean) are required.'], 400);
            $result = $user_service->setUserActiveStatus((int)$user_id_to_change, $is_active, $current_user_role);
            json_response($result, $result['success'] ? 200 : 400);
            break;
        case 'update_user_skills': // PUT user_id={id} (Freelancer or Admin)
            if ($request_method !== 'PUT') json_response(['message' => 'Method Not Allowed'], 405);
            $user_id_for_skills = $_GET['user_id'] ?? $current_user_id;
            $target_user_profile = $user_service->getUserProfile((int)$user_id_for_skills);
            if (!$target_user_profile['success']) json_response($target_user_profile, 404);

            if ($current_user_role !== 'admin' && ($target_user_profile['user']['role'] !== 'freelancer' || $current_user_id != $user_id_for_skills)) {
                 json_response(['message' => 'Unauthorized to update skills for this user.'], 403);
            }
            $skill_ids = $request_data['skill_ids'] ?? [];
            if (!is_array($skill_ids)) json_response(['message' => 'skill_ids must be an array.'], 400);
            $result = $user_service->updateUserSkills((int)$user_id_for_skills, $skill_ids);
            json_response($result, $result['success'] ? 200 : 400);
            break;
        case 'get_messageable_users': // GET
            if ($request_method !== 'GET') json_response(['message' => 'Method Not Allowed'], 405);
            $result = $user_service->getMessageableUsers($current_user_id, $current_user_role);
            json_response($result, $result['success'] ? 200 : 400);
            break;

        // --- Skills (Public list, Admin CRUD) ---
        case 'list_skills': // GET (Public)
            if ($request_method !== 'GET') json_response(['message' => 'Method Not Allowed'], 405);
            $result = $skill_service->listSkills();
            json_response($result, $result['success'] ? 200 : 400);
            break;
        case 'create_skill': // POST (Admin only)
            if ($request_method !== 'POST') json_response(['message' => 'Method Not Allowed'], 405);
            if ($current_user_role !== 'admin') json_response(['message' => 'Unauthorized'], 403);
            $result = $skill_service->createSkill($request_data, $current_user_role);
            json_response($result, $result['success'] ? 201 : 400);
            break;
        case 'update_skill': // PUT skill_id={id} (Admin only)
            if ($request_method !== 'PUT') json_response(['message' => 'Method Not Allowed'], 405);
            if ($current_user_role !== 'admin') json_response(['message' => 'Unauthorized'], 403);
            $skill_id = $_GET['skill_id'] ?? null;
            if (empty($skill_id)) json_response(['message' => 'Skill ID is required.'], 400);
            $result = $skill_service->updateSkill((int)$skill_id, $request_data, $current_user_role);
            json_response($result, $result['success'] ? 200 : 400);
            break;
        case 'delete_skill': // DELETE skill_id={id} (Admin only)
            if ($request_method !== 'DELETE') json_response(['message' => 'Method Not Allowed'], 405);
            if ($current_user_role !== 'admin') json_response(['message' => 'Unauthorized'], 403);
            $skill_id = $_GET['skill_id'] ?? null;
            if (empty($skill_id)) json_response(['message' => 'Skill ID is required.'], 400);
            $result = $skill_service->deleteSkill((int)$skill_id, $current_user_role);
            json_response($result, $result['success'] ? 200 : 400); // 204 No Content on success is also an option
            break;

        // --- Projects ---
        case 'create_project': // POST (Client or Admin)
            if ($request_method !== 'POST') json_response(['message' => 'Method Not Allowed'], 405);
            $result = $project_service->createProject($request_data, $current_user_id, $current_user_role);
            json_response($result, $result['success'] ? 201 : 400);
            break;
        case 'get_project_details': // GET project_id={id}
        case 'get_public_project_details': // Alias for public access to open projects
            if ($request_method !== 'GET') json_response(['message' => 'Method Not Allowed'], 405);
            $project_id = $_GET['project_id'] ?? null;
            if (empty($project_id)) json_response(['message' => 'Project ID is required.'], 400);
            // For 'get_public_project_details', role might be null or a guest role
            $requesting_user_id = $action === 'get_public_project_details' ? null : $current_user_id;
            $requesting_user_role = $action === 'get_public_project_details' ? 'guest' : $current_user_role; // 'guest' role for public access check

            $result = $project_service->getProjectById((int)$project_id, $requesting_user_id, $requesting_user_role);

            // If it's a public request and project is not open, deny
            if ($action === 'get_public_project_details' && $result['success'] && $result['project']['status'] !== 'open') {
                 json_response(['success' => false, 'message' => 'This project is not publicly viewable.'], 403);
            }
            json_response($result, $result['success'] ? 200 : ($result['message'] === 'Project not found.' ? 404 : 403));
            break;
        case 'list_projects': // GET (role-based filtering)
        case 'list_open_projects': // Alias for public listing
            if ($request_method !== 'GET') json_response(['message' => 'Method Not Allowed'], 405);
            $filters = $_GET;
            $requesting_user_id = $current_user_id;
            $requesting_user_role = $current_user_role;
            if ($action === 'list_open_projects') {
                $filters['status'] = 'open'; // Force status to open
                // For a truly public list_open_projects, user_id and role might be null/guest
                // but ProjectCrudService::listProjects handles this by showing only 'open' if role is not client/admin/freelancer with specific access
                // To make it truly public without auth, you'd pass null for user_id and a 'guest' role.
                // For now, assuming it requires some role, or ProjectCrudService handles guest implicitly.
                 $requesting_user_id = null; // No specific user for public list
                 $requesting_user_role = 'guest'; // Special role for public listing logic in service
            }
            $result = $project_service->listProjects($requesting_user_id, $requesting_user_role, $filters);
            json_response($result, $result['success'] ? 200 : 400);
            break;
        case 'update_project': // PUT project_id={id}
            if ($request_method !== 'PUT') json_response(['message' => 'Method Not Allowed'], 405);
            $project_id = $_GET['project_id'] ?? null;
            if (empty($project_id)) json_response(['message' => 'Project ID is required.'], 400);
            $result = $project_service->updateProject((int)$project_id, $request_data, $current_user_id, $current_user_role);
            json_response($result, $result['success'] ? 200 : 400);
            break;
        case 'delete_project': // DELETE project_id={id} (Archive/Cancel)
            if ($request_method !== 'DELETE') json_response(['message' => 'Method Not Allowed'], 405);
            $project_id = $_GET['project_id'] ?? null;
            if (empty($project_id)) json_response(['message' => 'Project ID is required.'], 400);
            $result = $project_service->deleteProject((int)$project_id, $current_user_id, $current_user_role);
            json_response($result, $result['success'] ? 200 : 400);
            break;
        case 'apply_to_project': // POST project_id={id} (Freelancer only)
            if ($request_method !== 'POST') json_response(['message' => 'Method Not Allowed'], 405);
            if ($current_user_role !== 'freelancer') json_response(['message' => 'Only freelancers can apply to projects.'], 403);
            $project_id = $_GET['project_id'] ?? null;
            if (empty($project_id)) json_response(['message' => 'Project ID is required for application.'], 400);
            $result = $project_service->applyToProject((int)$project_id, $current_user_id, $request_data);
            json_response($result, $result['success'] ? 201 : 400);
            break;
        case 'list_project_applications': // GET project_id={id} (Client or Admin)
            if ($request_method !== 'GET') json_response(['message' => 'Method Not Allowed'], 405);
            $project_id = $_GET['project_id'] ?? null;
            if (empty($project_id)) json_response(['message' => 'Project ID is required.'], 400);
            $result = $project_service->listProjectApplications((int)$project_id, $current_user_id, $current_user_role);
            json_response($result, $result['success'] ? 200 : 403);
            break;
        case 'list_my_applications': // GET (Freelancer only)
            if ($request_method !== 'GET') json_response(['message' => 'Method Not Allowed'], 405);
            if ($current_user_role !== 'freelancer') json_response(['message' => 'Only freelancers can view their applications.'], 403);
            $result = $project_service->listMyApplications($current_user_id);
            json_response($result, $result['success'] ? 200 : 400);
            break;
        case 'accept_application': // POST application_id={id} (Client or Admin)
            if ($request_method !== 'POST') json_response(['message' => 'Method Not Allowed'], 405);
            $application_id = $_GET['application_id'] ?? null;
            if (empty($application_id)) json_response(['message' => 'Application ID is required.'], 400);
            $result = $project_service->acceptApplication((int)$application_id, $current_user_id, $current_user_role);
            json_response($result, $result['success'] ? 200 : 400);
            break;
        case 'reject_application': // POST application_id={id} (Client or Admin)
            if ($request_method !== 'POST') json_response(['message' => 'Method Not Allowed'], 405);
            $application_id = $_GET['application_id'] ?? null;
            if (empty($application_id)) json_response(['message' => 'Application ID is required.'], 400);
            $result = $project_service->rejectApplication((int)$application_id, $current_user_id, $current_user_role);
            json_response($result, $result['success'] ? 200 : 400);
            break;
        case 'list_assigned_projects': // GET (Freelancer only)
            if ($request_method !== 'GET') json_response(['message' => 'Method Not Allowed'], 405);
            if ($current_user_role !== 'freelancer') json_response(['message' => 'Only freelancers can view their assigned projects.'], 403);
            $result = $project_service->listAssignedProjects($current_user_id);
            json_response($result, $result['success'] ? 200 : 400);
            break;

        // --- Job Cards ---
        case 'create_job_card': // POST project_id={id} (Client or Admin)
            if ($request_method !== 'POST') json_response(['message' => 'Method Not Allowed'], 405);
            $project_id = $_GET['project_id'] ?? null;
            if(empty($project_id)) json_response(['message' => 'Project ID required.'], 400);
            $result = $project_service->createJobCard((int)$project_id, $request_data, $current_user_id, $current_user_role);
            json_response($result, $result['success'] ? 201 : 400);
            break;
        case 'update_job_card': // PUT job_card_id={id} (Client, Admin, or assigned Freelancer)
            if ($request_method !== 'PUT') json_response(['message' => 'Method Not Allowed'], 405);
            $job_card_id = $_GET['job_card_id'] ?? null;
            if(empty($job_card_id)) json_response(['message' => 'Job Card ID required.'], 400);
            $result = $project_service->updateJobCard((int)$job_card_id, $request_data, $current_user_id, $current_user_role);
            json_response($result, $result['success'] ? 200 : 400);
            break;
        case 'delete_job_card': // DELETE job_card_id={id} (Client or Admin)
            if ($request_method !== 'DELETE') json_response(['message' => 'Method Not Allowed'], 405);
            $job_card_id = $_GET['job_card_id'] ?? null;
            if(empty($job_card_id)) json_response(['message' => 'Job Card ID required.'], 400);
            $result = $project_service->deleteJobCard((int)$job_card_id, $current_user_id, $current_user_role);
            json_response($result, $result['success'] ? 200 : 400);
            break;
         case 'list_my_job_cards': // GET (Freelancer only)
            if ($request_method !== 'GET') json_response(['message' => 'Method Not Allowed'], 405);
            if ($current_user_role !== 'freelancer') json_response(['message' => 'Only freelancers can view their job cards.'], 403);
            $result = $project_service->listMyJobCards($current_user_id);
            json_response($result, $result['success'] ? 200 : 400);
            break;


        // --- Messaging ---
        case 'send_message': // POST (Authenticated users)
            if ($request_method !== 'POST') json_response(['message' => 'Method Not Allowed'], 405);
            $thread_id = $request_data['thread_id'] ?? null;
            $content = $request_data['content'] ?? '';
            $attachment_url = $request_data['attachment_url'] ?? null;
            if (empty($thread_id)) json_response(['message' => 'Thread ID is required.'], 400);
            $result = $messaging_service->sendMessage((int)$thread_id, $current_user_id, $content, $attachment_url, $current_user_role);
            json_response($result, $result['success'] ? 201 : 400);
            break;
        case 'get_thread_messages': // GET thread_id={id} (Authenticated users in thread)
            if ($request_method !== 'GET') json_response(['message' => 'Method Not Allowed'], 405);
            $thread_id = $_GET['thread_id'] ?? null;
            if (empty($thread_id)) json_response(['message' => 'Thread ID is required.'], 400);
            $limit = $_GET['limit'] ?? 20;
            $offset = $_GET['offset'] ?? 0;
            $result = $messaging_service->getThreadMessages((int)$thread_id, $current_user_id, $current_user_role, (int)$limit, (int)$offset);
            json_response($result, $result['success'] ? 200 : 403);
            break;
        case 'list_user_threads': // GET (Authenticated user)
            if ($request_method !== 'GET') json_response(['message' => 'Method Not Allowed'], 405);
            $result = $messaging_service->listUserThreads($current_user_id, $current_user_role);
            json_response($result, $result['success'] ? 200 : 400);
            break;
        case 'moderate_project_message': // POST message_id={id} (Admin only)
            if ($request_method !== 'POST') json_response(['message' => 'Method Not Allowed'], 405);
            if ($current_user_role !== 'admin') json_response(['message' => 'Unauthorized'], 403);
            $message_id = $_GET['message_id'] ?? null;
            $new_status = $request_data['approval_status'] ?? null;
            if (empty($message_id) || empty($new_status)) json_response(['message' => 'Message ID and new approval_status are required.'], 400);
            $result = $messaging_service->moderateProjectMessage((int)$message_id, $new_status, $current_user_id);
            json_response($result, $result['success'] ? 200 : 400);
            break;
        case 'get_pending_approval_messages': // GET (Admin only)
            if ($request_method !== 'GET') json_response(['message' => 'Method Not Allowed'], 405);
            if ($current_user_role !== 'admin') json_response(['message' => 'Unauthorized'], 403);
            $result = $messaging_service->getPendingApprovalMessages($current_user_id);
            json_response($result, $result['success'] ? 200 : 400);
            break;
        case 'find_or_create_dm_thread': // POST (Authenticated users)
            if ($request_method !== 'POST') json_response(['message' => 'Method Not Allowed'], 405);
            $user2_id = $request_data['user2_id'] ?? null;
            if(empty($user2_id)) json_response(['message' => 'User ID of the other participant (user2_id) is required.'], 400);
            // Authorization: Can current user DM target user? (Handled by UserService::getMessageableUsers on frontend, backend implicitly allows if roles are valid)
            $userServiceForDMCheck = new Architex\UserService();
            $messageableUsersResult = $userServiceForDMCheck->getMessageableUsers($current_user_id, $current_user_role);
            $canMessage = false;
            if ($messageableUsersResult['success']) {
                foreach ($messageableUsersResult['users'] as $mu) {
                    if ($mu['user_id'] == $user2_id) {
                        $canMessage = true;
                        break;
                    }
                }
            }
            if (!$canMessage) json_response(['success' => false, 'message' => 'You are not allowed to message this user.'], 403);

            $result = $messaging_service->findOrCreateDirectMessageThread($current_user_id, (int)$user2_id);
            json_response($result, $result['success'] ? 200 : 400); // 200 if found or created
            break;


        // --- Time Logs ---
        case 'create_time_log': // POST (Freelancer only)
            if ($request_method !== 'POST') json_response(['message' => 'Method Not Allowed'], 405);
            if ($current_user_role !== 'freelancer') json_response(['message' => 'Only freelancers can log time.'], 403);
            $result = $time_log_service->createTimeLog($request_data, $current_user_id);
            json_response($result, $result['success'] ? 201 : 400);
            break;
        case 'get_time_logs': // GET (Role-based filtering)
            if ($request_method !== 'GET') json_response(['message' => 'Method Not Allowed'], 405);
            $filters = $_GET;
            $result = $time_log_service->getTimeLogs($current_user_id, $current_user_role, $filters);
            json_response($result, $result['success'] ? 200 : 400);
            break;
        case 'update_time_log': // PUT time_log_id={id} (Freelancer or Admin)
            if ($request_method !== 'PUT') json_response(['message' => 'Method Not Allowed'], 405);
            $time_log_id = $_GET['time_log_id'] ?? null;
            if (empty($time_log_id)) json_response(['message' => 'Time Log ID is required.'], 400);
            $result = $time_log_service->updateTimeLog((int)$time_log_id, $request_data, $current_user_id, $current_user_role);
            json_response($result, $result['success'] ? 200 : 400);
            break;
        case 'delete_time_log': // DELETE time_log_id={id} (Freelancer or Admin)
            if ($request_method !== 'DELETE') json_response(['message' => 'Method Not Allowed'], 405);
            $time_log_id = $_GET['time_log_id'] ?? null;
            if (empty($time_log_id)) json_response(['message' => 'Time Log ID is required.'], 400);
            $result = $time_log_service->deleteTimeLog((int)$time_log_id, $current_user_id, $current_user_role);
            json_response($result, $result['success'] ? 200 : 400);
            break;

        // --- Billing ---
        case 'create_billing_record': // POST (Admin only)
            if ($request_method !== 'POST') json_response(['message' => 'Method Not Allowed'], 405);
            if ($current_user_role !== 'admin') json_response(['message' => 'Unauthorized'], 403);
            $result = $billing_service->createBillingRecord($request_data, $current_user_role);
            json_response($result, $result['success'] ? 201 : 400);
            break;
        case 'get_billing_records': // GET (Role-based filtering)
            if ($request_method !== 'GET') json_response(['message' => 'Method Not Allowed'], 405);
            $filters = $_GET;
            $result = $billing_service->getBillingRecords($current_user_id, $current_user_role, $filters);
            json_response($result, $result['success'] ? 200 : 400);
            break;
        case 'update_billing_record_status': // PUT billing_id={id} (Admin only)
            if ($request_method !== 'PUT') json_response(['message' => 'Method Not Allowed'], 405);
            if ($current_user_role !== 'admin') json_response(['message' => 'Unauthorized'], 403);
            $billing_id = $_GET['billing_id'] ?? null;
            $new_status = $request_data['status'] ?? null;
            $paid_date = $request_data['paid_date'] ?? null;
            if (empty($billing_id) || empty($new_status)) json_response(['message' => 'Billing ID and new status are required.'], 400);
            $result = $billing_service->updateBillingRecordStatus((int)$billing_id, $new_status, $paid_date, $current_user_role);
            json_response($result, $result['success'] ? 200 : 400);
            break;

        default:
            json_response(['message' => "Action '{$action}' not recognized or not implemented."], 404);
            break;
    }
} catch (\PDOException $e) {
    error_log("PDOException in API Router: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    json_response(['message' => 'A database error occurred. Please try again later.'], 500);
} catch (\Exception $e) {
    error_log("General Exception in API Router: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    json_response(['message' => 'An unexpected error occurred. Please try again later.'], 500);
}

?>
