-- WebCalendar Core SQLite Schema (Target Architecture)
-- Based on WebCalendar v1.9.13 with PRD 4.0 enhancements

CREATE TABLE webcal_user (
    cal_login VARCHAR(60) NOT NULL,
    cal_passwd VARCHAR(255),
    cal_lastname VARCHAR(60),
    cal_firstname VARCHAR(60),
    cal_is_admin CHAR(1) DEFAULT 'N',
    cal_email VARCHAR(75) NULL,
    cal_enabled CHAR(1) DEFAULT 'Y',
    cal_telephone VARCHAR(60) NULL,
    cal_address VARCHAR(75) NULL,
    cal_title VARCHAR(75) NULL,
    cal_birthday INT NULL,
    cal_last_login INT NULL,
    cal_api_token VARCHAR(255) NULL,
    PRIMARY KEY (cal_login)
);

-- IMPORTANT: Do NOT create a default admin account in the schema.
-- The admin user should be created during installation with a secure password.
-- See the installation wizard or run: INSERT INTO webcal_user ... with a securely hashed password.

CREATE TABLE webcal_entry (
    cal_id INT NOT NULL,
    cal_group_id INT NULL,
    cal_ext_for_id INT NULL,
    cal_create_by VARCHAR(60) NOT NULL,
    cal_date INT NOT NULL,
    cal_time INT NULL,
    cal_mod_date INT,
    cal_mod_time INT,
    cal_duration INT NOT NULL,
    cal_due_date INT DEFAULT NULL,
    cal_due_time INT DEFAULT NULL,
    cal_location VARCHAR(100) DEFAULT NULL,
    cal_url VARCHAR(255) DEFAULT NULL,
    cal_completed INT DEFAULT NULL,
    cal_priority INT DEFAULT 5,
    cal_type CHAR(1) DEFAULT 'E',
    cal_access CHAR(1) DEFAULT 'P',
    cal_name VARCHAR(80) NOT NULL,
    cal_description TEXT,
    -- Target enhancements for RFC 5545 compliance
    cal_uid VARCHAR(255) DEFAULT NULL,
    cal_sequence INT DEFAULT 0,
    cal_transp VARCHAR(11) DEFAULT 'OPAQUE',
    cal_status VARCHAR(20) DEFAULT NULL,
    cal_geo_lat DECIMAL(10,7) DEFAULT NULL,
    cal_geo_lon DECIMAL(10,7) DEFAULT NULL,
    cal_color VARCHAR(16) DEFAULT NULL,
    cal_conference VARCHAR(255) DEFAULT NULL,
    cal_organizer VARCHAR(255) DEFAULT NULL,
    cal_created INT DEFAULT NULL,
    cal_created_time INT DEFAULT NULL,
    PRIMARY KEY (cal_id)
);

CREATE TABLE webcal_entry_categories (
    cal_id INT DEFAULT 0 NOT NULL,
    cat_id INT DEFAULT 0 NOT NULL,
    cat_order INT DEFAULT 0 NOT NULL,
    cat_owner VARCHAR(60) DEFAULT '' NOT NULL,
    PRIMARY KEY (cal_id, cat_id, cat_order, cat_owner)
);

CREATE INDEX webcal_entry_categories_cat_id ON webcal_entry_categories(cat_id);

CREATE TABLE webcal_entry_repeats (
    cal_id INT DEFAULT 0 NOT NULL,
    cal_type VARCHAR(20),
    cal_end INT,
    cal_frequency INT DEFAULT 1,
    cal_days CHAR(7),
    cal_endtime INT DEFAULT NULL,
    cal_bymonth VARCHAR(60) DEFAULT NULL,
    cal_bymonthday VARCHAR(100) DEFAULT NULL,
    cal_byday VARCHAR(100) DEFAULT NULL,
    cal_bysetpos VARCHAR(60) DEFAULT NULL,
    cal_byweekno VARCHAR(60) DEFAULT NULL,
    cal_byyearday VARCHAR(60) DEFAULT NULL,
    cal_wkst CHAR(2) DEFAULT 'MO',
    cal_count INT DEFAULT NULL,
    -- Target enhancements for sub-daily patterns
    cal_byhour VARCHAR(60) DEFAULT NULL,
    cal_byminute VARCHAR(60) DEFAULT NULL,
    cal_bysecond VARCHAR(60) DEFAULT NULL,
    PRIMARY KEY (cal_id)
);

