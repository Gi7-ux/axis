-- Users Table: Stores information about all users (Admins, Clients, Freelancers)
CREATE TABLE users (
    user_id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'client', 'freelancer') NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    profile_picture_url VARCHAR(255),
    bio TEXT,
    company_name VARCHAR(255), -- Relevant for Clients
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login_at TIMESTAMP NULL
);

-- Skills Table: Stores a predefined list of skills
CREATE TABLE skills (
    skill_id INT AUTO_INCREMENT PRIMARY KEY,
    skill_name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- User Skills Table: Links users (especially Freelancers) to skills
CREATE TABLE user_skills (
    user_skill_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    skill_id INT NOT NULL,
    proficiency_level ENUM('beginner', 'intermediate', 'expert'), -- Optional
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (skill_id) REFERENCES skills(skill_id) ON DELETE CASCADE,
    UNIQUE(user_id, skill_id) -- Ensures a user doesn't have the same skill listed multiple times
);

-- Projects Table: Stores information about projects created by Clients
CREATE TABLE projects (
    project_id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL, -- User ID of the Client who created the project
    title VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    budget DECIMAL(10, 2),
    deadline DATE,
    status ENUM('open', 'in_progress', 'completed', 'cancelled', 'on_hold', 'pending_approval') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- Project Applications Table: Stores applications submitted by Freelancers for projects
CREATE TABLE project_applications (
    application_id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    freelancer_id INT NOT NULL, -- User ID of the Freelancer applying
    proposal_text TEXT,
    bid_amount DECIMAL(10, 2), -- Optional, if project allows bidding
    estimated_timeline VARCHAR(255), -- e.g., "2 weeks", "1 month"
    status ENUM('pending', 'accepted', 'rejected', 'withdrawn') DEFAULT 'pending',
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE,
    FOREIGN KEY (freelancer_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- Project Assigned Freelancers Table: Links Freelancers to projects they are assigned to
CREATE TABLE project_assigned_freelancers (
    assignment_id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    freelancer_id INT NOT NULL, -- User ID of the assigned Freelancer
    role_in_project VARCHAR(255), -- e.g., "Lead Developer", "Designer" (optional)
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE,
    FOREIGN KEY (freelancer_id) REFERENCES users(user_id) ON DELETE CASCADE,
    UNIQUE(project_id, freelancer_id) -- Ensures a freelancer is not assigned multiple times to the same project
);

-- Job Cards Table: Stores tasks or sub-components of a project
CREATE TABLE job_cards (
    job_card_id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    assigned_freelancer_id INT, -- Optional: can be assigned to a specific freelancer on the project
    title VARCHAR(255) NOT NULL,
    description TEXT,
    status ENUM('todo', 'in_progress', 'review', 'completed', 'blocked') DEFAULT 'todo',
    due_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_freelancer_id) REFERENCES users(user_id) ON DELETE SET NULL -- Allow task to be unassigned
);

-- Time Logs Table: Stores time logged by Freelancers for tasks/projects
CREATE TABLE time_logs (
    time_log_id INT AUTO_INCREMENT PRIMARY KEY,
    job_card_id INT NOT NULL,
    freelancer_id INT NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    notes TEXT,
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (job_card_id) REFERENCES job_cards(job_card_id) ON DELETE CASCADE,
    FOREIGN KEY (freelancer_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- Message Threads Table: Defines communication channels
CREATE TABLE message_threads (
    thread_id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NULL, -- Null for direct messages, linked for project messages
    type ENUM('direct', 'project_client_admin_freelancer', 'project_admin_client', 'project_admin_freelancer') NOT NULL,
    title VARCHAR(255), -- Optional title for the thread
    linked_folder_path VARCHAR(255), -- Path for shared files related to this thread
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE SET NULL -- Keep thread if project is deleted, but unlink
);

-- Thread Participants Table: Links users to message threads
CREATE TABLE thread_participants (
    participant_id INT AUTO_INCREMENT PRIMARY KEY,
    thread_id INT NOT NULL,
    user_id INT NOT NULL,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_read_at TIMESTAMP NULL,
    -- visibility_level could be added if finer-grained control is needed beyond thread type
    FOREIGN KEY (thread_id) REFERENCES message_threads(thread_id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    UNIQUE(thread_id, user_id)
);

-- Messages Table: Stores individual messages within threads
CREATE TABLE messages (
    message_id INT AUTO_INCREMENT PRIMARY KEY,
    thread_id INT NOT NULL,
    sender_id INT NOT NULL,
    content TEXT NOT NULL,
    attachment_url VARCHAR(255), -- URL to an uploaded file
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    requires_approval BOOLEAN DEFAULT FALSE, -- True for Freelancer messages in Type A threads
    approval_status ENUM('pending', 'approved', 'rejected') DEFAULT NULL, -- Relevant if requires_approval is true
    approved_by_admin_id INT NULL, -- Admin who approved/rejected
    FOREIGN KEY (thread_id) REFERENCES message_threads(thread_id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by_admin_id) REFERENCES users(user_id) ON DELETE SET NULL
);

-- Billing Info Table: Stores billing records related to projects/time logs
CREATE TABLE billing_info (
    billing_id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    client_id INT NOT NULL,
    freelancer_id INT, -- Can be null if billing is project-level to client, or linked if specific to a freelancer's work
    amount DECIMAL(10, 2) NOT NULL,
    invoice_number VARCHAR(50) UNIQUE,
    status ENUM('pending', 'paid', 'overdue', 'cancelled') DEFAULT 'pending',
    due_date DATE,
    issued_date DATE DEFAULT (CURRENT_DATE),
    paid_date DATE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(project_id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (freelancer_id) REFERENCES users(user_id) ON DELETE SET NULL
);

-- Example of adding an Admin user (should be done securely, this is for schema setup understanding)
-- INSERT INTO users (username, email, password_hash, role, first_name, last_name, is_active)
-- VALUES ('admin_user', 'admin@example.com', 'hashed_password_goes_here', 'admin', 'Admin', 'User', TRUE);

-- Example of adding a skill
-- INSERT INTO skills (skill_name, description)
-- VALUES ('PHP Development', 'Developing applications using PHP.');
-- INSERT INTO skills (skill_name, description)
-- VALUES ('React Development', 'Building user interfaces with React.');
-- INSERT INTO skills (skill_name, description)
-- VALUES ('Graphic Design', 'Creating visual content.');

-- Indexes for performance (examples, more can be added based on query patterns)
CREATE INDEX idx_projects_client_id ON projects(client_id);
CREATE INDEX idx_projects_status ON projects(status);
CREATE INDEX idx_project_applications_project_id ON project_applications(project_id);
CREATE INDEX idx_project_applications_freelancer_id ON project_applications(freelancer_id);
CREATE INDEX idx_job_cards_project_id ON job_cards(project_id);
CREATE INDEX idx_time_logs_job_card_id ON time_logs(job_card_id);
CREATE INDEX idx_time_logs_freelancer_id ON time_logs(freelancer_id);
CREATE INDEX idx_messages_thread_id ON messages(thread_id);
CREATE INDEX idx_thread_participants_thread_id ON thread_participants(thread_id);
CREATE INDEX idx_thread_participants_user_id ON thread_participants(user_id);
CREATE INDEX idx_user_skills_user_id ON user_skills(user_id);
CREATE INDEX idx_user_skills_skill_id ON user_skills(skill_id);
CREATE INDEX idx_billing_info_project_id ON billing_info(project_id);
CREATE INDEX idx_billing_info_client_id ON billing_info(client_id);
CREATE INDEX idx_billing_info_freelancer_id ON billing_info(freelancer_id);
