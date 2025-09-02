CREATE TABLE IF NOT EXISTS "migrations"(
  "id" integer primary key autoincrement not null,
  "migration" varchar not null,
  "batch" integer not null
);
CREATE TABLE IF NOT EXISTS "users"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "email" varchar not null,
  "email_verified_at" datetime,
  "password" varchar not null,
  "remember_token" varchar,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "users_email_unique" on "users"("email");
CREATE TABLE IF NOT EXISTS "password_reset_tokens"(
  "email" varchar not null,
  "token" varchar not null,
  "created_at" datetime,
  primary key("email")
);
CREATE TABLE IF NOT EXISTS "sessions"(
  "id" varchar not null,
  "user_id" integer,
  "ip_address" varchar,
  "user_agent" text,
  "payload" text not null,
  "last_activity" integer not null,
  primary key("id")
);
CREATE INDEX "sessions_user_id_index" on "sessions"("user_id");
CREATE INDEX "sessions_last_activity_index" on "sessions"("last_activity");
CREATE TABLE IF NOT EXISTS "cache"(
  "key" varchar not null,
  "value" text not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE TABLE IF NOT EXISTS "cache_locks"(
  "key" varchar not null,
  "owner" varchar not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE TABLE IF NOT EXISTS "jobs"(
  "id" integer primary key autoincrement not null,
  "queue" varchar not null,
  "payload" text not null,
  "attempts" integer not null,
  "reserved_at" integer,
  "available_at" integer not null,
  "created_at" integer not null
);
CREATE INDEX "jobs_queue_index" on "jobs"("queue");
CREATE TABLE IF NOT EXISTS "job_batches"(
  "id" varchar not null,
  "name" varchar not null,
  "total_jobs" integer not null,
  "pending_jobs" integer not null,
  "failed_jobs" integer not null,
  "failed_job_ids" text not null,
  "options" text,
  "cancelled_at" integer,
  "created_at" integer not null,
  "finished_at" integer,
  primary key("id")
);
CREATE TABLE IF NOT EXISTS "failed_jobs"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "connection" text not null,
  "queue" text not null,
  "payload" text not null,
  "exception" text not null,
  "failed_at" datetime not null default CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs"("uuid");
CREATE TABLE IF NOT EXISTS "vin_wmis"(
  "id" integer primary key autoincrement not null,
  "wmi" varchar not null,
  "manufacturer" varchar not null,
  "country" varchar not null,
  "country_code" varchar not null,
  "start_year" integer not null,
  "end_year" integer,
  "verified_at" date not null,
  "verified_by" varchar not null,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "vin_wmis_wmi_start_year_index" on "vin_wmis"(
  "wmi",
  "start_year"
);
CREATE INDEX "vin_wmis_manufacturer_country_code_index" on "vin_wmis"(
  "manufacturer",
  "country_code"
);
CREATE UNIQUE INDEX "vin_wmis_wmi_unique" on "vin_wmis"("wmi");
CREATE TABLE IF NOT EXISTS "vehicle_specs"(
  "id" integer primary key autoincrement not null,
  "make" varchar not null,
  "model" varchar not null,
  "variant" varchar,
  "year" integer not null,
  "length_m" numeric not null,
  "width_m" numeric not null,
  "height_m" numeric not null,
  "wheelbase_m" numeric not null,
  "weight_kg" integer not null,
  "engine_cc" integer not null,
  "fuel_type" varchar check("fuel_type" in('petrol', 'diesel', 'hybrid', 'phev', 'electric')) not null,
  "wmi_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("wmi_id") references "vin_wmis"("id") on delete cascade
);
CREATE INDEX "vehicle_specs_make_model_year_index" on "vehicle_specs"(
  "make",
  "model",
  "year"
);
CREATE INDEX "vehicle_specs_fuel_type_year_index" on "vehicle_specs"(
  "fuel_type",
  "year"
);
CREATE INDEX "vehicle_specs_wmi_id_year_index" on "vehicle_specs"(
  "wmi_id",
  "year"
);
CREATE TABLE IF NOT EXISTS "intakes"(
  "id" integer primary key autoincrement not null,
  "status" varchar not null default 'uploaded',
  "source" varchar,
  "notes" text,
  "priority" varchar not null default 'normal',
  "created_at" datetime,
  "updated_at" datetime,
  "robaws_offer_id" varchar,
  "robaws_offer_number" varchar,
  "export_payload_hash" varchar,
  "export_attempt_count" integer not null default '0',
  "last_export_error" text,
  "contact_email" varchar,
  "contact_phone" varchar,
  "last_export_error_at" datetime
);
CREATE INDEX "intakes_status_priority_index" on "intakes"(
  "status",
  "priority"
);
CREATE TABLE IF NOT EXISTS "quotations"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "document_id" integer,
  "robaws_id" varchar not null,
  "quotation_number" varchar,
  "status" varchar not null default 'draft',
  "client_name" varchar,
  "client_email" varchar,
  "client_phone" varchar,
  "origin_port" varchar,
  "destination_port" varchar,
  "cargo_type" varchar,
  "container_type" varchar,
  "weight" numeric,
  "volume" numeric,
  "pieces" integer,
  "estimated_cost" numeric,
  "currency" varchar not null default 'EUR',
  "valid_until" datetime,
  "robaws_data" text,
  "auto_created" tinyint(1) not null default '0',
  "created_from_document" tinyint(1) not null default '0',
  "sent_at" datetime,
  "accepted_at" datetime,
  "rejected_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("document_id") references "documents"("id") on delete set null
);
CREATE INDEX "quotations_user_id_status_index" on "quotations"(
  "user_id",
  "status"
);
CREATE INDEX "quotations_robaws_id_index" on "quotations"("robaws_id");
CREATE INDEX "quotations_status_index" on "quotations"("status");
CREATE INDEX "quotations_auto_created_index" on "quotations"("auto_created");
CREATE UNIQUE INDEX "quotations_robaws_id_unique" on "quotations"("robaws_id");
CREATE TABLE IF NOT EXISTS "documents"(
  "id" integer primary key autoincrement not null,
  "intake_id" integer,
  "filename" varchar not null,
  "file_path" varchar not null,
  "mime_type" varchar not null,
  "file_size" integer not null,
  "has_text_layer" tinyint(1),
  "document_type" varchar,
  "page_count" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "extraction_data" text,
  "extraction_confidence" numeric,
  "extraction_service" varchar,
  "extraction_status" varchar,
  "extracted_at" datetime,
  "robaws_quotation_id" varchar,
  "robaws_quotation_data" text,
  "robaws_formatted_at" datetime,
  "robaws_synced_at" datetime,
  "robaws_document_id" varchar,
  "robaws_uploaded_at" datetime,
  "robaws_upload_attempted_at" datetime,
  "upload_status" varchar,
  "upload_error" text,
  "upload_method" varchar,
  "original_filename" varchar,
  "storage_disk" varchar not null default 'local',
  "robaws_last_sync_at" datetime,
  "robaws_sync_status" varchar check("robaws_sync_status" in('ready', 'needs_review', 'synced', 'not_found', 'error')),
  "source_message_id" varchar,
  "source_content_sha" varchar,
  "robaws_last_upload_sha" varchar,
  "processing_status" varchar not null default 'pending',
  "status" varchar,
  foreign key("intake_id") references "intakes"("id") on delete set null
);
CREATE INDEX "documents_extraction_status_index" on "documents"(
  "extraction_status"
);
CREATE INDEX "documents_intake_id_document_type_index" on "documents"(
  "intake_id",
  "document_type"
);
CREATE INDEX "documents_robaws_document_id_index" on "documents"(
  "robaws_document_id"
);
CREATE INDEX "documents_robaws_quotation_id_index" on "documents"(
  "robaws_quotation_id"
);
CREATE INDEX "documents_upload_status_index" on "documents"("upload_status");
CREATE TABLE IF NOT EXISTS "extractions"(
  "id" integer primary key autoincrement not null,
  "intake_id" integer,
  "raw_json" text not null,
  "confidence" numeric not null default('0'),
  "verified_at" datetime,
  "verified_by" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "document_id" integer,
  "status" varchar not null default('pending'),
  "extracted_data" text,
  "service_used" varchar,
  "analysis_type" varchar,
  "robaws_quotation_id" varchar,
  "robaws_quotation_exists" tinyint(1) not null default '1',
  foreign key("document_id") references documents("id") on delete cascade on update no action,
  foreign key("intake_id") references intakes("id") on delete cascade on update no action
);
CREATE INDEX "extractions_confidence_index" on "extractions"("confidence");
CREATE INDEX "extractions_intake_id_index" on "extractions"("intake_id");
CREATE INDEX "extractions_robaws_quotation_id_index" on "extractions"(
  "robaws_quotation_id"
);
CREATE INDEX "documents_source_message_id_index" on "documents"(
  "source_message_id"
);
CREATE INDEX "documents_source_content_sha_index" on "documents"(
  "source_content_sha"
);
CREATE UNIQUE INDEX "documents_source_message_id_unique" on "documents"(
  "source_message_id"
);
CREATE UNIQUE INDEX "documents_source_content_sha_unique" on "documents"(
  "source_content_sha"
);
CREATE INDEX "documents_robaws_dedup_idx" on "documents"(
  "robaws_quotation_id",
  "robaws_last_upload_sha"
);
CREATE INDEX "documents_processing_status_index" on "documents"(
  "processing_status"
);
CREATE TABLE IF NOT EXISTS "robaws_documents"(
  "id" integer primary key autoincrement not null,
  "document_id" integer,
  "robaws_offer_id" varchar not null,
  "robaws_document_id" varchar,
  "sha256" varchar not null,
  "filename" varchar not null,
  "filesize" integer not null,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "robaws_documents_robaws_offer_id_sha256_unique" on "robaws_documents"(
  "robaws_offer_id",
  "sha256"
);
CREATE INDEX "robaws_documents_document_id_index" on "robaws_documents"(
  "document_id"
);
CREATE INDEX "robaws_documents_robaws_offer_id_index" on "robaws_documents"(
  "robaws_offer_id"
);
CREATE INDEX "robaws_documents_robaws_document_id_index" on "robaws_documents"(
  "robaws_document_id"
);
CREATE INDEX "robaws_documents_sha256_index" on "robaws_documents"("sha256");
CREATE UNIQUE INDEX "robaws_offer_sha_unique" on "robaws_documents"(
  "robaws_offer_id",
  "sha256"
);
CREATE INDEX "intakes_robaws_offer_id_index" on "intakes"("robaws_offer_id");
CREATE INDEX "documents_status_index" on "documents"("status");
CREATE TABLE IF NOT EXISTS "intake_files"(
  "id" integer primary key autoincrement not null,
  "intake_id" integer not null,
  "filename" varchar not null,
  "storage_path" varchar not null,
  "storage_disk" varchar not null default 'local',
  "mime_type" varchar not null,
  "file_size" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("intake_id") references "intakes"("id") on delete cascade
);
CREATE INDEX "intake_files_intake_id_index" on "intake_files"("intake_id");
CREATE INDEX "intakes_contact_email_index" on "intakes"("contact_email");
CREATE INDEX "intakes_contact_phone_index" on "intakes"("contact_phone");

INSERT INTO migrations VALUES(1,'0001_01_01_000000_create_users_table',1);
INSERT INTO migrations VALUES(2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO migrations VALUES(3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO migrations VALUES(4,'2024_12_28_120000_add_export_tracking_fields_to_intakes_table',1);
INSERT INTO migrations VALUES(5,'2025_08_15_193325_create_vin_wmis_table',1);
INSERT INTO migrations VALUES(6,'2025_08_15_193326_create_vehicle_specs_table',1);
INSERT INTO migrations VALUES(7,'2025_08_15_205308_create_intakes_table',1);
INSERT INTO migrations VALUES(8,'2025_08_15_205321_create_documents_table',1);
INSERT INTO migrations VALUES(9,'2025_08_15_205334_create_extractions_table',1);
INSERT INTO migrations VALUES(10,'2025_08_23_044116_add_missing_columns_to_extractions_table',1);
INSERT INTO migrations VALUES(11,'2025_08_25_202527_add_robaws_fields_to_documents_table',1);
INSERT INTO migrations VALUES(12,'2025_08_25_202548_create_quotations_table',1);
INSERT INTO migrations VALUES(13,'2025_08_27_061324_add_analysis_type_to_extractions_table',1);
INSERT INTO migrations VALUES(14,'2025_08_27_201146_add_robaws_sync_fields_to_documents_table',1);
INSERT INTO migrations VALUES(15,'2025_08_30_083846_add_file_upload_tracking_to_documents_table',1);
INSERT INTO migrations VALUES(16,'2025_08_30_090802_make_intake_id_nullable_in_documents_table',1);
INSERT INTO migrations VALUES(17,'2025_08_30_090811_make_intake_id_nullable_in_documents_table',1);
INSERT INTO migrations VALUES(18,'2025_08_30_094835_add_storage_disk_to_documents_table',1);
INSERT INTO migrations VALUES(19,'2025_08_30_095128_make_intake_id_nullable_in_extractions_table',1);
INSERT INTO migrations VALUES(20,'2025_08_30_100621_add_robaws_quotation_id_to_extractions_table',1);
INSERT INTO migrations VALUES(21,'2025_08_30_114333_add_sync_tracking_to_documents_and_extractions_table',1);
INSERT INTO migrations VALUES(22,'2025_08_30_130127_fix_extractions_raw_json_nullable',1);
INSERT INTO migrations VALUES(23,'2025_08_31_105059_add_email_dedupe_columns_to_documents',1);
INSERT INTO migrations VALUES(24,'2025_08_31_110453_add_storage_and_robaws_columns_to_documents_table',1);
INSERT INTO migrations VALUES(25,'2025_08_31_114908_create_robaws_documents_table',1);
INSERT INTO migrations VALUES(26,'2025_08_31_115458_add_unique_constraint_to_robaws_documents_table',1);
INSERT INTO migrations VALUES(27,'2025_08_31_120658_add_robaws_fields_to_intakes_table',1);
INSERT INTO migrations VALUES(28,'2025_08_31_194203_add_status_to_documents_table',1);
INSERT INTO migrations VALUES(29,'2025_09_02_142420_create_intake_files_table',1);
INSERT INTO migrations VALUES(30,'2025_12_28_120000_add_export_tracking_fields_to_intakes_table',1);
INSERT INTO migrations VALUES(31,'2025_09_02_163632_add_contact_and_export_error_to_intakes_table',2);