CREATE TABLE webcal_entry_repeats_not (
    cal_id INT NOT NULL,
    cal_date INT NOT NULL,
    cal_exdate INT NOT NULL DEFAULT 1,
    PRIMARY KEY (cal_id, cal_date)
);

CREATE TABLE webcal_entry_user (
    cal_id INT DEFAULT 0 NOT NULL,
    cal_login VARCHAR(60) NOT NULL,
    cal_status CHAR(1) DEFAULT 'A',
    cal_category INT DEFAULT NULL,
    cal_percent INT DEFAULT 0 NOT NULL,
    PRIMARY KEY (cal_id, cal_login)
);

CREATE TABLE webcal_entry_ext_user (
    cal_id INT DEFAULT 0 NOT NULL,
    cal_fullname VARCHAR(60) NOT NULL,
    cal_email VARCHAR(75) NULL,
    PRIMARY KEY (cal_id, cal_fullname)
);

CREATE TABLE webcal_user_pref (
    cal_login VARCHAR(60) NOT NULL,
    cal_setting VARCHAR(60) NOT NULL,
    cal_value VARCHAR(100) NULL,
    PRIMARY KEY (cal_login, cal_setting)
);

CREATE TABLE webcal_user_layers (
    cal_layerid INT DEFAULT 0 NOT NULL,
    cal_login VARCHAR(60) NOT NULL,
    cal_layeruser VARCHAR(60) NOT NULL,
    cal_color VARCHAR(60) NULL,
    cal_dups CHAR(1) DEFAULT 'N',
    PRIMARY KEY (cal_login, cal_layeruser)
);

CREATE TABLE webcal_site_extras (
    cal_id INT DEFAULT 0 NOT NULL,
    cal_name VARCHAR(60) NOT NULL,
    cal_type INT NOT NULL,
    cal_date INT DEFAULT 0,
    cal_remind INT DEFAULT 0,
    cal_data TEXT
);

CREATE TABLE webcal_reminders (
    cal_id INT NOT NULL DEFAULT 0,
    cal_date INT NOT NULL DEFAULT 0,
    cal_offset INT NOT NULL DEFAULT 0,
    cal_related CHAR(1) NOT NULL DEFAULT 'S',
    cal_before CHAR(1) NOT NULL DEFAULT 'Y',
    cal_last_sent INT DEFAULT NULL,
    cal_repeats INT NOT NULL DEFAULT 0,
    cal_duration INT NOT NULL DEFAULT 0,
    cal_times_sent INT NOT NULL DEFAULT 0,
    cal_action VARCHAR(12) NOT NULL DEFAULT 'EMAIL',
    -- Target enhancements for VAlarm compliance
    cal_description TEXT DEFAULT NULL,
    cal_summary VARCHAR(255) DEFAULT NULL,
    cal_attendee VARCHAR(255) DEFAULT NULL,
    cal_attach VARCHAR(255) DEFAULT NULL,
    cal_time INT DEFAULT NULL,
    PRIMARY KEY (cal_id)
);

CREATE TABLE webcal_group (
    cal_group_id INT NOT NULL,
    cal_owner VARCHAR(60) NULL,
    cal_name VARCHAR(60) NOT NULL,
    cal_last_update INT NOT NULL,
    PRIMARY KEY (cal_group_id)
);

CREATE TABLE webcal_group_user (
    cal_group_id INT NOT NULL,
    cal_login VARCHAR(60) NOT NULL,
    PRIMARY KEY (cal_group_id, cal_login)
);

