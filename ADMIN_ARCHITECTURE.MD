# Architex Axis Management Suite - System Architecture (Admin Perspective)

## 1. Overall System Architecture

From an Administrator's viewpoint, the Architex Axis Management Suite is a platform requiring oversight of its interconnected components to ensure smooth operation, data integrity, and effective user management. Understanding this architecture is key to troubleshooting, managing configurations, and supporting users.

### 1.1. Main Components and Admin Relevance

*   **Frontend Application (React SPA):**
    *   **Admin Relevance:** While Admins use the same frontend application as other users, they have access to specific views and components designed for administrative tasks (e.g., `AdminDashboard.tsx`, `UserManagement.tsx`, `ProjectManagement.tsx`). The Admin must be aware that all user interactions, including their own, are processed through this React-based interface, which then communicates with the backend. Issues reported by any user often originate from or manifest in the frontend.

*   **Backend API (PHP Server):**
    *   **Admin Relevance:** This is the core engine Admins indirectly manage and rely on. All administrative actions (like user creation, project modification, message moderation) are translated into API calls to these PHP services.
        *   Admins should understand that the backend handles critical functions:
            *   **Authentication & Authorization:** Via JWTs; Admins manage user roles which dictate API access.
            *   **Business Logic:** Services like `AuthService.php`, `ProjectCrudService.php`, `MessagingService.php`, `TimeLogService.php` execute the rules and operations Admins initiate.
            *   **Database Interaction:** All data modifications and retrievals, including those for generating admin reports or user lists, go through the backend's database connection (`db_connect.php`).
        *   Knowledge of API endpoints (e.g., `/api.php?action=...`) can be useful for advanced troubleshooting or understanding system integrations, although direct API interaction is normally abstracted by admin UI tools.

*   **Database (SQL Database - Likely MySQL):**
    *   **Admin Relevance:** This is the authoritative source of all platform data. Admins are responsible for the integrity and proper management of this data, although typically through the application's interface.
        *   Key data Admins oversee includes: `users` (credentials, roles, status), `projects` (details, status, assignments), `messages` (content, approval status), `time_logs` (freelancer work records), `billing_info` (financial records).
        *   While direct database access is usually reserved for technical maintenance, understanding the schema (`.multicoder/task/database_schema.md`) helps Admins comprehend data relationships and the impact of their actions. For instance, deactivating a user in the `users` table has implications for their associated projects or messages.

### 1.2. Technologies Used (Admin Context)

*   **Frontend (React, TypeScript, Vite, Tailwind CSS):**
    *   **Admin Relevance:** Understanding these helps in communicating with developers about UI issues or feature requests for admin tools.
*   **Backend (PHP, Composer, PHPUnit):**
    *   **Admin Relevance:** Crucial for understanding the server-side operations. If performance issues arise or specific backend errors are reported, knowing it's a PHP environment is key. `composer.json` lists backend dependencies like `firebase/php-jwt` which handles authentication tokens.
*   **Database (SQL-based, likely MySQL):**
    *   **Admin Relevance:** Essential for data reporting, understanding data relationships, and potential manual data interventions under guidance in critical situations.
*   **General (Node.js, Git):**
    *   **Admin Relevance:** Node.js is part of the development/build toolchain for the frontend. Git is used for version control; Admins might be involved in approving deployments or understanding changes if they have a technical oversight role.

### 1.3. Communication Flow (Admin Perspective)

1.  **Admin Interaction with UI:** Admin uses their browser to interact with specific admin sections of the React frontend.
2.  **API Requests from Admin Tools:** Admin actions (e.g., approving a message, creating a user) trigger API requests from the frontend to the PHP backend. These are typically privileged operations requiring an Admin's JWT.
3.  **Backend Processing of Admin Commands:** The backend validates the Admin's JWT, then executes the requested command using its service layer (e.g., `UserService.php` to create a user, `MessagingService.php` to approve a message).
4.  **Database Updates:** Admin commands often result in significant data changes in the SQL database (e.g., updating user roles, project statuses, message visibility).
5.  **API Response to Frontend:** The backend confirms the action's success/failure, and the frontend updates the Admin's UI.
    *   **Admin Relevance:** Admins need to ensure this flow is robust. Failures at any point can impact their ability to manage the system. They are primary users of workflows that involve complex state changes and data validation across the entire stack.

