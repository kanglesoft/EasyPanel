alter table vhost add column product_id INTEGER NOT NULL DEFAULT 0;
alter table vhost add column flow INTEGER NOT NULL DEFAULT 0;
alter table vhost add column hcount INTEGER NOT NULL DEFAULT 0;
alter table vhost add column cs INTEGER NOT NULL DEFAULT 0;
alter table vhost add column envs TEXT DEFAULT NULL;
alter table vhost add column cdn INTEGER NOT NULL DEFAULT 0;
ALTER TABLE vhost ADD COLUMN [db_type] VARCHAR(16)  NULL;
ALTER TABLE vhost ADD COLUMN [ext_passwd] INTEGER DEFAULT 0;
CREATE TABLE [manynode] (
	[name] VARCHAR(255)  NOT NULL PRIMARY KEY,
	[host] VARCHAR(255)  NULL,
	[mem] VARCHAR(255)  NULL
);
ALTER TABLE vhost ADD COLUMN [log_handle] INTEGER DEFAULT 0;
ALTER TABLE product ADD COLUMN [log_handle] INTEGER DEFAULT 0;

alter table product add column cs INTEGER NOT NULL DEFAULT 0;
alter table product add column envs TEXT DEFAULT NULL;
alter table product add column cdn INTEGER NOT NULL DEFAULT 0;
alter table product add column	max_worker INTEGER DEFAULT 0 NULL;
alter table product add column	max_queue INTEGER DEFAULT 0 NULL;
alter table product add column max_subdir INTEGER NOT NULL DEFAULT 0;
alter table vhost add column max_subdir INTEGER NOT NULL DEFAULT 0;
ALTER TABLE product ADD COLUMN [db_type] VARCHAR(16)  NULL;
alter table manynode add column syncstatus INTEGER  NULL;
alter table manynode add column synctime datatime  NULL;
alter table vhost add column expire_time2 INTEGER NOT NULL DEFAULT 0;
alter table manynode add column skey TEXT NULL;
alter table vhost add column sync_seq INTEGER NOT NULL DEFAULT 0;
alter table vhost add column flow_limit INTEGER NOT NULL DEFAULT 0;
alter table product add column flow_limit INTEGER NOT NULL DEFAULT 0;