CREATE TABLE webcal_view (
    cal_view_id INT NOT NULL,
    cal_owner VARCHAR(60) NOT NULL,
    cal_name VARCHAR(60) NOT NULL,
    cal_view_type CHAR(1),
    cal_is_global CHAR(1) DEFAULT 'N' NOT NULL,
    PRIMARY KEY (cal_view_id)
);

CREATE TABLE webcal_view_user (
    cal_view_id INT NOT NULL,
    cal_login VARCHAR(60) NOT NULL,
    PRIMARY KEY (cal_view_id, cal_login)
);

CREATE TABLE webcal_config (
    cal_setting VARCHAR(60) NOT NULL,
    cal_value VARCHAR(100) NULL,
    PRIMARY KEY (cal_setting)
);

INSERT INTO webcal_config (cal_setting, cal_value) VALUES ('WEBCALENDAR_PROGRAM_VERSION', 'v4.0.0');

CREATE TABLE webcal_entry_log (
    cal_log_id INTEGER PRIMARY KEY AUTOINCREMENT,
    cal_entry_id INT NOT NULL,
    cal_login VARCHAR(60) NOT NULL,
    cal_user_cal VARCHAR(60) NULL,
    cal_type CHAR(1) NOT NULL,
    cal_date INT NOT NULL,
    cal_time INT NULL,
    cal_text TEXT
);

CREATE TABLE webcal_categories (
    cat_id INT NOT NULL,
    cat_owner VARCHAR(60) DEFAULT '' NOT NULL,
    cat_name VARCHAR(80) NOT NULL,
    cat_color VARCHAR(16) NULL, -- Increased to 16 for hex colors
    cat_status CHAR(1) DEFAULT 'A',
    cat_icon_mime VARCHAR(32) DEFAULT NULL,
    cat_icon_blob BLOB DEFAULT NULL,
    PRIMARY KEY (cat_id, cat_owner)
);

CREATE TABLE webcal_asst (
    cal_boss VARCHAR(60) NOT NULL,
    cal_assistant VARCHAR(60) NOT NULL,
    PRIMARY KEY (cal_boss, cal_assistant)
);

CREATE TABLE webcal_nonuser_cals (
    cal_login VARCHAR(60) NOT NULL,
    cal_lastname VARCHAR(60) NULL,
    cal_firstname VARCHAR(60) NULL,
    cal_admin VARCHAR(60) NOT NULL,
    cal_is_public CHAR(1) DEFAULT 'N' NOT NULL,
    cal_url VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (cal_login)
);

CREATE TABLE webcal_import (
    cal_import_id INT NOT NULL,
    cal_name VARCHAR(60) NULL,
    cal_date INT NOT NULL,
    cal_check_date INT NULL,
    cal_type VARCHAR(10) NOT NULL,
    cal_login VARCHAR(60) NULL,
    cal_md5 VARCHAR(32) NULL DEFAULT NULL,
    PRIMARY KEY (cal_import_id)
);

CREATE TABLE webcal_import_data (
    cal_import_id INT NOT NULL,
    cal_id INT NOT NULL,
    cal_login VARCHAR(60) NOT NULL,
    cal_import_type VARCHAR(15) NOT NULL,
    cal_external_id VARCHAR(200) NULL,
    PRIMARY KEY (cal_id, cal_login)
);

CREATE INDEX webcal_import_data_type ON webcal_import_data(cal_import_type);
CREATE INDEX webcal_import_data_ext_id ON webcal_import_data(cal_external_id);

