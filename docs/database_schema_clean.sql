-- DemandFlow Bridge schema (structure only)
-- Generated: 2026-04-01T21:43:36+02:00
-- Database: leads

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `app_settings`;
DROP TABLE IF EXISTS `campaign_additional_files`;
DROP TABLE IF EXISTS `campaign_delivery_files`;
DROP TABLE IF EXISTS `campaign_details`;
DROP TABLE IF EXISTS `campaign_forms`;
DROP TABLE IF EXISTS `campaign_metrics`;
DROP TABLE IF EXISTS `campaign_notes`;
DROP TABLE IF EXISTS `campaign_revenue`;
DROP TABLE IF EXISTS `campaign_user_assignments`;
DROP TABLE IF EXISTS `campaigns`;
DROP TABLE IF EXISTS `chat_group_members`;
DROP TABLE IF EXISTS `chat_groups`;
DROP TABLE IF EXISTS `chat_messages`;
DROP TABLE IF EXISTS `client_billing_profiles`;
DROP TABLE IF EXISTS `client_contacts`;
DROP TABLE IF EXISTS `client_sdr_map`;
DROP TABLE IF EXISTS `client_tags`;
DROP TABLE IF EXISTS `clients`;
DROP TABLE IF EXISTS `form_submissions`;
DROP TABLE IF EXISTS `form_templates`;
DROP TABLE IF EXISTS `forms`;
DROP TABLE IF EXISTS `fx_rates`;
DROP TABLE IF EXISTS `holidays`;
DROP TABLE IF EXISTS `hr_attendance_days`;
DROP TABLE IF EXISTS `hr_attendance_states`;
DROP TABLE IF EXISTS `hr_bonuses`;
DROP TABLE IF EXISTS `hr_loan_deductions`;
DROP TABLE IF EXISTS `hr_loans`;
DROP TABLE IF EXISTS `hr_payroll_month_locks`;
DROP TABLE IF EXISTS `hr_payslips`;
DROP TABLE IF EXISTS `hr_salary_settings`;
DROP TABLE IF EXISTS `hr_salary_structures`;
DROP TABLE IF EXISTS `hr_shifts`;
DROP TABLE IF EXISTS `hr_user_shift_assignments`;
DROP TABLE IF EXISTS `lead_activity`;
DROP TABLE IF EXISTS `lead_files`;
DROP TABLE IF EXISTS `lead_tags`;
DROP TABLE IF EXISTS `leads`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `notification_digest_queue`;
DROP TABLE IF EXISTS `notification_preferences`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `operations_campaign_assignments`;
DROP TABLE IF EXISTS `productivity_day_snapshots`;
DROP TABLE IF EXISTS `productivity_month_locks`;
DROP TABLE IF EXISTS `productivity_month_snapshots`;
DROP TABLE IF EXISTS `productivity_targets`;
DROP TABLE IF EXISTS `qa_assignment_requests`;
DROP TABLE IF EXISTS `qa_audit_logs`;
DROP TABLE IF EXISTS `qa_campaign_assignments`;
DROP TABLE IF EXISTS `revenue_invoice_billto_profiles`;
DROP TABLE IF EXISTS `revenue_invoice_items`;
DROP TABLE IF EXISTS `revenue_invoice_settings`;
DROP TABLE IF EXISTS `revenue_invoices`;
DROP TABLE IF EXISTS `revenue_manual_expenses`;
DROP TABLE IF EXISTS `sales_client_ownership`;
DROP TABLE IF EXISTS `sales_lead_activities`;
DROP TABLE IF EXISTS `sales_leads`;
DROP TABLE IF EXISTS `sales_manager_sdr_map`;
DROP TABLE IF EXISTS `sales_targets`;
DROP TABLE IF EXISTS `tags`;
DROP TABLE IF EXISTS `team_campaigns`;
DROP TABLE IF EXISTS `team_members`;
DROP TABLE IF EXISTS `teams`;
DROP TABLE IF EXISTS `url_previews`;
DROP TABLE IF EXISTS `user_bank_details`;
DROP TABLE IF EXISTS `user_documents`;
DROP TABLE IF EXISTS `user_ip_access`;
DROP TABLE IF EXISTS `user_personal_details`;
DROP TABLE IF EXISTS `user_presence`;
DROP TABLE IF EXISTS `user_sessions`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `vendor_billing_profiles`;
DROP TABLE IF EXISTS `vendor_campaign_map`;
DROP TABLE IF EXISTS `vendor_user_map`;
DROP TABLE IF EXISTS `vendors`;