alter table vhost_info add column id INTEGER NOT NULL DEFAULT 1000;
CREATE INDEX [IDX_VHOST_INFO_ID] ON [vhost_info]([id]  ASC);
alter table vhost add column ftp_connect INTEGER NOT NULL DEFAULT 0;
alter table vhost add column ftp_usl INTEGER NOT NULL DEFAULT 0;
alter table vhost add column ftp_dsl INTEGER NOT NULL DEFAULT 0;
alter table product add column ftp_connect INTEGER NOT NULL DEFAULT 0;
alter table product add column ftp_usl INTEGER NOT NULL DEFAULT 0;
alter table product add column ftp_dsl INTEGER NOT NULL DEFAULT 0;
ALTER TABLE product ADD COLUMN [module] TEXT  NULL;
ALTER TABLE vhost ADD COLUMN [module] TEXT  NULL;
ALTER TABLE vhost ADD COLUMN [certificate] TEXT  NULL;
ALTER TABLE vhost ADD COLUMN [certificate_key] TEXT  NULL;
ALTER TABLE product ADD COLUMN [ip] TEXT  NULL;
ALTER TABLE vhost ADD COLUMN [ip] TEXT  NULL;
ALTER TABLE product ADD COLUMN [port] TEXT  NULL;
ALTER TABLE vhost ADD COLUMN [port] TEXT  NULL;
ALTER TABLE product ADD COLUMN [ssi] INTEGER;
ALTER TABLE vhost ADD COLUMN [ssi] INTEGER;
ALTER TABLE vhost ADD COLUMN [ftp_subdir] TEXT  NULL;
alter table vhost add column last_password_error TIMESTAMP NOT NULL DEFAULT 0;
alter table vhost add column password_error_count INTEGER NOT NULL DEFAULT 0;
alter table vhost add column password_security TEXT NULL;
alter table vhost add column ignore_backup INTEGER NOT NULL DEFAULT 0;
alter table product add column ignore_backup INTEGER NOT NULL DEFAULT 0;
alter table vhost add column cron INTEGER NOT NULL DEFAULT 0;
alter table product add column cron INTEGER NOT NULL DEFAULT 0;
alter table product add column [default_index] TEXT  NULL;
CREATE TABLE [cron] (
	[id] INTEGER  NOT NULL PRIMARY KEY AUTOINCREMENT,
	[vhost] TEXT  NULL,
	[type] TEXT NULL,
	[min] TEXT  NULL,
	[hour] TEXT  NULL,
	[day] TEXT  NULL,
	[month] TEXT  NULL,
	[mday] TEXT  NULL,
	[cmd_type] INTEGER DEFAULT 0 NOT NULL,
	[cmd] TEXT  NULL,
	[stdin_file] TEXT  NULL,
	[stdout_file] TEXT  NULL,
	[stderr_file] INTEGER DEFAULT 1 NOT NULL
);
CREATE INDEX [IDX_CRON_VHOST] ON [cron] (
	[vhost]  ASC
);
CREATE TABLE [httpauth] (
	[vhost] TEXT  NULL,
	[user] TEXT  NULL,
	[passwd] TEXT  NULL,
	PRIMARY KEY ([vhost],[user])
);
CREATE INDEX [IDX_VHOST_INFO_VHOST] ON [vhost_info](
	[vhost]  ASC
);
alter table vhost add column recordid INTEGER NOT NULL DEFAULT 0;
alter table vhost add column http2 INTEGER NOT NULL DEFAULT 0;
alter table manynode add column port INTEGER NOT NULL DEFAULT 3312;
CREATE TABLE [cert_ledger] (
	[id] INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	[vhost] TEXT NOT NULL,
	[domain] TEXT NOT NULL,
	[brand] TEXT,
	[sans] TEXT,
	[not_before] INTEGER NOT NULL DEFAULT 0,
	[not_after] INTEGER NOT NULL DEFAULT 0,
	[source] TEXT NOT NULL DEFAULT 'manual',
	[cert_file] TEXT,
	[note] TEXT,
	[created_at] INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX [IDX_CERT_LEDGER_VHOST] ON [cert_ledger] (
	[vhost]  ASC
);
CREATE TABLE IF NOT EXISTS [admin_cron] (
	[id] INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	[name] TEXT NULL,
	[type] TEXT NULL,
	[cmd] TEXT NULL,
	[cron] TEXT NULL,
	[flock] INTEGER NOT NULL DEFAULT 1,
	[enabled] INTEGER NOT NULL DEFAULT 1,
	[last_run] INTEGER NOT NULL DEFAULT 0,
	[last_status] INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS [dns_credential] (
	[id] INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	[provider] TEXT NOT NULL,
	[name] TEXT NULL,
	[domain] TEXT NULL,
	[remark] TEXT NULL,
	[credential] TEXT NOT NULL,
	[is_default] INTEGER NOT NULL DEFAULT 0,
	[cre_time] INTEGER NOT NULL DEFAULT 0,
	[upd_time] INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS [IDX_DNS_CRED_PROVIDER] ON [dns_credential] ([provider] ASC);
CREATE TABLE IF NOT EXISTS [acme_order_guard] (
	[domain] TEXT NOT NULL PRIMARY KEY,
	[fail_count] INTEGER NOT NULL DEFAULT 0,
	[last_error] TEXT NULL,
	[provider] TEXT NULL,
	[ca] TEXT NULL,
	[first_fail] INTEGER NOT NULL DEFAULT 0,
	[last_fail] INTEGER NOT NULL DEFAULT 0,
	[blocked_until] INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS [acme_hook_token] (
	[token] TEXT NOT NULL PRIMARY KEY,
	[cred] INTEGER NOT NULL,
	[domain] TEXT NULL,
	[exp] INTEGER NOT NULL
);