This architectural understanding enables Administrators to effectively manage the platform, support users, and contribute to strategic decisions regarding system maintenance and development.

## 2. User Roles and Permissions (Admin Perspective)

From an administrative standpoint, understanding user roles is critical for managing access control, supporting users, and ensuring the platform operates as intended. The system's `users` table (with its `role` ENUM: 'admin', 'client', 'freelancer') is central to this.

### 2.1. Admin Role

Admins possess comprehensive access and control, acting as superusers responsible for the overall health and functionality of the platform.

*   **Core Responsibilities:**
    *   **User Lifecycle Management:** Creating, viewing, updating (details, roles, status), and deactivating all user accounts (Clients, Freelancers, other Admins) via tools like `UserManagement.tsx`. This includes password resets and managing account activation (`is_active` field in `users` table).
    *   **Project Oversight & Intervention:** Viewing all projects, modifying project details, changing statuses, and potentially re-assigning users or roles within projects. This is managed through interfaces like `ProjectManagement.tsx`.
    *   **Communication Moderation & Facilitation:** Full access to all project message threads (`project_client_admin_freelancer`, `project_admin_client`, `project_admin_freelancer`) via `AdminProjectMessagingPage.tsx`. Crucially, Admins approve/reject Freelancer messages in Type A threads before Client visibility. They can also initiate direct messages with any user, acting as a central point of contact or mediator.
    *   **Financial & Work Oversight:** Reviewing all time logs (`AdminTimeLogReportPage.tsx`), managing billing information (`billing_info` table), generating reports, and potentially handling invoice processes and payment disputes.
    *   **System Configuration & Maintenance:** Managing platform-wide settings like the skills list (`ManageSkills.tsx`), and potentially other configurations that affect all users. They are the first line of defense for system errors or performance issues.
*   **Key Permissions:**
    *   Full CRUD (Create, Read, Update, Delete/Deactivate) operations on most data entities, especially users and projects.
    *   Ability to override or modify data created by other user roles (e.g., changing project deadlines set by Clients).
    *   Access to sensitive information and administrative back-office tools.

### 2.2. Client Role (from Admin's View)

Admins need to understand Client capabilities to support them, manage their projects, and ensure they use the platform correctly.

*   **Admin's Interaction & Oversight of Clients:**
    *   **Account Management:** Admins can view/edit Client profiles, assist with account issues (e.g., login problems), and manage their account status.
    *   **Project Monitoring:** Admins see all projects created by Clients. They can monitor progress, budgets, and deadlines. If a Client struggles with project creation or management, Admins might provide guidance or directly assist using their elevated privileges.
    *   **Communication Support:** Admins can view communications between Clients and assigned Freelancers (in Type A threads, post-approval) and between Clients and other Admins (Type B threads and DMs). This is vital for resolving disputes or clarifying project requirements. Admins ensure Clients understand the communication channels, especially the restriction that Clients can only DM Admins directly.
    *   **Billing & Payment Support:** Admins oversee the billing process related to Client projects, help resolve payment issues, and can access Client-related `billing_info`.
*   **Client Capabilities Relevant to Admin:**
    *   Clients create `projects`, defining scope and budget. Admins may need to review these for compliance or resource allocation.
    *   Clients select Freelancers by reviewing `project_applications`. Admins can see these applications and the Client's choices.
    *   Clients view `time_logs` for their projects. Admin ensures this data is available and accurate.

### 2.3. Freelancer Role (from Admin's View)

Admins manage the pool of Freelancers, oversee their participation in projects, and ensure they adhere to platform guidelines.

*   **Admin's Interaction & Oversight of Freelancers:**
    *   **Account Management & Vetting:** Admins manage Freelancer accounts (view/edit profiles, manage status). They might be involved in an initial vetting process or skills verification.
    *   **Project Assignment Monitoring:** Admins see which Freelancers are applying for projects (`project_applications`) and which are assigned (`project_assigned_freelancers`). They can intervene in assignment issues or manually assign Freelancers if needed.
    *   **Work & Performance Oversight:** Admins can review `time_logs` submitted by all Freelancers. They monitor task completion via `job_cards` associated with projects Freelancers are on.
    *   **Communication Moderation:** This is a key admin function. All messages from Freelancers in Type A (`project_client_admin_freelancer`) threads **must be approved or rejected by an Admin** before being visible to the Client. Admins also manage direct communications between Freelancers and themselves (Type C threads and DMs), ensuring professional conduct. Admins enforce the restriction that Freelancers can only DM Admins.
    *   **Payment Facilitation:** Admins are typically involved in ensuring Freelancers are paid correctly based on verified time logs and project completion.