--
-- Table: app_settings
--
CREATE TABLE IF NOT EXISTS `app_settings` (
  `setting_key` varchar(191) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: campaign_additional_files
--
CREATE TABLE IF NOT EXISTS `campaign_additional_files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `campaign_id` int(11) NOT NULL,
  `file_title` varchar(180) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_type` varchar(80) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_campaign` (`campaign_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: campaign_delivery_files
--
CREATE TABLE IF NOT EXISTS `campaign_delivery_files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `campaign_id` int(11) NOT NULL,
  `uploader_id` int(11) NOT NULL,
  `format` varchar(40) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `file_type` varchar(40) DEFAULT NULL,
  `file_name` varchar(180) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_campaign` (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: campaign_details
--
CREATE TABLE IF NOT EXISTS `campaign_details` (
  `campaign_id` int(11) NOT NULL,
  `code` varchar(32) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Draft',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `total_leads` int(11) DEFAULT NULL,
  `pacing_type` varchar(20) DEFAULT NULL,
  `pacing_count` int(11) DEFAULT NULL,
  `cpc` decimal(10,2) DEFAULT NULL,
  `cpl` decimal(10,2) DEFAULT NULL,
  `cpl_currency` varchar(8) DEFAULT NULL,
  `campaign_type` varchar(40) DEFAULT NULL,
  `delivery_format` varchar(40) DEFAULT NULL,
  `targeted_country` text DEFAULT NULL,
  `job_title` varchar(255) DEFAULT NULL,
  `departments` text DEFAULT NULL,
  `seniority_levels` text DEFAULT NULL,
  `industries` text DEFAULT NULL,
  `employee_sizes` text DEFAULT NULL,
  `revenue_sizes` text DEFAULT NULL,
  `instruction` text DEFAULT NULL,
  `script_path` varchar(255) DEFAULT NULL,
  `tal_path` varchar(255) DEFAULT NULL,
  `suppression_path` varchar(255) DEFAULT NULL,
  `recording_path` varchar(255) DEFAULT NULL,
  `custom_fields_json` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `client_code` varchar(50) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`campaign_id`),
  UNIQUE KEY `code` (`code`),
  UNIQUE KEY `idx_code` (`code`),
  KEY `idx_status` (`status`),
  KEY `idx_campaign_type` (`campaign_type`),
  KEY `idx_client_id` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: campaign_forms
--
CREATE TABLE IF NOT EXISTS `campaign_forms` (
  `campaign_id` int(11) NOT NULL,
  `form_id` int(11) NOT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `assigned_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`campaign_id`),
  UNIQUE KEY `campaign_id` (`campaign_id`),
  KEY `idx_form` (`form_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: campaign_metrics
--
CREATE TABLE IF NOT EXISTS `campaign_metrics` (
  `campaign_id` int(11) NOT NULL,
  `delivered` int(11) DEFAULT NULL,
  `generated` int(11) DEFAULT NULL,
  `qualified` int(11) DEFAULT NULL,
  `disqualified` int(11) DEFAULT NULL,
  `pending` int(11) DEFAULT NULL,
  `rejected` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: campaign_notes
--
CREATE TABLE IF NOT EXISTS `campaign_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `campaign_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `note_text` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `attachment_path` text DEFAULT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: campaign_revenue
--
CREATE TABLE IF NOT EXISTS `campaign_revenue` (
  `campaign_id` int(11) NOT NULL,
  `revenue` decimal(12,2) DEFAULT NULL,
  `currency` varchar(8) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`campaign_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: campaign_user_assignments
--
CREATE TABLE IF NOT EXISTS `campaign_user_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `campaign_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `assigned_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_campaign_user` (`campaign_id`,`user_id`),
  KEY `idx_campaign` (`campaign_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: campaigns
--
CREATE TABLE IF NOT EXISTS `campaigns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 0,
  `owner_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: chat_group_members
--
CREATE TABLE IF NOT EXISTS `chat_group_members` (
  `group_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` varchar(16) NOT NULL DEFAULT 'member',
  `added_by` int(11) DEFAULT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_read_message_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`group_id`,`user_id`),
  KEY `user_id` (`user_id`),
  KEY `group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: chat_groups
--
CREATE TABLE IF NOT EXISTS `chat_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `group_name` varchar(120) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: chat_messages
--
CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `group_id` int(11) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `message_type` varchar(16) DEFAULT 'text',
  `delivered_at` datetime DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `is_deleted` tinyint(1) DEFAULT 0,
  `deleted_by` int(11) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sender` (`sender_id`),
  KEY `idx_receiver` (`receiver_id`),
  KEY `group_id` (`group_id`),
  KEY `created_at` (`created_at`),
  KEY `group_id_2` (`group_id`),
  KEY `created_at_2` (`created_at`),
  KEY `group_id_3` (`group_id`),
  KEY `created_at_3` (`created_at`),
  KEY `group_id_4` (`group_id`),
  KEY `created_at_4` (`created_at`),
  KEY `group_id_5` (`group_id`),
  KEY `created_at_5` (`created_at`),
  KEY `group_id_6` (`group_id`),
  KEY `created_at_6` (`created_at`),
  KEY `group_id_7` (`group_id`),
  KEY `created_at_7` (`created_at`),
  KEY `group_id_8` (`group_id`),
  KEY `created_at_8` (`created_at`),
  KEY `group_id_9` (`group_id`),
  KEY `created_at_9` (`created_at`),
  KEY `group_id_10` (`group_id`),
  KEY `created_at_10` (`created_at`),
  KEY `group_id_11` (`group_id`),
  KEY `created_at_11` (`created_at`),
  KEY `group_id_12` (`group_id`),
  KEY `created_at_12` (`created_at`),
  KEY `group_id_13` (`group_id`),
  KEY `created_at_13` (`created_at`),
  KEY `group_id_14` (`group_id`),
  KEY `created_at_14` (`created_at`),
  KEY `group_id_15` (`group_id`),
  KEY `created_at_15` (`created_at`),
  KEY `group_id_16` (`group_id`),
  KEY `created_at_16` (`created_at`),
  KEY `group_id_17` (`group_id`),
  KEY `created_at_17` (`created_at`),
  KEY `group_id_18` (`group_id`),
  KEY `created_at_18` (`created_at`),
  KEY `group_id_19` (`group_id`),
  KEY `created_at_19` (`created_at`),
  KEY `group_id_20` (`group_id`),
  KEY `created_at_20` (`created_at`),
  KEY `group_id_21` (`group_id`),
  KEY `created_at_21` (`created_at`),
  KEY `group_id_22` (`group_id`),
  KEY `created_at_22` (`created_at`),
  KEY `group_id_23` (`group_id`),
  KEY `created_at_23` (`created_at`),
  KEY `group_id_24` (`group_id`),
  KEY `created_at_24` (`created_at`),
  KEY `group_id_25` (`group_id`),
  KEY `created_at_25` (`created_at`),
  KEY `group_id_26` (`group_id`),
  KEY `created_at_26` (`created_at`),
  KEY `group_id_27` (`group_id`),
  KEY `created_at_27` (`created_at`),
  KEY `group_id_28` (`group_id`),
  KEY `created_at_28` (`created_at`),
  KEY `group_id_29` (`group_id`),
  KEY `created_at_29` (`created_at`),
  KEY `group_id_30` (`group_id`),
  KEY `created_at_30` (`created_at`),
  KEY `group_id_31` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: client_billing_profiles
--
CREATE TABLE IF NOT EXISTS `client_billing_profiles` (
  `client_id` int(11) NOT NULL,
  `billing_name` varchar(180) DEFAULT NULL,
  `billing_email` varchar(180) DEFAULT NULL,
  `billing_phone` varchar(40) DEFAULT NULL,
  `billing_address` text DEFAULT NULL,
  `tax_id` varchar(120) DEFAULT NULL,
  `bank_name` varchar(180) DEFAULT NULL,
  `bank_account_name` varchar(180) DEFAULT NULL,
  `bank_account_number` varchar(120) DEFAULT NULL,
  `bank_ifsc_swift` varchar(120) DEFAULT NULL,
  `bank_iban` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`client_id`),
  CONSTRAINT `client_billing_profiles_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: client_contacts
--
CREATE TABLE IF NOT EXISTS `client_contacts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_id` int(11) NOT NULL,
  `name` varchar(180) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `title` varchar(120) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_client` (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: client_sdr_map
--
CREATE TABLE IF NOT EXISTS `client_sdr_map` (
  `client_id` int(11) NOT NULL,
  `sdr_user_id` int(11) NOT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`client_id`,`sdr_user_id`),
  KEY `idx_client` (`client_id`),
  KEY `idx_sdr` (`sdr_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: client_tags
--
CREATE TABLE IF NOT EXISTS `client_tags` (
  `client_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  PRIMARY KEY (`client_id`,`tag_id`),
  KEY `idx_tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: clients
--
CREATE TABLE IF NOT EXISTS `clients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `client_code` varchar(50) NOT NULL,
  `name` varchar(200) NOT NULL,
  `website` varchar(255) DEFAULT NULL,
  `industry` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `website_domain` varchar(255) DEFAULT NULL,
  `country` varchar(120) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `client_code` (`client_code`),
  KEY `idx_website_domain` (`website_domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: form_submissions
--
CREATE TABLE IF NOT EXISTS `form_submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `form_id` int(11) NOT NULL,
  `campaign_id` int(11) NOT NULL,
  `lead_id` int(11) DEFAULT NULL,
  `submitted_by` int(11) NOT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `data_json` mediumtext NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_form` (`form_id`),
  KEY `idx_campaign` (`campaign_id`),
  KEY `idx_lead` (`lead_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: form_templates
--
CREATE TABLE IF NOT EXISTS `form_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_name` varchar(150) NOT NULL,
  `schema_json` mediumtext NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: forms
--
CREATE TABLE IF NOT EXISTS `forms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `fingerprint` char(64) NOT NULL,
  `schema_json` mediumtext NOT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `fingerprint` (`fingerprint`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: fx_rates
--
CREATE TABLE IF NOT EXISTS `fx_rates` (
  `rate_date` date NOT NULL,
  `usd_inr` decimal(10,4) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`rate_date`),
  KEY `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: holidays
--
CREATE TABLE IF NOT EXISTS `holidays` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `country_code` varchar(2) NOT NULL,
  `holiday_date` date NOT NULL,
  `name` varchar(120) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_country_date` (`country_code`,`holiday_date`),
  KEY `idx_date` (`holiday_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: hr_attendance_days
--
CREATE TABLE IF NOT EXISTS `hr_attendance_days` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `work_date` date NOT NULL,
  `punch_in` datetime DEFAULT NULL,
  `punch_out` datetime DEFAULT NULL,
  `current_state` varchar(20) NOT NULL DEFAULT 'Off',
  `break_minutes` int(11) NOT NULL DEFAULT 0,
  `working_minutes` int(11) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'Absent',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `late_minutes` int(11) NOT NULL DEFAULT 0,
  `shift_id` int(11) DEFAULT NULL,
  `shift_start_time` time DEFAULT NULL,
  `grace_minutes` int(11) NOT NULL DEFAULT 15,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_date` (`user_id`,`work_date`),
  KEY `idx_work_date` (`work_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: hr_attendance_states
--
CREATE TABLE IF NOT EXISTS `hr_attendance_states` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `attendance_day_id` int(11) NOT NULL,
  `state` varchar(20) NOT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime DEFAULT NULL,
  `minutes` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_day` (`attendance_day_id`),
  KEY `idx_state` (`state`),
  KEY `idx_start` (`start_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: hr_bonuses
--
CREATE TABLE IF NOT EXISTS `hr_bonuses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `year` int(11) NOT NULL,
  `month` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_month` (`user_id`,`year`,`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: hr_loan_deductions
--
CREATE TABLE IF NOT EXISTS `hr_loan_deductions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `loan_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `year` int(11) NOT NULL,
  `month` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_loan_month` (`loan_id`,`year`,`month`),
  KEY `idx_user_month` (`user_id`,`year`,`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: hr_loans
--
CREATE TABLE IF NOT EXISTS `hr_loans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `remaining_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `emi_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `start_date` date NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_active` (`user_id`,`active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: hr_payroll_month_locks
--
CREATE TABLE IF NOT EXISTS `hr_payroll_month_locks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `year` int(11) NOT NULL,
  `month` int(11) NOT NULL,
  `locked_by` int(11) NOT NULL,
  `locked_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_year_month` (`year`,`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: hr_payslips
--
CREATE TABLE IF NOT EXISTS `hr_payslips` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `year` int(11) NOT NULL,
  `month` int(11) NOT NULL,
  `salary_data` longtext NOT NULL,
  `generated_at` datetime NOT NULL,
  `generated_by` int(11) NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_month` (`user_id`,`year`,`month`),
  KEY `idx_year_month` (`year`,`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: hr_salary_settings
--
CREATE TABLE IF NOT EXISTS `hr_salary_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `basic_salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `allowances` decimal(12,2) NOT NULL DEFAULT 0.00,
  `pf` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_deductions` decimal(12,2) NOT NULL DEFAULT 0.00,
  `effective_date` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_date` (`user_id`,`effective_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: hr_salary_structures
--
CREATE TABLE IF NOT EXISTS `hr_salary_structures` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `effective_date` date NOT NULL,
  `structure_type` varchar(40) NOT NULL DEFAULT 'Standard',
  `total_salary` decimal(12,2) NOT NULL DEFAULT 0.00,
  `basic` decimal(12,2) NOT NULL DEFAULT 0.00,
  `hra` decimal(12,2) NOT NULL DEFAULT 0.00,
  `conveyance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `medical` decimal(12,2) NOT NULL DEFAULT 0.00,
  `special_allowance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `other_allowance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `pf` decimal(12,2) NOT NULL DEFAULT 0.00,
  `professional_tax` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tds` decimal(12,2) NOT NULL DEFAULT 0.00,
  `locked` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_date` (`user_id`,`effective_date`),
  KEY `idx_effective` (`effective_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: hr_shifts
--
CREATE TABLE IF NOT EXISTS `hr_shifts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `grace_minutes` int(11) NOT NULL DEFAULT 15,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: hr_user_shift_assignments
--
CREATE TABLE IF NOT EXISTS `hr_user_shift_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `shift_id` int(11) NOT NULL,
  `effective_date` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_date` (`user_id`,`effective_date`),
  KEY `idx_user` (`user_id`),
  KEY `idx_effective` (`effective_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: lead_activity
--
CREATE TABLE IF NOT EXISTS `lead_activity` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `actor_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `meta_json` mediumtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead` (`lead_id`),
  KEY `idx_actor` (`actor_id`),
  KEY `idx_action` (`action`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: lead_files
--
CREATE TABLE IF NOT EXISTS `lead_files` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `field_id` varchar(80) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead` (`lead_id`),
  KEY `idx_field` (`field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: lead_tags
--
CREATE TABLE IF NOT EXISTS `lead_tags` (
  `lead_id` int(11) NOT NULL,
  `tag_id` int(11) NOT NULL,
  `added_by` int(11) DEFAULT NULL,
  `added_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`lead_id`,`tag_id`),
  KEY `tag_id` (`tag_id`),
  CONSTRAINT `lead_tags_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `lead_tags_ibfk_2` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: leads
--
CREATE TABLE IF NOT EXISTS `leads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` varchar(20) NOT NULL,
  `campaign_id` int(11) NOT NULL,
  `campaign_name` varchar(100) NOT NULL,
  `agent_id` int(11) NOT NULL,
  `agent_name` varchar(100) NOT NULL,
  `first_name` varchar(50) NOT NULL,
  `last_name` varchar(50) NOT NULL,
  `job_title` varchar(150) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `linkedin_link` text DEFAULT NULL,
  `contact_phone` varchar(20) DEFAULT NULL,
  `industry` varchar(100) DEFAULT NULL,
  `company_linkedin` text DEFAULT NULL,
  `company_name` varchar(150) DEFAULT NULL,
  `company_size` varchar(50) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `software_implementation_timeline` varchar(20) DEFAULT NULL,
  `recording_path` varchar(255) DEFAULT NULL,
  `qa_status` enum('Pending','Reopened','Qualified','Disqualified','Rework Needed','Duplicate','Rectified','Delivered','Approved','Rejected') DEFAULT 'Pending',
  `qa_comment` text DEFAULT NULL,
  `qa_client_comment` text DEFAULT NULL,
  `qa_updated_at` timestamp NULL DEFAULT NULL,
  `qa_reviewed_by` int(11) DEFAULT NULL,
  `form_done` enum('Yes','No') DEFAULT 'No',
  `ip_address` varchar(50) DEFAULT NULL,
  `form_filled_time` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `lead_comment` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `email_status` varchar(30) DEFAULT NULL,
  `email_status_comment` text DEFAULT NULL,
  `email_status_updated_by` int(11) DEFAULT NULL,
  `email_status_updated_at` datetime DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `vendor_id` int(11) DEFAULT NULL,
  `lead_source` varchar(20) DEFAULT NULL,
  `assigned_to_user` int(11) DEFAULT NULL,
  `client_delivery_status` varchar(20) NOT NULL DEFAULT 'Pending',
  `company_domain` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_campaign_id` (`campaign_id`),
  KEY `idx_agent_id` (`agent_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_updated_by` (`updated_by`),
  KEY `idx_email_status` (`email_status`),
  KEY `idx_email_status_updated` (`email_status_updated_at`),
  KEY `idx_client_id` (`client_id`),
  KEY `idx_vendor_id` (`vendor_id`),
  KEY `idx_lead_source` (`lead_source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: login_attempts
--
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `username` varchar(50) DEFAULT NULL,
  `attempt_time` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: notification_digest_queue
--
CREATE TABLE IF NOT EXISTS `notification_digest_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` varchar(40) NOT NULL,
  `title` varchar(180) NOT NULL,
  `body` text DEFAULT NULL,
  `link_url` varchar(255) DEFAULT NULL,
  `dedup_key` char(40) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `processed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_user_processed` (`user_id`,`processed_at`),
  KEY `idx_user_dedup` (`user_id`,`type`,`dedup_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: notification_preferences
--
CREATE TABLE IF NOT EXISTS `notification_preferences` (
  `user_id` int(11) NOT NULL,
  `type` varchar(40) NOT NULL,
  `delivery_mode` varchar(12) NOT NULL DEFAULT 'instant',
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `show_toast` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`,`type`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: notifications
--
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` varchar(40) NOT NULL,
  `title` varchar(180) NOT NULL,
  `body` text DEFAULT NULL,
  `link_url` varchar(255) DEFAULT NULL,
  `dedup_key` char(40) DEFAULT NULL,
  `importance` varchar(12) NOT NULL DEFAULT 'normal',
  `show_toast` tinyint(1) NOT NULL DEFAULT 0,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_user_read` (`user_id`,`is_read`),
  KEY `idx_user_dedup` (`user_id`,`type`,`dedup_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: operations_campaign_assignments
--
CREATE TABLE IF NOT EXISTS `operations_campaign_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `campaign_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `assigned_by` int(11) NOT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_ops_campaign_user` (`campaign_id`,`user_id`),
  KEY `idx_campaign` (`campaign_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_assigned_by` (`assigned_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: productivity_day_snapshots
--
CREATE TABLE IF NOT EXISTS `productivity_day_snapshots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `agent_id` int(11) NOT NULL,
  `year` int(11) NOT NULL,
  `month` int(11) NOT NULL,
  `work_date` date NOT NULL,
  `counts_json` text NOT NULL,
  `total_leads` int(11) NOT NULL,
  `achieved_mql` decimal(10,2) NOT NULL,
  `daily_percent` decimal(5,1) NOT NULL,
  `met_daily_target` tinyint(1) NOT NULL,
  `base_incentive` int(11) NOT NULL,
  `extra_incentive` int(11) NOT NULL,
  `extra_counts_json` text NOT NULL,
  `daily_incentive` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_agent_month` (`agent_id`,`year`,`month`),
  KEY `idx_work_date` (`work_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: productivity_month_locks
--
CREATE TABLE IF NOT EXISTS `productivity_month_locks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `year` int(11) NOT NULL,
  `month` int(11) NOT NULL,
  `locked_by` int(11) NOT NULL,
  `locked_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_year_month` (`year`,`month`),
  KEY `idx_locked_at` (`locked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: productivity_month_snapshots
--
CREATE TABLE IF NOT EXISTS `productivity_month_snapshots` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `agent_id` int(11) NOT NULL,
  `year` int(11) NOT NULL,
  `month` int(11) NOT NULL,
  `daily_target_mql` int(11) NOT NULL,
  `monthly_target_mql` int(11) NOT NULL,
  `working_days` int(11) NOT NULL,
  `days_elapsed` int(11) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `total_leads` int(11) NOT NULL,
  `total_mql` decimal(10,2) NOT NULL,
  `overall_percent` decimal(5,1) NOT NULL,
  `days_met_daily` int(11) NOT NULL,
  `met_monthly` tinyint(1) NOT NULL,
  `daily_incentives` int(11) NOT NULL,
  `monthly_incentive` int(11) NOT NULL,
  `total_incentives` int(11) NOT NULL,
  `locked_by` int(11) NOT NULL,
  `locked_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_agent_month` (`agent_id`,`year`,`month`),
  KEY `idx_year_month` (`year`,`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: productivity_targets
--
CREATE TABLE IF NOT EXISTS `productivity_targets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `agent_id` int(11) NOT NULL,
  `year` int(11) NOT NULL,
  `month` int(11) NOT NULL,
  `working_days` int(11) NOT NULL,
  `daily_target` int(11) NOT NULL,
  `monthly_target` int(11) NOT NULL,
  `minimum_target` int(11) DEFAULT NULL,
  `assigned_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_agent_month` (`agent_id`,`year`,`month`),
  KEY `idx_year_month` (`year`,`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: qa_assignment_requests
--
CREATE TABLE IF NOT EXISTS `qa_assignment_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `requested_by` int(11) NOT NULL,
  `message` text NOT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'Open',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `resolved_by` int(11) DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`),
  KEY `idx_requested_by` (`requested_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: qa_audit_logs
--
CREATE TABLE IF NOT EXISTS `qa_audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `lead_id` int(11) NOT NULL,
  `prev_status` varchar(32) DEFAULT NULL,
  `campaign_id` int(11) NOT NULL,
  `qa_status` varchar(32) NOT NULL,
  `qa_comment` text DEFAULT NULL,
  `qa_client_comment` text DEFAULT NULL,
  `client_delivery_status` varchar(20) NOT NULL DEFAULT 'Pending',
  `qa_reviewed_by` int(11) NOT NULL,
  `reviewed_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lead` (`lead_id`),
  KEY `idx_campaign` (`campaign_id`),
  KEY `idx_reviewer` (`qa_reviewed_by`),
  KEY `idx_reviewed_at` (`reviewed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: qa_campaign_assignments
--
CREATE TABLE IF NOT EXISTS `qa_campaign_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `campaign_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `assigned_by` int(11) NOT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_qa_campaign_user` (`campaign_id`,`user_id`),
  KEY `idx_campaign` (`campaign_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_assigned_by` (`assigned_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: revenue_invoice_billto_profiles
--
CREATE TABLE IF NOT EXISTS `revenue_invoice_billto_profiles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `label` varchar(120) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `client_code` varchar(50) DEFAULT NULL,
  `bill_to_name` varchar(200) DEFAULT NULL,
  `bill_to_address` text DEFAULT NULL,
  `bill_to_contacts` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_label` (`user_id`,`label`),
  KEY `idx_user` (`user_id`),
  KEY `idx_client_code` (`client_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: revenue_invoice_items
--
CREATE TABLE IF NOT EXISTS `revenue_invoice_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `description` varchar(255) NOT NULL,
  `qty` decimal(12,2) NOT NULL DEFAULT 1.00,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_invoice` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: revenue_invoice_settings
--
CREATE TABLE IF NOT EXISTS `revenue_invoice_settings` (
  `user_id` int(11) NOT NULL,
  `bill_from_name` varchar(200) DEFAULT NULL,
  `bill_from_address` text DEFAULT NULL,
  `bill_from_city_state` varchar(200) DEFAULT NULL,
  `bill_from_country` varchar(120) DEFAULT NULL,
  `bill_from_email` varchar(255) DEFAULT NULL,
  `bill_from_phone` varchar(50) DEFAULT NULL,
  `bank_name` varchar(200) DEFAULT NULL,
  `account_name` varchar(200) DEFAULT NULL,
  `account_number` varchar(80) DEFAULT NULL,
  `ifsc_code` varchar(40) DEFAULT NULL,
  `swift_code` varchar(40) DEFAULT NULL,
  `beneficiary_address` text DEFAULT NULL,
  `beneficiary_city_state` varchar(200) DEFAULT NULL,
  `signature_path` varchar(255) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: revenue_invoices
--
CREATE TABLE IF NOT EXISTS `revenue_invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoice_no` varchar(40) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Draft',
  `issue_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `currency` varchar(8) NOT NULL DEFAULT 'USD',
  `client_id` int(11) DEFAULT NULL,
  `client_code` varchar(50) DEFAULT NULL,
  `client_name` varchar(200) DEFAULT NULL,
  `bill_to_name` varchar(200) DEFAULT NULL,
  `bill_to_address` text DEFAULT NULL,
  `campaign_id` int(11) DEFAULT NULL,
  `month_str` varchar(7) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_rate` decimal(6,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL,
  `bill_to_contact_name` varchar(180) DEFAULT NULL,
  `bill_to_contact_email` varchar(255) DEFAULT NULL,
  `bill_to_contact_phone` varchar(50) DEFAULT NULL,
  `bill_from_name` varchar(200) DEFAULT NULL,
  `bill_from_address` text DEFAULT NULL,
  `bill_from_city_state` varchar(200) DEFAULT NULL,
  `bill_from_country` varchar(120) DEFAULT NULL,
  `bank_name` varchar(200) DEFAULT NULL,
  `account_name` varchar(200) DEFAULT NULL,
  `account_number` varchar(80) DEFAULT NULL,
  `ifsc_code` varchar(40) DEFAULT NULL,
  `swift_code` varchar(40) DEFAULT NULL,
  `beneficiary_address` text DEFAULT NULL,
  `beneficiary_city_state` varchar(200) DEFAULT NULL,
  `signature_path` varchar(255) DEFAULT NULL,
  `bill_from_email` varchar(255) DEFAULT NULL,
  `bill_from_phone` varchar(50) DEFAULT NULL,
  `bill_to_contacts` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_invoice_no` (`invoice_no`),
  KEY `idx_status` (`status`),
  KEY `idx_issue` (`issue_date`),
  KEY `idx_client` (`client_id`),
  KEY `idx_campaign` (`campaign_id`),
  KEY `idx_month` (`month_str`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: revenue_manual_expenses
--
CREATE TABLE IF NOT EXISTS `revenue_manual_expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `expense_date` date NOT NULL,
  `category` varchar(80) NOT NULL,
  `description` text DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `currency` varchar(8) NOT NULL DEFAULT 'INR',
  `campaign_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_date` (`expense_date`),
  KEY `idx_campaign` (`campaign_id`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: sales_client_ownership
--
CREATE TABLE IF NOT EXISTS `sales_client_ownership` (
  `client_id` int(11) NOT NULL,
  `owner_id` int(11) NOT NULL,
  `manager_id` int(11) DEFAULT NULL,
  `source_sales_lead_id` int(11) DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `assigned_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`client_id`),
  KEY `idx_owner` (`owner_id`),
  KEY `idx_manager` (`manager_id`),
  KEY `idx_assigned_at` (`assigned_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: sales_lead_activities
--
CREATE TABLE IF NOT EXISTS `sales_lead_activities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sales_lead_id` int(11) NOT NULL,
  `status` varchar(40) DEFAULT NULL,
  `comment` text NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lead` (`sales_lead_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: sales_leads
--
CREATE TABLE IF NOT EXISTS `sales_leads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_name` varchar(200) NOT NULL,
  `website` varchar(255) DEFAULT NULL,
  `website_domain` varchar(255) DEFAULT NULL,
  `industry` varchar(120) DEFAULT NULL,
  `company_size` varchar(60) DEFAULT NULL,
  `country` varchar(120) DEFAULT NULL,
  `contact_name` varchar(180) DEFAULT NULL,
  `contact_job_title` varchar(120) DEFAULT NULL,
  `contact_email` varchar(255) DEFAULT NULL,
  `contact_email_domain` varchar(255) DEFAULT NULL,
  `contact_phone` varchar(50) DEFAULT NULL,
  `linkedin_url` varchar(255) DEFAULT NULL,
  `lead_source` varchar(40) NOT NULL DEFAULT 'Manual Outreach',
  `status` varchar(40) NOT NULL DEFAULT 'New',
  `priority` varchar(20) NOT NULL DEFAULT 'Normal',
  `expected_opportunity_size` decimal(12,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `owner_id` int(11) NOT NULL,
  `sales_manager_id` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `last_activity_at` datetime DEFAULT NULL,
  `next_follow_up_at` datetime DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_owner` (`owner_id`),
  KEY `idx_manager` (`sales_manager_id`),
  KEY `idx_company` (`company_name`),
  KEY `idx_website_domain` (`website_domain`),
  KEY `idx_email_domain` (`contact_email_domain`),
  KEY `idx_linkedin` (`linkedin_url`),
  KEY `idx_client` (`client_id`),
  KEY `idx_last_activity` (`last_activity_at`),
  KEY `idx_next_followup` (`next_follow_up_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: sales_manager_sdr_map
--
CREATE TABLE IF NOT EXISTS `sales_manager_sdr_map` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `manager_user_id` int(11) NOT NULL,
  `sdr_user_id` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_manager_sdr` (`manager_user_id`,`sdr_user_id`),
  KEY `idx_manager` (`manager_user_id`),
  KEY `idx_sdr` (`sdr_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: sales_targets
--
CREATE TABLE IF NOT EXISTS `sales_targets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `year` int(11) NOT NULL,
  `month` int(11) NOT NULL,
  `target_new_accounts` int(11) NOT NULL DEFAULT 0,
  `target_revenue_usd` decimal(12,2) NOT NULL DEFAULT 0.00,
  `assigned_by` int(11) NOT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_month` (`user_id`,`year`,`month`),
  KEY `idx_year_month` (`year`,`month`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: tags
--
CREATE TABLE IF NOT EXISTS `tags` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: team_campaigns
--
CREATE TABLE IF NOT EXISTS `team_campaigns` (
  `team_id` int(11) NOT NULL,
  `campaign_id` int(11) NOT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`team_id`,`campaign_id`),
  KEY `campaign_id` (`campaign_id`),
  KEY `team_id` (`team_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: team_members
--
CREATE TABLE IF NOT EXISTS `team_members` (
  `team_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `member_role` varchar(16) NOT NULL DEFAULT 'member',
  `added_by` int(11) DEFAULT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`team_id`,`user_id`),
  KEY `user_id` (`user_id`),
  KEY `team_id` (`team_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: teams
--
CREATE TABLE IF NOT EXISTS `teams` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `team_name` varchar(120) NOT NULL,
  `manager_user_id` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_team_name` (`team_name`),
  KEY `manager_user_id` (`manager_user_id`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: url_previews
--
CREATE TABLE IF NOT EXISTS `url_previews` (
  `url_hash` char(64) NOT NULL,
  `url` text NOT NULL,
  `final_url` text DEFAULT NULL,
  `preview_title` varchar(255) DEFAULT NULL,
  `preview_description` text DEFAULT NULL,
  `preview_image` varchar(512) DEFAULT NULL,
  `fetch_status` varchar(20) NOT NULL DEFAULT 'ok',
  `http_status` int(11) DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL,
  `fetched_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`url_hash`),
  KEY `idx_fetched` (`fetched_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: user_bank_details
--
CREATE TABLE IF NOT EXISTS `user_bank_details` (
  `user_id` int(11) NOT NULL,
  `bank_name` varchar(120) DEFAULT NULL,
  `account_number` varchar(40) DEFAULT NULL,
  `account_type` varchar(20) DEFAULT NULL,
  `ifsc_code` varchar(20) DEFAULT NULL,
  `pan_number` varchar(20) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: user_documents
--
CREATE TABLE IF NOT EXISTS `user_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `category` varchar(60) NOT NULL,
  `doc_type` varchar(80) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `mime_type` varchar(120) DEFAULT NULL,
  `file_size` int(11) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_user_cat` (`user_id`,`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: user_ip_access
--
CREATE TABLE IF NOT EXISTS `user_ip_access` (
  `user_id` int(11) NOT NULL,
  `mode` varchar(16) NOT NULL DEFAULT 'open',
  `allowed_ips` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  KEY `mode` (`mode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: user_personal_details
--
CREATE TABLE IF NOT EXISTS `user_personal_details` (
  `user_id` int(11) NOT NULL,
  `personal_email` varchar(190) DEFAULT NULL,
  `emergency_contact_number` varchar(40) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: user_presence
--
CREATE TABLE IF NOT EXISTS `user_presence` (
  `user_id` int(11) NOT NULL,
  `last_seen` datetime NOT NULL DEFAULT current_timestamp(),
  `is_online` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: user_sessions
--
CREATE TABLE IF NOT EXISTS `user_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `login_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `logout_time` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: users
--
CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `role` varchar(50) NOT NULL DEFAULT 'agent',
  `is_active` tinyint(1) DEFAULT 1,
  `is_locked` tinyint(1) DEFAULT 0,
  `locked_until` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `profile_pic` varchar(255) DEFAULT NULL,
  `employee_id` varchar(50) DEFAULT NULL,
  `date_of_joining` date DEFAULT NULL,
  `job_title` varchar(100) DEFAULT NULL,
  `phone_number` varchar(20) DEFAULT NULL,
  `department` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `emergency_contact` varchar(100) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `vendor_id` int(11) DEFAULT NULL,
  `reporting_manager_id` int(11) DEFAULT NULL,
  `onboarding_notes` text DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `employee_id` (`employee_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: vendor_billing_profiles
--
CREATE TABLE IF NOT EXISTS `vendor_billing_profiles` (
  `vendor_id` int(11) NOT NULL,
  `billing_name` varchar(180) DEFAULT NULL,
  `billing_email` varchar(180) DEFAULT NULL,
  `billing_phone` varchar(40) DEFAULT NULL,
  `billing_address` text DEFAULT NULL,
  `tax_id` varchar(120) DEFAULT NULL,
  `bank_name` varchar(180) DEFAULT NULL,
  `bank_account_name` varchar(180) DEFAULT NULL,
  `bank_account_number` varchar(120) DEFAULT NULL,
  `bank_ifsc_swift` varchar(120) DEFAULT NULL,
  `bank_iban` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`vendor_id`),
  CONSTRAINT `vendor_billing_profiles_ibfk_1` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: vendor_campaign_map
--
CREATE TABLE IF NOT EXISTS `vendor_campaign_map` (
  `vendor_id` int(11) NOT NULL,
  `campaign_id` int(11) NOT NULL,
  `vendor_cpl` decimal(10,2) DEFAULT NULL,
  `vendor_cpl_currency` varchar(8) DEFAULT NULL,
  `uploads_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`vendor_id`,`campaign_id`),
  KEY `idx_campaign` (`campaign_id`),
  KEY `idx_vendor` (`vendor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: vendor_user_map
--
CREATE TABLE IF NOT EXISTS `vendor_user_map` (
  `vendor_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `assigned_by` int(11) DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`vendor_id`,`user_id`),
  KEY `idx_vendor` (`vendor_id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table: vendors
--
CREATE TABLE IF NOT EXISTS `vendors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vendor_code` varchar(50) NOT NULL,
  `name` varchar(160) NOT NULL,
  `website` varchar(255) DEFAULT NULL,
  `contact_name` varchar(160) DEFAULT NULL,
  `contact_email` varchar(255) DEFAULT NULL,
  `contact_phone` varchar(50) DEFAULT NULL,
  `country` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `vendor_code` (`vendor_code`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS=1;
