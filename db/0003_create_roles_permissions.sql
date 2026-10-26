-- 0003_create_roles_permissions.sql
-- Global roles and permissions. Reference data is inserted here so a fresh database always works.
-- Roles are created before memberships because memberships reference them.
-- Platform administrators are not a role, they are users.is_platform_admin.

CREATE TABLE IF NOT EXISTS roles (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         VARCHAR(40)     NOT NULL,
  name         VARCHAR(80)     NOT NULL,
  description  VARCHAR(255)    NULL,
  is_system    TINYINT(1)      NOT NULL DEFAULT 1,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code         VARCHAR(60)     NOT NULL,
  description  VARCHAR(255)    NULL,
  created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id        BIGINT UNSIGNED NOT NULL,
  permission_id  BIGINT UNSIGNED NOT NULL,
  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (role_id, permission_id),
  KEY idx_role_permissions_permission (permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reference data: roles
INSERT IGNORE INTO roles (id, code, name, description) VALUES
  (1, 'owner', 'Owner',  'Full control of the workspace'),
  (2, 'admin', 'Admin',  'Manages staff, teams, categories and tickets'),
  (3, 'agent', 'Agent',  'Works tickets for their teams');

-- Reference data: permissions
INSERT IGNORE INTO permissions (id, code, description) VALUES
  (1,  'tickets.view_all',     'View every ticket in the workspace'),
  (2,  'tickets.view_team',    'View tickets for own teams and tickets assigned to self'),
  (3,  'tickets.create',       'Create tickets'),
  (4,  'tickets.assign',       'Assign tickets to any team or agent'),
  (5,  'tickets.assign_self',  'Assign tickets to self'),
  (6,  'tickets.status',       'Change ticket status'),
  (7,  'tickets.priority',     'Change priority and due date'),
  (8,  'tickets.reply',        'Send public replies'),
  (9,  'tickets.note',         'Add internal notes'),
  (10, 'tickets.attach',       'Upload and download attachments'),
  (11, 'teams.manage',         'Create and edit teams'),
  (12, 'categories.manage',    'Create and edit categories'),
  (13, 'sites.manage',         'Create and edit sites'),
  (14, 'members.manage',       'Invite, change role, disable members'),
  (15, 'settings.manage',      'Edit workspace settings, public link and domain'),
  (16, 'reports.view',         'View reports for the whole workspace'),
  (17, 'reports.view_team',    'View reports for own teams'),
  (18, 'audit.view',           'View the audit log');

-- Owner: everything
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.code = 'owner';

-- Admin: everything except workspace settings
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'admin' AND p.code <> 'settings.manage';

-- Agent: work own team tickets
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.code = 'agent' AND p.code IN (
  'tickets.view_team', 'tickets.create', 'tickets.assign_self', 'tickets.status',
  'tickets.reply', 'tickets.note', 'tickets.attach', 'reports.view_team'
);