*   **Freelancer Capabilities Relevant to Admin:**
    *   Freelancers submit `project_applications`. Admins can view and manage these.
    *   Freelancers log time via `time_logs`. Admins use this data for billing and project tracking.
    *   Freelancers manage their tasks in `job_cards`. Admins can see this to gauge project progress.

By understanding these roles and their interactions from an administrative perspective, Admins can effectively govern the platform, enforce policies, and provide necessary support to all user types.

## 3. Core Workflows (Admin Perspective)

This section details typical processes, emphasizing the Administrator's actions, responsibilities, and control points within each workflow.

### 3.1. Admin Workflow: Comprehensive Platform Management

This workflow consolidates the primary operational duties of an Admin.

1.  **User Lifecycle Management (via `UserManagement.tsx`):**
    *   **Access User Dashboard:** Admin navigates to the user management section.
    *   **View & Filter Users:** Lists all users, with filters for role (Client, Freelancer, Admin), status (active/inactive), and search functionality.
    *   **Create New User:**
        *   Manually adds a user (e.g., if bulk importing or special setup is needed).
        *   Inputs username, email, temporary password, assigns a role.
        *   Backend `UserService.php` (or similar) creates the user in the `users` table.
    *   **Edit Existing User:**
        *   Selects a user to modify their profile (name, contact), role, or other attributes.
        *   Can reset passwords or assist with account recovery.
    *   **Activate/Deactivate User:**
        *   Changes the `is_active` flag for a user. Deactivated users cannot log in. This is critical for managing departing users or temporarily suspending accounts.
    *   **Monitor User Activity:** (Implicit) Admins may have access to logs or activity dashboards to monitor user actions, last login times (`users.last_login_at`), etc., for security and operational insights.

2.  **Project Oversight & Intervention (via `ProjectManagement.tsx`):**
    *   **Access Project Dashboard:** Admin views a list of all projects on the platform.
    *   **Monitor Status & Progress:** Tracks projects by status ('open', 'in_progress', 'completed', etc.), deadlines, and assigned personnel.
    *   **View/Edit Project Details:** Can access and modify any project's title, description, budget, deadline, even if created by a Client. This is crucial for correcting errors or adapting projects.
    *   **Manage Assignments:** Can view `project_assigned_freelancers` and `project_applications`. May manually assign or unassign Freelancers, or override Client selections if necessary (e.g., due to disputes or freelancer unavailability).
    *   **Oversee Project Files:** Ensures that linked file exchange folders (`message_threads.linked_folder_path`) are correctly configured and accessible as per thread types.

3.  **Communication Moderation & Management (via `AdminProjectMessagingPage.tsx` and general messaging tools):**
    *   **Access All Project Threads:** Can join and view messages in any Type A, B, or C project thread.
    *   **Moderate Type A Thread Messages (Client-Admin-Freelancer):**
        *   Monitors incoming messages from Freelancers in Type A threads, which are initially `pending_approval`.
        *   Uses UI controls to "Approve" or "Reject" these messages.
        *   Approval makes the message visible to the Client; rejection keeps it hidden (sender and Admin still see status). Backend `MessagingService.php` updates `messages.approval_status`.
    *   **Facilitate & Mediate:** Uses Type B (Admin-Client) and Type C (Admin-Freelancer) threads for targeted communication. Can use DMs to resolve issues, clarify requirements, or relay information between parties who cannot directly message each other.
    *   **Enforce Communication Policies:** Monitors discussions for professionalism and adherence to platform guidelines.