CREATE TABLE webcal_report (
    cal_login VARCHAR(60) NOT NULL,
    cal_report_id INT NOT NULL,
    cal_is_global CHAR(1) DEFAULT 'N' NOT NULL,
    cal_report_type VARCHAR(20) NOT NULL,
    cal_include_header CHAR(1) DEFAULT 'Y' NOT NULL,
    cal_report_name VARCHAR(60) NOT NULL,
    cal_time_range INT NOT NULL,
    cal_user VARCHAR(60) NULL,
    cal_allow_nav CHAR(1) DEFAULT 'Y',
    cal_cat_id INT NULL,
    cal_include_empty CHAR(1) DEFAULT 'N',
    cal_show_in_trailer CHAR(1) DEFAULT 'N',
    cal_update_date INT NOT NULL,
    PRIMARY KEY (cal_report_id)
);

CREATE TABLE webcal_report_template (
    cal_report_id INT NOT NULL,
    cal_template_type CHAR(1) NOT NULL,
    cal_template_text TEXT,
    PRIMARY KEY (cal_report_id, cal_template_type)
);

CREATE TABLE webcal_access_user (
    cal_login VARCHAR(60) NOT NULL,
    cal_other_user VARCHAR(60) NOT NULL,
    cal_can_view INT NOT NULL DEFAULT 0,
    cal_can_edit INT NOT NULL DEFAULT 0,
    cal_can_approve INT NOT NULL DEFAULT 0,
    cal_can_invite CHAR(1) NOT NULL DEFAULT 'Y',
    cal_can_email CHAR(1) NOT NULL DEFAULT 'Y',
    cal_see_time_only CHAR(1) NOT NULL DEFAULT 'N',
    PRIMARY KEY (cal_login, cal_other_user)
);

CREATE TABLE webcal_access_function (
    cal_login VARCHAR(60) NOT NULL,
    cal_permissions VARCHAR(64) NOT NULL,
    PRIMARY KEY (cal_login)
);

CREATE TABLE webcal_user_template (
    cal_login VARCHAR(60) NOT NULL DEFAULT '',
    cal_type CHAR(1) NOT NULL DEFAULT '',
    cal_template_text TEXT,
    PRIMARY KEY (cal_login, cal_type)
);

CREATE TABLE webcal_blob (
    cal_blob_id INTEGER PRIMARY KEY AUTOINCREMENT,
    cal_id INT NULL,
    cal_login VARCHAR(60) NULL,
    cal_name VARCHAR(30) NULL,
    cal_description VARCHAR(128) NULL,
    cal_size INT NULL,
    cal_mime_type VARCHAR(60) NULL,
    cal_type CHAR(1) NOT NULL,
    cal_mod_date INT NOT NULL,
    cal_mod_time INT NOT NULL,
    cal_blob BLOB
);

CREATE TABLE webcal_timezones (
    tzid VARCHAR(100) NOT NULL DEFAULT '',
    dtstart VARCHAR(60) DEFAULT NULL,
    dtend VARCHAR(60) DEFAULT NULL,
    vtimezone TEXT,
    PRIMARY KEY (tzid)
);

-- Token storage for sessions and CSRF protection
CREATE TABLE webcal_tokens (
    cal_token VARCHAR(128) NOT NULL,
    cal_type VARCHAR(32) NOT NULL,
    cal_data VARCHAR(255) NOT NULL,
    cal_created_at INT NOT NULL,
    cal_expires_at INT DEFAULT NULL,
    PRIMARY KEY (cal_token, cal_type)
);

CREATE INDEX idx_webcal_tokens_expires ON webcal_tokens(cal_expires_at);
CREATE INDEX idx_webcal_tokens_type_data ON webcal_tokens(cal_type, cal_data);

-- Rate limiting for security (login attempts, API, etc.)
CREATE TABLE webcal_rate_limits (
    cal_id INTEGER PRIMARY KEY AUTOINCREMENT,
    cal_identifier VARCHAR(64) NOT NULL,
    cal_action VARCHAR(32) NOT NULL,
    cal_attempt_at INT NOT NULL
);

CREATE INDEX idx_webcal_rate_limits_lookup ON webcal_rate_limits(cal_identifier, cal_action, cal_attempt_at);
