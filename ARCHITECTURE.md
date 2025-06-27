# Architex Axis Management Suite - System Architecture

## 1. Overall System Architecture

The Architex Axis Management Suite is a web-based platform designed to facilitate project management and collaboration between clients, freelancers, and administrators. The system is composed of three primary components: a frontend application, a backend API, and a relational database.

### 1.1. Main Components

*   **Frontend Application:**
    *   **Description:** A single-page application (SPA) that provides the user interface for all user roles. Users interact with the system through this interface to manage projects, communicate, and perform role-specific tasks.
    *   **Technology:** Built with [React](https://reactjs.org/) and [TypeScript](https://www.typescriptlang.org/), utilizing components for various UI elements and pages. State management is likely handled via React Context (as seen in `AuthContext.tsx`) and component state.
    *   **Key UI Elements:**
        *   Dashboards for different user roles.
        *   Project creation and management views.
        *   Messaging interfaces.
        *   Time tracking and reporting tools.
        *   User profile and settings pages.

*   **Backend API:**
    *   **Description:** A RESTful API server that handles business logic, data processing, and communication with the database. It exposes endpoints for user authentication, project management, messaging, file handling, time logging, and other core functionalities.
    *   **Technology:** Developed in [PHP](https://www.php.net/). It appears to be a custom-built API without a specific framework mentioned, using `composer` for dependency management.
    *   **Key Services/Libraries:**
        *   `firebase/php-jwt` for JSON Web Token (JWT) authentication.
        *   Standard PHP functions for database interaction (likely PDO or mysqli, though not explicitly stated in the initial READMEs, `db_connect.php` would clarify this).
        *   Custom service classes for different modules (e.g., `AuthService.php`, `ProjectService.php`, `MessagingService.php`).

*   **Database:**
    *   **Description:** A relational database that stores all persistent data for the application, including user accounts, project details, messages, time logs, and billing information.
    *   **Technology:** While not explicitly named in the main READMEs, the `backend/schema.sql` and `.multicoder/task/database_schema.md` strongly indicate a SQL-based database. Given PHP's common pairings, this is likely **MySQL** or PostgreSQL. The schema defines tables, columns, relationships, and constraints.
    *   **Key Data Points:** User credentials, project specifications, freelancer applications, communication threads, task details, time entries.

### 1.2. Technologies Used

*   **Frontend:**
    *   React (v18.2.0 based on `package.json` if available, otherwise assumed latest stable)
    *   TypeScript
    *   Vite (build tool)
    *   Tailwind CSS (styling)
    *   Jest & Playwright (testing)
*   **Backend:**
    *   PHP (version not specified, but likely 8.x given modern practices)
    *   Composer (dependency management)
    *   PHPUnit (testing)
    *   Key PHP Libraries: `firebase/php-jwt`
*   **Database:**
    *   SQL-based (likely MySQL, based on common PHP usage and syntax in schema files if reviewed).
*   **General:**
    *   Node.js (for frontend development environment and build processes)
    *   Git (version control)

### 1.3. Communication Flow

1.  **User Interaction:** Users interact with the React frontend in their web browser.
2.  **API Requests:** The frontend makes asynchronous HTTP requests (GET, POST, PUT, DELETE) to the backend PHP API to fetch data or perform actions. These requests typically include JSON payloads.
3.  **Authentication:**
    *   For protected endpoints, a JWT is sent in the `Authorization` header of API requests.
    *   The backend API validates the JWT to authenticate the user and authorize access to resources or actions.
    *   Login and registration endpoints handle user credential verification and JWT issuance.
4.  **Backend Processing:** The PHP backend receives requests, processes them (validates input, interacts with the database, performs business logic via its service classes), and prepares a response.
5.  **Database Interaction:** The backend API executes SQL queries against the database to Create, Read, Update, or Delete (CRUD) data.
6.  **API Response:** The backend sends a JSON response back to the frontend. Responses typically include a status (`success` or `error`), a message, and data (if any).
7.  **UI Update:** The frontend receives the API response and updates the UI dynamically to reflect changes or display fetched data.

This architecture allows for a separation of concerns, with the frontend handling presentation and user interaction, and the backend managing data and business logic.

## 2. User Roles and Permissions

The system defines three distinct user roles, each with specific capabilities and access levels. These roles are defined in the `users` table (`role` ENUM('admin', 'client', 'freelancer')).

### 2.1. Admin

Admins have the highest level of access and control over the system. They are responsible for managing users, overseeing projects, and ensuring smooth platform operation.

*   **User Management:**
    *   View all users (Clients, Freelancers, other Admins).
    *   Create, edit, and deactivate user accounts.
    *   Reset user passwords (implicitly).
    *   Manage user roles.
*   **Project Management:**
    *   View all projects in the system.
    *   Potentially assign freelancers to projects (though primary assignment might be client-driven or application-based).
    *   Modify project details or status if necessary.
    *   Oversee project file exchange areas.
*   **Communication Management:**
    *   Access and participate in all types of project message threads:
        *   `project_client_admin_freelancer` (Type A)
        *   `project_admin_client` (Type B)
        *   `project_admin_freelancer` (Type C)
    *   Moderate messages in Type A threads: Approve or reject messages sent by Freelancers before they are visible to Clients.
    *   Initiate and participate in direct messages (DMs) with any other user (Client, Freelancer, Admin).
*   **Time Log and Billing Oversight:**
    *   View time logs submitted by Freelancers across all projects (e.g., via `AdminTimeLogReportPage.tsx`).
    *   Manage billing information and potentially generate invoices (based on `billing_info` table).
*   **System Configuration:**
    *   Manage skills list (based on `ManageSkills.tsx`).
    *   General platform settings (not explicitly detailed but a common admin function).

### 2.2. Client

Clients are users who create projects and hire freelancers to complete them.

*   **Account Management:**
    *   Register and manage their own profile.
*   **Project Management:**
    *   Create new projects (defining title, description, budget, deadline - stored in `projects` table).
    *   View and manage their own projects (`MyProjects.tsx`).
    *   Review proposals from freelancers (`project_applications` table).
    *   Select and assign freelancers to their projects (potentially by accepting applications, which would update `project_assigned_freelancers`).
    *   Monitor project status.
*   **Communication:**
    *   Communicate with Admins via direct messages.
    *   Communicate with Admins and assigned Freelancers in `project_client_admin_freelancer` (Type A) threads. Messages from Freelancers in this thread are only visible to the Client after Admin approval.
    *   Communicate privately with Admins in `project_admin_client` (Type B) threads.
    *   **Restriction:** Cannot initiate direct messages with Freelancers or other Clients. Can only DM Admins.
*   **Time Log and Billing:**
    *   View time logs submitted by Freelancers for their projects (`ClientProjectTimeLogPage.tsx`).
    *   Manage payments and view billing history related to their projects (`billing_info` table).
*   **File Management:**
    *   Access project-specific file exchange areas relevant to their communication threads (Type A and Type B).

### 2.3. Freelancer

Freelancers are users who apply for and work on projects posted by Clients.

*   **Account Management:**
    *   Register and manage their own profile, including skills, bio, etc.
*   **Project Engagement:**
    *   Browse available projects (`ProjectBrowser.tsx`).
    *   Submit proposals/applications for projects (`project_applications` table).
    *   View projects they have applied to (`MyApplications.tsx`).
    *   View projects they are assigned to (`MyAssignedProjects.tsx`).
*   **Task Management:**
    *   View and manage assigned job cards/tasks within a project (`MyJobCards.tsx`, `job_cards` table).
    *   Update task status.
*   **Time Tracking:**
    *   Log time spent on tasks/projects (`FreelancerTimeTrackingPage.tsx`, `time_logs` table).
*   **Communication:**
    *   Communicate with Admins via direct messages.
    *   Communicate with Admins in `project_admin_freelancer` (Type C) threads for their assigned projects.
    *   Communicate with Admins and (conditionally) Clients in `project_client_admin_freelancer` (Type A) threads. Messages sent by the Freelancer in this thread require Admin approval before being visible to the Client. They see the status of their messages (pending, approved, rejected).
    *   **Restriction:** Cannot initiate direct messages with Clients or other Freelancers. Can only DM Admins.
*   **File Management:**
    *   Access project-specific file exchange areas relevant to their communication threads (Type A and Type C).

## 3. Core Workflows for Each User Role

This section outlines the typical processes and interactions for each user role within the Architex Axis Management Suite.

### 3.1. Admin Workflow

Admins are central to the platform's operation, managing users, projects, and communications.

1.  **User Management:**
    *   **View Users:** Navigates to a user management dashboard (e.g., `UserManagement.tsx`).
    *   **Filter/Search:** Filters user list by role, status, or searches by name/email.
    *   **Add User:** Can manually add new users, assigning them a role and initial credentials.
    *   **Edit User:** Modifies user details, changes roles, or updates profile information.
    *   **Activate/Deactivate User:** Changes the `is_active` status of a user account.

2.  **Project Oversight:**
    *   **View All Projects:** Accesses a comprehensive list of all projects on the platform (e.g., `ProjectManagement.tsx`).
    *   **Monitor Project Status:** Tracks the progress of projects, identifying any that are stalled or require attention.
    *   **Intervene if Necessary:** May edit project details, reassign roles, or mediate disputes if they arise.
    *   **Manage Project Files:** Ensures file exchange areas are functioning correctly.

3.  **Communication Management & Moderation:**
    *   **Access Project Threads:** Joins or views any of the three project-specific message threads (Type A, B, C) for any project (e.g., via `AdminProjectMessagingPage.tsx`).
    *   **Moderate Freelancer Messages (Type A Threads):**
        *   Receives notifications or views a queue of messages from Freelancers pending approval.
        *   Reviews message content.
        *   **Approves:** Message becomes visible to the Client and other participants in the Type A thread.
        *   **Rejects:** Message remains hidden from the Client; the Freelancer sees the "Rejected" status.
    *   **Direct Messaging:** Initiates or responds to direct messages with Clients, Freelancers, or other Admins. This uses a general messaging interface (`MessagingPage.tsx` likely adapted for admin).
    *   **Facilitate Communication:** Ensures important information is relayed between parties if direct communication channels are restricted or require moderation.

4.  **Time Log and Billing Management:**
    *   **Review Time Logs:** Accesses reports of time logged by Freelancers across various projects (e.g., `AdminTimeLogReportPage.tsx`).
    *   **Verify Billing Information:** Checks the accuracy of data in the `billing_info` table.
    *   **Manage Invoices:** May be responsible for generating, sending, or tracking the status of invoices.
    *   **Handle Payment Disputes:** Investigates and resolves any issues related to billing or payments.

5.  **System Configuration:**
    *   **Manage Skills:** Adds, edits, or removes skills from the system's predefined list (e.g., `ManageSkills.tsx`), which Freelancers can then add to their profiles and Clients can use to search.

### 3.2. Client Workflow

Clients use the platform to get their projects completed by qualified freelancers.

1.  **Registration and Onboarding:**
    *   **Register:** Creates a new Client account (`RegisterPage.tsx`).
    *   **Complete Profile:** Fills in necessary personal and company details (`UserProfilePage.tsx`).

2.  **Project Creation and Posting:**
    *   **Create New Project:** Navigates to a "Create Project" form (e.g., `CreateProject.tsx`).
    *   **Define Project Details:** Enters project title, detailed description, required skills, budget, and deadline.
    *   **Submit Project:** Posts the project to the platform, making it visible (typically after admin review/approval, though not explicitly stated, or directly to freelancers).

3.  **Hiring Freelancers:**
    *   **Receive Applications/Proposals:** Gets notified of or views applications from Freelancers for their project(s) (via `project_applications` table data).
    *   **Review Freelancer Profiles:** Examines applicants' profiles, skills, experience, and proposal details.
    *   **Select Freelancer(s):** Chooses suitable Freelancer(s) for the project.
    *   **Award Project:** Formally assigns the project to the selected Freelancer(s) (e.g., by accepting an application, which backend logic then updates `project_assigned_freelancers`). This may trigger notifications.

4.  **Project Monitoring and Management:**
    *   **Track Progress:** Views the status of their active projects through a dashboard (`MyProjects.tsx`, `ClientDashboard.tsx`).
    *   **Communicate:**
        *   Uses Type A (`project_client_admin_freelancer`) threads to communicate with the assigned Freelancer(s) and Admin. Client sees Freelancer messages only after Admin approval.
        *   Uses Type B (`project_admin_client`) threads for private communication with the Admin regarding the project.
        *   Uses DMs for direct communication with Admins.
    *   **Review Deliverables:** Accesses files or work submitted by Freelancers (likely through the project file exchange linked in message threads).
    *   **Provide Feedback:** Gives feedback to Freelancers and Admins.

5.  **Time Log Review and Approval (if applicable):**
    *   **View Time Logs:** Reviews time logs submitted by Freelancers for their project tasks (`ClientProjectTimeLogPage.tsx`).
    *   **Approve/Query Logs:** May have a system to approve time logs before they are billed or query discrepancies.

6.  **Payment and Billing:**
    *   **Receive Invoices:** Gets invoices for completed work or project milestones (based on `billing_info`).
    *   **Process Payments:** Makes payments through the platform or as per agreed terms.
    *   **View Billing History:** Tracks past payments and outstanding amounts.

### 3.3. Freelancer Workflow

Freelancers use the platform to find work, manage their tasks, and get paid.

1.  **Registration and Profile Setup:**
    *   **Register:** Creates a new Freelancer account (`RegisterPage.tsx`).
    *   **Complete Profile:** Fills in detailed profile information, including:
        *   Personal details, contact information.
        *   Skills (selected from the admin-managed list).
        *   Bio, portfolio links, experience.
        *   Profile picture (`UserProfilePage.tsx`).

2.  **Project Discovery and Application:**
    *   **Browse Projects:** Uses a project browser (`ProjectBrowser.tsx`) to find projects matching their skills and interests.
    *   **Filter/Search:** Filters projects by category, required skills, budget, etc.
    *   **View Project Details:** Reviews the full description, requirements, and terms of interesting projects.
    *   **Submit Proposal:** Writes and submits a proposal for a project, outlining their approach, timeline, and bid (data stored in `project_applications`).
    *   **Track Applications:** Monitors the status of their submitted applications (`MyApplications.tsx`).

3.  **Project Execution (Once Hired):**
    *   **Notification of Assignment:** Receives a notification when assigned to a project.
    *   **View Assigned Projects:** Accesses a list of their current projects (`MyAssignedProjects.tsx`).
    *   **Understand Requirements:** Reviews project details, job cards, and any initial instructions.
    *   **Manage Tasks/Job Cards:**
        *   Views assigned tasks or job cards (`MyJobCards.tsx` from `job_cards` table).
        *   Updates the status of tasks (e.g., 'todo', 'in_progress', 'completed').
    *   **Communicate:**
        *   Uses Type A (`project_client_admin_freelancer`) threads to communicate with the Admin and (once approved by Admin) the Client. Understands their messages to this thread require Admin approval.
        *   Uses Type C (`project_admin_freelancer`) threads for private communication with the Admin regarding the project.
        *   Uses DMs for direct communication with Admins.
    *   **Submit Work:** Uploads deliverables or provides updates as per project requirements (likely through project file exchange linked in message threads).

4.  **Time Tracking:**
    *   **Log Time:** Uses a time tracking interface (`FreelancerTimeTrackingPage.tsx`) to record hours worked on specific tasks or projects.
    *   **Add Notes:** Includes descriptions of work done during the logged time (data stored in `time_logs`).
    *   **Submit Time Logs:** Regularly submits time logs for review/approval.

5.  **Billing and Payment:**
    *   **Await Payment:** Once work is completed and time logs/invoices are processed (often by Admin/Client).
    *   **View Payment History:** Tracks payments received.

## 4. Key System Workflows

This section describes critical end-to-end processes that involve multiple user roles and system components.

### 4.1. User Authentication Workflow

This workflow covers how users register, log in, and maintain authenticated sessions.

1.  **Registration:**
    *   A new user navigates to the registration page (`RegisterPage.tsx`).
    *   They fill out the registration form, providing username, email, password, and selecting a role (Client, Freelancer). Admin role is likely assigned manually by another Admin.
    *   The frontend sends a POST request to `/api.php?action=register_user` with the user details.
    *   The backend `AuthService.php` (or similar) validates the input (e.g., unique email/username, password strength).
    *   It hashes the password using `password_hash()`.
    *   A new record is created in the `users` table.
    *   The backend returns a success message. The user might be automatically logged in or redirected to the login page.

2.  **Login:**
    *   A user navigates to the login page (`LoginPage.tsx`).
    *   They enter their email and password.
    *   The frontend sends a POST request to `/api.php?action=login_user` with the credentials.
    *   The backend `AuthService.php` retrieves the user record from the `users` table based on the email.
    *   It verifies the provided password against the stored `password_hash` using `password_verify()`.
    *   If credentials are valid, the backend generates a JWT using `firebase/php-jwt` (via `JWTHandler.php`). The JWT includes user ID, role, and an expiration time.
    *   The backend returns the JWT and user information (role, name, etc.) to the frontend.
    *   The frontend stores the JWT (e.g., in local storage or a secure cookie) and user data in its state (`AuthContext.tsx`). The user is redirected to their respective dashboard.

3.  **Authenticated Session:**
    *   For subsequent requests to protected API endpoints, the frontend includes the JWT in the `Authorization: Bearer {token}` header.
    *   The backend API, for each protected route, uses a middleware or a function (likely in `RequestHandler.php` or `AuthService.php`) to validate the JWT.
    *   If the JWT is valid and not expired, the request proceeds, and the user's identity (e.g., `user_id`, `role`) is available to the backend logic.
    *   If the JWT is invalid or expired, the API returns an authentication error (e.g., 401 Unauthorized), and the frontend redirects the user to the login page.

4.  **Logout:**
    *   The user clicks a logout button.
    *   The frontend removes the JWT from storage and clears user data from its state.
    *   Optionally, the frontend can send a request to a backend endpoint to invalidate the token on the server-side if a token blocklist is maintained (not explicitly detailed but good practice).
    *   The user is redirected to the login page or homepage.

### 4.2. Project Creation and Management Workflow

This workflow describes how projects are initiated by Clients and managed.

1.  **Client Creates Project:**
    *   The Client, after logging in, navigates to the "Create Project" interface (`CreateProject.tsx`).
    *   They fill in project details: title, description, budget, deadline, required skills.
    *   On submission, the frontend sends a POST request (e.g., `/api.php?action=create_project`) to the backend.
    *   The backend (`ProjectCrudService.php` or similar) validates the input and creates a new record in the `projects` table, associating it with the `client_id` of the logged-in Client.
    *   The project status is initially set (e.g., 'open').

2.  **Project Visibility & Freelancer Application (Covered in 4.3)**

3.  **Client Manages Project:**
    *   The Client can view their projects on their dashboard (`MyProjects.tsx`).
    *   They can potentially edit project details (if allowed by the system after posting).
    *   They monitor applications and assign freelancers (see 4.3).

4.  **Admin Oversight:**
    *   Admins can view all projects (`ProjectManagement.tsx`).
    *   They can monitor project statuses and intervene if necessary (e.g., resolve disputes, update details).

### 4.3. Freelancer Application and Assignment Workflow

This workflow details how Freelancers find projects and are assigned to them.

1.  **Freelancer Browses Projects:**
    *   A logged-in Freelancer navigates to the project browser (`ProjectBrowser.tsx`).
    *   The frontend fetches a list of 'open' projects from the backend (e.g., `/api.php?action=list_projects`).
    *   The Freelancer can filter and search for projects.

2.  **Freelancer Applies to Project:**
    *   The Freelancer selects a project and reviews its details.
    *   They submit a proposal, which may include a cover letter, bid, and estimated timeline.
    *   The frontend sends a POST request (e.g., `/api.php?action=apply_to_project`) with `project_id`, `freelancer_id` (from session), and proposal details.
    *   The backend (`ApplicationService.php` or similar) creates a record in the `project_applications` table with status 'pending'.

3.  **Client Reviews Applications:**
    *   The Client is notified of new applications for their project or views them on their project management page.
    *   They review Freelancer profiles and proposals.

4.  **Client Assigns Freelancer:**
    *   The Client accepts a Freelancer's application.
    *   This action triggers a backend process (e.g., `/api.php?action=accept_application`):
        *   The status of the `project_applications` record is updated to 'accepted'.
        *   A new record is created in the `project_assigned_freelancers` table linking the `project_id` and the `user_id` of the Freelancer.
        *   The project's status in the `projects` table might be updated to 'in_progress'.
        *   Other applications for the same slot might be automatically set to 'rejected' or remain open if multiple freelancers can be assigned.
        *   Notifications are sent to the accepted Freelancer.

5.  **Freelancer Starts Work:**
    *   The assigned Freelancer sees the project in their "Assigned Projects" list (`MyAssignedProjects.tsx`).
    *   They can now access project-specific job cards, communication channels (Type A and C), and begin logging time.

### 4.4. Messaging Workflow

This workflow details the complex communication system, referencing `docs/messaging_workflow.md`.

1.  **Direct Messaging (DM):**
    *   **Initiation:**
        *   Admins can DM any other user (Admin, Client, Freelancer).
        *   Clients can only DM Admins.
        *   Freelancers can only DM Admins.
        *   The frontend calls an endpoint like `get_messageable_users` to populate user lists for new DMs, enforcing these restrictions.
    *   **Sending/Receiving:** Messages are sent/received via endpoints like `send_direct_message` and `get_thread_messages`. The backend creates a `message_threads` record with `type='direct'` and adds participants to `thread_participants`.

2.  **Project-Specific Messaging:**
    *   These threads are linked to a `project_id`.
    *   **Type A: Client-Admin-Freelancer(s) (`project_client_admin_freelancer`)**
        *   **Participants:** Project Client, Admin(s), all assigned Freelancers.
        *   **Freelancer Message Flow:**
            1.  Freelancer sends a message. Backend flags it with `requires_approval=TRUE`, `approval_status='pending'`. Message is visible to the sending Freelancer and Admins.
            2.  Admin sees the pending message in their interface (`AdminProjectMessagingPage.tsx`).
            3.  Admin approves/rejects via `moderate_project_message` endpoint.
            4.  If approved, `approval_status='approved'`, message becomes visible to the Client and other participants in the Type A thread (respecting `thread_participants.visibility_level`).
            5.  If rejected, `approval_status='rejected'`, message remains hidden from Client. Sending Freelancer sees the status update.
        *   **Client/Admin Message Flow:** Messages sent by Clients or Admins in this thread do not require approval and are immediately visible to all participants.
    *   **Type B: Admin-Client (`project_admin_client`)**
        *   **Participants:** Admin(s), Project Client.
        *   **Purpose:** Private Admin-Client communication. Freelancers cannot access.
        *   Messages flow directly between participants.
    *   **Type C: Admin-Freelancer(s) (`project_admin_freelancer`)**
        *   **Participants:** Admin(s), all assigned Freelancers for the project.
        *   **Purpose:** Private Admin-Freelancer communication. Client cannot access.
        *   Messages flow directly between participants.
    *   **Thread Creation:** Typically initiated by an Admin or automatically when a project stage is reached. Backend API (`send_project_message` or similar) sets up the thread, participants, and their roles/visibility.
    *   **Accessing Threads:** Users see their relevant threads on a general `MessagingPage.tsx` or contextually within project views.

3.  **General Features:**
    *   Fetching messages: `get_thread_messages` endpoint with pagination and filtering based on user role and message status.
    *   Real-time updates: May use polling or WebSockets (not specified, polling more likely with simple PHP backend).
    *   File attachments: `messages.attachment_url` suggests file sharing within messages.
    *   Linked folders: `message_threads.linked_folder_path` for project file exchange.

### 4.5. Time Logging and Billing Workflow (High-Level)

This workflow outlines how time is tracked and billing is processed.

1.  **Freelancer Logs Time:**
    *   The Freelancer selects an assigned project/task (`MyJobCards.tsx`).
    *   They use the time tracking interface (`FreelancerTimeTrackingPage.tsx`) to input start time, end time (or duration), and notes.
    *   Frontend sends data to an endpoint like `/api.php?action=create_time_log`.
    *   Backend (`TimeLogService.php`) creates a record in the `time_logs` table, linking it to `job_card_id` and `freelancer_id`.

2.  **Client/Admin Reviews Time Logs:**
    *   Clients can view time logs for their projects (`ClientProjectTimeLogPage.tsx`).
    *   Admins can view comprehensive time reports (`AdminTimeLogReportPage.tsx`).
    *   Approval Process (Optional): There might be a formal approval step by Clients or Admins before billing.

3.  **Billing Process:**
    *   Based on approved time logs and project terms (e.g., hourly rates, fixed price milestones), billing records are generated in the `billing_info` table. This might be a manual Admin task or an automated process.
    *   Invoices are created (details not specified, but `billing_info.invoice_number` exists).
    *   Clients are notified of due payments.

4.  **Client Makes Payment:**
    *   Clients make payments (mechanism not detailed - could be external or integrated).
    *   The `billing_info.status` is updated to 'paid'.

## 5. Conceptual Diagrams

This section describes the key diagrams that would visually represent the system architecture and workflows. These are textual descriptions intended to guide the creation of actual visual diagrams.

### 5.1. High-Level System Architecture Diagram

*   **Purpose:** To provide a bird's-eye view of the main components and their interactions.
*   **Elements:**
    *   **Box 1: User (Web Browser)**
        *   Represents any user (Admin, Client, Freelancer) interacting via a web browser.
    *   **Box 2: Frontend (React SPA)**
        *   Label: "Frontend: React Single Page Application"
        *   Sub-items: UI Components, State Management, API Service (`apiService.ts`)
    *   **Box 3: Backend API (PHP Server)**
        *   Label: "Backend: PHP REST API"
        *   Sub-items: Authentication (JWT), Business Logic (Services: Auth, Project, Message, etc.), Database Interface (`db_connect.php`)
    *   **Box 4: Database (SQL Database)**
        *   Label: "Database: MySQL (or similar SQL DB)"
        *   Sub-items: Tables (Users, Projects, Messages, TimeLogs, etc.)
*   **Connections:**
    *   User <--> Frontend: (HTTP/HTTPS) - User interacts with UI.
    *   Frontend <--> Backend API: (HTTP/HTTPS REST API Calls, JSON) - Data requests and actions.
    *   Backend API <--> Database: (SQL Queries) - Data persistence and retrieval.
*   **Annotations:**
    *   Note "JWT" on the Frontend <-> Backend connection for authentication.

### 5.2. User Role Interaction Diagram

*   **Purpose:** To illustrate how different user roles interact with the major system modules or functionalities.
*   **Elements:**
    *   **Columns/Swimlanes for Roles:** Admin, Client, Freelancer.
    *   **Rows/Sections for Modules/Functionalities:**
        *   User Authentication (Register, Login)
        *   Profile Management
        *   Project Management (Create, View, Apply, Assign)
        *   Task Management (Job Cards)
        *   Time Tracking
        *   Messaging (Direct, Project Threads)
        *   Billing/Payments
        *   User/System Administration
*   **Interactions:**
    *   Place checks or short descriptions in cells where a role interacts with a module. For example:
        *   Client -> Project Management: "Creates Projects", "Reviews Applications"
        *   Freelancer -> Project Management: "Browses Projects", "Applies to Projects"
        *   Admin -> User Authentication: "Manages User Accounts"
        *   Admin -> Messaging: "Moderates Messages", "Full Access to Threads"
*   **Focus:** Show the distribution of responsibilities and access across roles.

### 5.3. Messaging Workflow Diagram (Focus on Type A Thread)

*   **Purpose:** To detail the specific flow of messages in a `project_client_admin_freelancer` (Type A) thread, highlighting the approval mechanism.
*   **Elements:**
    *   **Participants (as boxes/nodes):** Freelancer, Admin, Client.
    *   **System Component (as a box/node):** Backend System / Message Store.
*   **Flows (arrows indicating sequence and data):**
    1.  **Freelancer Sends Message:**
        *   Freelancer -> Backend System: "Submit Message (content, project_id, thread_type_A)"
        *   Backend System: "Store Message (status: pending_approval, requires_approval: true)"
        *   Backend System -> Freelancer: "Message View (status: pending)"
        *   Backend System -> Admin: "Notification / Message View (status: pending, actions: Approve/Reject)"
    2.  **Admin Action - Approve:**
        *   Admin -> Backend System: "Approve Message (message_id)"
        *   Backend System: "Update Message (status: approved)"
        *   Backend System -> Client: "Notification / Message View (message visible)"
        *   Backend System -> Freelancer: "Message View (status: approved)"
        *   Backend System -> Other Thread Participants: "Message View (message visible)"
    3.  **Admin Action - Reject (Alternative Flow):**
        *   Admin -> Backend System: "Reject Message (message_id)"
        *   Backend System: "Update Message (status: rejected)"
        *   Backend System -> Freelancer: "Message View (status: rejected)"
        *   (Client and other participants do not see the message)
*   **Annotations:**
    *   Clearly indicate the change in message visibility for the Client based on Admin action.
    *   Show that the Admin and the sending Freelancer can always see the message, but its status changes.

### 5.4. Data Flow for Project Application

*   **Purpose:** To illustrate how data moves when a freelancer applies for a project and a client accepts it.
*   **Elements:**
    *   **Actors:** Freelancer, Client, System (Frontend, Backend API, Database).
*   **Sequence:**
    1.  **Freelancer Views Project:** Frontend requests project list from Backend; Backend queries `projects` table.
    2.  **Freelancer Submits Application:**
        *   Freelancer (via Frontend) -> Backend API (`/api.php?action=apply_to_project`): (project_id, freelancer_id, proposal_text)
        *   Backend API -> Database: INSERT into `project_applications` (status: 'pending')
    3.  **Client Views Applications:**
        *   Client (via Frontend) -> Backend API (`/api.php?action=get_project_applications`): (project_id)
        *   Backend API -> Database: SELECT from `project_applications` WHERE project_id = X
        *   Backend API -> Frontend: List of applications
    4.  **Client Accepts Application:**
        *   Client (via Frontend) -> Backend API (`/api.php?action=accept_application`): (application_id)
        *   Backend API:
            *   Updates `project_applications` (status: 'accepted') for the given application_id.
            *   INSERT into `project_assigned_freelancers` (project_id, user_id (freelancer)).
            *   Optionally, updates `projects` (status: 'in_progress').
            *   (Notifications may be triggered here).
*   **Data Points:** Show key data being passed (e.g., project_id, user_id, status).

These textual descriptions should enable someone to create clear and informative visual diagrams representing the system's architecture and core processes.

---