4.  **Financial & Work Oversight (via `AdminTimeLogReportPage.tsx`, billing sections):**
    *   **Review Time Logs:** Accesses comprehensive reports of all `time_logs` submitted by Freelancers. Can filter by Freelancer, project, date range.
    *   **Verify Accuracy:** Checks for inconsistencies or policy violations in time logging.
    *   **Manage Billing (`billing_info` table):**
        *   Oversees the generation of billing records.
        *   May create or approve invoices.
        *   Tracks payment statuses ('pending', 'paid', 'overdue').
    *   **Handle Disputes:** Investigates and resolves discrepancies in time logs or billing between Clients and Freelancers.

5.  **System Configuration & Maintenance (e.g., `ManageSkills.tsx`):**
    *   **Manage Skills List:** Adds, edits, or removes skills available for Freelancer profiles and project requirements. This impacts searchability and matching.
    *   **Platform Settings:** May control other global settings (e.g., notification preferences, default project terms, user registration options – details depend on implementation).
    *   **Troubleshooting:** Acts as the first point of contact for system errors, performance degradation, or user-reported bugs, liaising with technical teams if necessary.

### 3.2. Client Workflow (Admin's Supervisory View)

Admins oversee Client activities to provide support, ensure platform integrity, and manage project lifecycles.

1.  **Onboarding & Account Support:**
    *   Admin can view Client registration data.
    *   Assists Clients with account setup, profile completion, or login issues, potentially using `UserManagement.tsx` to reset passwords or update details.

2.  **Project Creation & Posting Monitoring:**
    *   Admin sees new projects created by Clients (`projects` table, `client_id` linkage).
    *   May review project descriptions for clarity, appropriateness, or compliance with platform terms.
    *   Can edit/clarify project details directly if a Client struggles or if details are problematic.

3.  **Oversight of Freelancer Hiring Process:**
    *   Admin can view `project_applications` submitted to Client projects.
    *   Monitors Client decisions in hiring Freelancers (when Client accepts an application, updating `project_assigned_freelancers`).
    *   Can intervene if there are issues, e.g., a Client is unresponsive to applications, or if there's a dispute over freelancer selection. Can manually assign a freelancer if needed.

4.  **Monitoring Project Execution & Communication:**
    *   Admin tracks overall project progress via `ProjectManagement.tsx`.
    *   Monitors Client communication in Type A (Client-Admin-Freelancer) and Type B (Admin-Client) threads.
    *   Ensures Clients are aware of the message approval process for Freelancer messages in Type A.
    *   Steps in to mediate or clarify if communication breaks down or if a Client has concerns.

5.  **Time Log & Billing Support for Clients:**
    *   Admin ensures Clients can view relevant `time_logs` for their projects.
    *   Assists Clients in understanding invoices and billing details derived from `billing_info`.
    *   Manages any billing disputes or queries raised by Clients.

### 3.3. Freelancer Workflow (Admin's Supervisory View)

Admins manage the Freelancer community, ensure quality, and oversee their project contributions and adherence to platform rules.

1.  **Onboarding & Profile Management Support:**
    *   Admin views Freelancer registration data and profiles.
    *   May have a role in a formal vetting or skill verification process.
    *   Assists Freelancers with account issues via `UserManagement.tsx`.
    *   Ensures Freelancers maintain up-to-date profiles and select appropriate skills from the admin-managed list (`ManageSkills.tsx`).

2.  **Monitoring Project Applications & Assignments:**
    *   Admin sees all `project_applications` from Freelancers.
    *   Tracks which Freelancers are assigned to which projects (`project_assigned_freelancers`).
    *   Can identify popular projects or Freelancers with many applications.
    *   May guide Freelancers on improving proposals or finding suitable projects.

3.  **Oversight of Project Work & Communication:**
    *   Admin monitors Freelancer activity on projects through `job_cards` status and `time_logs`.
    *   **Crucially, moderates Freelancer messages in Type A threads** (see 3.1.3). This is a direct control point over Freelancer-Client communication.
    *   Manages communication in Type C (Admin-Freelancer) threads, providing direct guidance or feedback.
    *   Ensures Freelancers use DMs appropriately (only to Admins).

4.  **Time Log Verification & Payment Facilitation:**
    *   Admin is the primary reviewer of all `time_logs` for accuracy and policy compliance before they are used for billing.
    *   Manages the process that leads to Freelancer payment, based on verified work and `billing_info` records.
    *   Addresses any Freelancer queries or disputes regarding logged time or payments.

Through these supervisory workflows, Admins maintain control over platform operations, user interactions, and project outcomes, acting as the central authority and support hub.

## 4. Key System Workflows (Admin Oversight Perspective)

This section describes critical end-to-end processes, focusing on the Administrator's role in overseeing, managing, and intervening in these system-wide operations.

### 4.1. User Authentication Workflow (Admin Oversight)

Admins ensure the integrity and security of the authentication process and manage user access.

1.  **Registration Oversight:**
    *   Admins can view all new user registrations (Clients, Freelancers).
    *   They may have a process for approving certain roles or verifying email addresses if not fully automated.
    *   Admins are responsible for manually creating other Admin accounts, assigning the 'admin' role directly in the `users` table or through a secure admin interface.

2.  **Login Management & Support:**
    *   Admins can assist users experiencing login issues (e.g., forgotten passwords by triggering resets, unlocking accounts if there are lockout mechanisms).
    *   They can monitor `users.last_login_at` to identify inactive accounts or unusual login patterns.
    *   Admins ensure the JWT generation (`JWTHandler.php`) and validation mechanisms are functioning correctly, as this is critical for system security.

3.  **Session Management & Security:**
    *   Admins can deactivate user accounts (`is_active` = FALSE) to immediately revoke access for compromised or departing users, effectively invalidating their sessions.
    *   While not explicitly stated, Admins would be responsible for policies around session timeouts or JWT expiration configurations if these are server-side managed.

4.  **Role Management:**
    *   A critical Admin function: assigning and changing user roles via `UserManagement.tsx`. This directly impacts user permissions and access to API endpoints, as the JWT carries role information validated by the backend.

### 4.2. Project Creation and Management Workflow (Admin Control)

Admins have ultimate control over all projects within the system.

1.  **Client Creates Project (Admin View):**
    *   Admins can see all projects created by Clients in `ProjectManagement.tsx`.
    *   They can review new projects for completeness, appropriateness, or to ensure they align with platform guidelines.
    *   **Intervention:** Admins can edit any project detail (title, description, budget, deadline), add supplementary information, or even reject/archive inappropriate projects.

2.  **Admin Direct Project Creation/Modification:**
    *   Admins can create projects directly, perhaps for internal initiatives or on behalf of users.
    *   They can assign any Client to any project, or change the client associated with a project.

3.  **Status & Lifecycle Management:**
    *   Admins can manually change the status of any project (e.g., from 'open' to 'on_hold', 'cancelled', or 'completed'), overriding normal workflow progression if necessary. This is vital for managing exceptions or disputes.

### 4.3. Freelancer Application and Assignment Workflow (Admin Intervention & Oversight)

Admins oversee the matching of Freelancers to projects, ensuring fairness and efficiency.

1.  **Monitoring Applications:**
    *   Admins can view all `project_applications` across all projects. This allows them to identify projects with few applicants or Freelancers who are frequently applying.
    *   They can review individual proposals if needed, perhaps to assist a Client or investigate a complaint.

2.  **Overseeing Client Selections:**
    *   Admins see when Clients accept applications and a Freelancer is added to `project_assigned_freelancers`.
    *   **Intervention:** If a Client is struggling to choose, or if there's a need to expedite assignment, an Admin can:
        *   Recommend Freelancers to a Client.
        *   Directly assign a Freelancer to a project, bypassing the standard application/acceptance flow by creating a record in `project_assigned_freelancers` and updating the project status.
        *   Resolve conflicts if multiple Freelancers are selected for a single-person task or if a selected Freelancer becomes unavailable.

3.  **Managing Freelancer Pool:**
    *   Admins use insights from applications and assignments to understand the demand for certain skills, potentially informing decisions on recruiting more Freelancers or adjusting the `ManageSkills.tsx` list.

### 4.4. Messaging Workflow (Admin Moderation & Full Access)

Admins are central to the messaging system, with moderation duties and complete visibility.

1.  **Direct Messaging (DM) Oversight:**
    *   While DMs are generally private, Admins can typically access DM records directly in the database (`messages` and `message_threads` tables where `type='direct'`) if required for dispute resolution or investigation, subject to privacy policies.
    *   Admins ensure the DM restrictions (Clients/Freelancers can only DM Admins) are enforced by the backend (`get_messageable_users` logic).

2.  **Project-Specific Messaging (Admin Control Point):**
    *   **Full Visibility:** Admins can access and view all messages in all project thread types (A, B, C) via `AdminProjectMessagingPage.tsx`.
    *   **Type A (Client-Admin-Freelancer) Moderation:** This is a primary admin responsibility.
        1.  Admin UI highlights Freelancer messages with `requires_approval=TRUE`, `approval_status='pending'`.
        2.  Admin uses `moderate_project_message` endpoint functionality to "Approve" or "Reject".
        3.  This action directly controls information flow to the Client.
    *   **Utilizing Thread Types:** Admins strategically use:
        *   **Type A:** For tripartite discussions where their moderation is key.
        *   **Type B (Admin-Client):** For private project discussions with Clients (e.g., contract terms, sensitive feedback).
        *   **Type C (Admin-Freelancer):** For private discussions with Freelancers (e.g., task clarifications, performance feedback, internal coordination).
    *   **Thread Management:** Admins can likely initiate any project thread type and ensure correct participants from `users` and `project_assigned_freelancers` are added by the backend. They can also manage `message_threads.linked_folder_path` for file exchanges.

### 4.5. Time Logging and Billing Workflow (Admin Verification & Processing)

Admins are crucial for ensuring the accuracy of time logs and the smooth operation of the billing process.

1.  **Freelancer Logs Time (Admin View):**
    *   Admins have access to all `time_logs` via `AdminTimeLogReportPage.tsx`.
    *   They can filter/sort these logs by Freelancer, project, date, `job_card_id`.

2.  **Verification & Approval (Admin Responsibility):**
    *   Admins are typically responsible for verifying the accuracy and legitimacy of logged hours. They might compare logs against task progress (`job_cards.status`) or project deliverables.
    *   While not explicitly a separate "approval" step in the schema for time logs, Admin verification is the de facto approval before billing.

3.  **Billing Process Management (Admin Driven):**
    *   Admins initiate or oversee the generation of records in `billing_info`. This may involve:
        *   Aggregating approved `time_logs`.
        *   Applying project rates or fixed prices.
        *   Generating `invoice_number` and amounts.
    *   They manage the lifecycle of these billing records (e.g., moving status from 'pending' to 'paid').

4.  **Payment & Dispute Resolution:**
    *   Admins track payments from Clients.
    *   They are the primary point of contact for any billing disputes from Clients or Freelancers, using their access to time logs, project details, and communication records to investigate and mediate.

Admin oversight is woven into every key system workflow, ensuring control, security, and operational integrity.

## 5. Conceptual Diagrams (Admin Focus)

This section describes key diagrams from an administrator's perspective, emphasizing their interactions, control points, and overview capabilities. These textual descriptions guide the creation of visual diagrams tailored for admin understanding.

### 5.1. High-Level System Architecture Diagram (Admin View)

*   **Purpose:** To provide Admins with an understanding of the main components and how they relate to administrative functions.
*   **Elements:** (Similar to the general version, but with admin-specific annotations)
    *   User (Web Browser - highlighting Admin user sessions)
    *   Frontend (React SPA - highlighting Admin-specific UI tools like `UserManagement.tsx`, `AdminDashboard.tsx`)
    *   Backend API (PHP Server - emphasizing services critical for admin functions: `AuthService`, `ProjectCrudService`, `MessagingService` for moderation)
    *   Database (SQL Database - highlighting tables Admins frequently oversee: `users`, `projects`, `messages`, `time_logs`, `billing_info`)
*   **Connections:** Standard connections, but potentially with callouts for:
    *   "Admin Actions" on the User <-> Frontend link.
    *   "Privileged API Calls (Admin JWT)" on the Frontend <-> Backend link.
    *   "Full Data Access/Modification" on the Backend API <-> Database link, from an admin's operational perspective.
*   **Admin Focus:** The diagram should visually cue that Admins interact with specialized frontend tools which then execute powerful backend operations against the full database.

### 5.2. User Role Interaction & Admin Control Diagram

*   **Purpose:** To illustrate the Admin's central role and comprehensive interaction with all system modules, contrasted with the more limited interactions of Clients and Freelancers.
*   **Elements:**
    *   **Central Column/Hub: Admin Role.**
    *   **Peripheral Columns/Spokes: Client Role, Freelancer Role.**
    *   **Modules/Functionalities (as nodes or sections accessed by roles):**
        *   User Account Management (CRUD for all users)
        *   Project Creation & Full Management (CRUD for all projects)
        *   Freelancer Application Review & Assignment Control
        *   Task Management Oversight (View all `job_cards`)
        *   Time Tracking Review & Verification (View all `time_logs`)
        *   Messaging (Full access to all threads, Moderation of Type A)
        *   Billing & Payment Processing
        *   System Configuration (Skills, Platform Settings)
*   **Interactions (Lines from roles to modules):**
    *   **Admin:** Lines connecting to ALL modules, indicating full access and control. Annotate lines with key admin actions (e.g., "Manages All Users", "Approves Messages", "Sets Skills").
    *   **Client/Freelancer:** Lines showing their more restricted access, highlighting areas where their actions are subject to Admin oversight or approval (e.g., Freelancer message in Type A thread is "Pending Admin Approval").
*   **Admin Focus:** Visually emphasize the Admin's pervasive access and control compared to other roles.

### 5.3. Messaging Workflow Diagram (Type A Thread - Admin Moderation Emphasis)

*   **Purpose:** To clearly illustrate the Admin's critical moderation step in Type A (`project_client_admin_freelancer`) project threads.
*   **Elements:**
    *   **Key Participants:** Freelancer, **Admin (Central Moderator)**, Client.
    *   **System Component:** Backend System / Message Store.
*   **Flows (Emphasizing Admin's decision point):**
    1.  Freelancer Sends Message -> Backend (stores as "pending_approval", visible to Freelancer & Admin).
    2.  **Admin Notification/View:** Message appears in Admin's queue/interface (`AdminProjectMessagingPage.tsx`) with "Approve" / "Reject" options.
    3.  **ADMIN DECISION POINT (Highlighted Box/Step):**
        *   **Path A (Approve):** Admin Clicks Approve -> Backend updates message to "approved" -> Message becomes visible to Client & other participants.
        *   **Path B (Reject):** Admin Clicks Reject -> Backend updates message to "rejected" -> Message remains hidden from Client; Freelancer sees "rejected" status.
*   **Admin Focus:** The diagram must make the Admin's approval/rejection action the clear gatekeeper for Client visibility of Freelancer messages.

### 5.4. Data Flow for Project Assignment by Admin (Intervention Scenario)

*   **Purpose:** To show how an Admin can directly intervene in the project assignment process.
*   **Elements:**
    *   **Actors:** Admin, System (Frontend Admin Tools, Backend API, Database).
    *   (Optional: Client, Freelancer, if showing Admin overriding or assisting them).
*   **Sequence (Example: Admin directly assigns a Freelancer):**
    1.  **Admin Accesses Project:** Via `ProjectManagement.tsx`, Admin selects a project.
    2.  **Admin Initiates Assignment:** Admin uses UI to select a Freelancer for the project.
    3.  **Admin Frontend -> Backend API:** (e.g., `/api.php?action=admin_assign_freelancer_to_project`) with (project_id, user_id (freelancer), role_in_project).
    4.  **Backend API Processing:**
        *   Validates Admin permissions.
        *   Checks if Freelancer can be assigned (e.g., not already assigned in conflicting role).
        *   **Database Interaction (Admin-Initiated):**
            *   INSERT into `project_assigned_freelancers` (project_id, user_id, role_in_project).
            *   UPDATE `projects` table (e.g., status to 'in_progress' if not already).
            *   Optionally, if this assignment supersedes an application, update relevant `project_applications` status.
    5.  **Backend API -> Admin Frontend:** Confirmation of assignment.
    6.  **(Notifications):** System sends notifications to the assigned Freelancer and potentially the Client.
*   **Admin Focus:** Show a clear path where Admin actions directly modify core project assignment data (`project_assigned_freelancers`, `projects` status), bypassing typical user-driven application flows if necessary.

These descriptions, tailored for an admin audience, will help in creating diagrams that resonate with their responsibilities and system interactions.
---
