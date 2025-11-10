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
CREATE TABLE IF NOT EXISTS "pricing_tiers"(
  "id" integer primary key autoincrement not null,
  "code" varchar not null,
  "name" varchar not null,
  "description" text,
  "margin_percentage" numeric not null,
  "color" varchar not null default 'gray',
  "icon" varchar,
  "sort_order" integer not null default '0',
  "is_active" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "pricing_tiers_code_index" on "pricing_tiers"("code");
CREATE INDEX "pricing_tiers_is_active_index" on "pricing_tiers"("is_active");
CREATE INDEX "pricing_tiers_sort_order_index" on "pricing_tiers"("sort_order");
CREATE UNIQUE INDEX "pricing_tiers_code_unique" on "pricing_tiers"("code");
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
  "upload_error" text,
  "upload_method" varchar,
  "original_filename" varchar,
  "storage_disk" varchar not null default 'local',
  "robaws_last_sync_at" datetime,
  "robaws_sync_status" varchar check("robaws_sync_status" in('ready', 'needs_review', 'synced', 'not_found', 'error')),
  "source_message_id" varchar,
  "source_content_sha" varchar,
  "storage_path" varchar,
  "sha256" varchar,
  "robaws_last_upload_sha" varchar,
  "processing_status" varchar not null default 'pending',
  "status" varchar,
  "extraction_meta" text,
  "upload_status" varchar check("upload_status" in('pending', 'uploading', 'uploaded', 'failed', 'failed_permanent')),
  "robaws_client_id" varchar,
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
CREATE INDEX "documents_sha256_index" ON "documents"("sha256");
CREATE INDEX "documents_storage_disk_path_index" ON "documents"(
  "storage_disk",
  "storage_path"
);
CREATE INDEX "documents_robaws_dedup_idx" ON "documents"(
  "robaws_quotation_id",
  "robaws_last_upload_sha"
);
CREATE INDEX "documents_processing_status_index" ON "documents"(
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
CREATE INDEX "intake_files_intake_id_mime_type_index" on "intake_files"(
  "intake_id",
  "mime_type"
);
CREATE INDEX "intake_files_storage_disk_index" on "intake_files"(
  "storage_disk"
);
CREATE INDEX "intake_files_created_at_index" on "intake_files"("created_at");
CREATE INDEX intake_files_intake_id_mime_type_idx ON intake_files(
  intake_id,
  mime_type
);
CREATE INDEX intake_files_storage_disk_idx ON intake_files(storage_disk);
CREATE TABLE IF NOT EXISTS "intakes"(
  "id" integer primary key autoincrement not null,
  "status" varchar not null default('uploaded'),
  "source" varchar,
  "notes" text,
  "priority" varchar not null default('normal'),
  "created_at" datetime,
  "updated_at" datetime,
  "robaws_offer_id" varchar,
  "robaws_offer_number" varchar,
  "contact_email" varchar,
  "contact_phone" varchar,
  "last_export_error" text,
  "last_export_error_at" datetime,
  "customer_name" varchar,
  "extraction_data" text,
  "robaws_client_id" varchar,
  "robaws_exported_at" datetime,
  "robaws_export_status" varchar,
  "flags" text,
  "aggregated_extraction_data" text,
  "is_multi_document" tinyint(1) not null default '0',
  "total_documents" integer not null default '1',
  "processed_documents" integer not null default '0',
  "service_type" varchar,
  "customer_role" varchar,
  "export_payload_hash" varchar,
  "export_attempt_count" integer not null default '0'
);
CREATE INDEX "intakes_contact_email_index" on "intakes"("contact_email");
CREATE INDEX "intakes_contact_phone_index" on "intakes"("contact_phone");
CREATE INDEX "intakes_created_at_index" on "intakes"("created_at");
CREATE INDEX "intakes_robaws_client_id_index" on "intakes"("robaws_client_id");
CREATE INDEX "intakes_robaws_offer_id_index" on "intakes"("robaws_offer_id");
CREATE INDEX "intakes_status_index" on "intakes"("status");
CREATE INDEX "intakes_status_priority_index" on "intakes"(
  "status",
  "priority"
);
CREATE INDEX "documents_upload_status_index" on "documents"("upload_status");
CREATE INDEX "documents_robaws_client_id_index" on "documents"(
  "robaws_client_id"
);
CREATE INDEX "extractions_document_id_index" on "extractions"("document_id");
CREATE INDEX "extractions_status_index" on "extractions"("status");
CREATE INDEX "extractions_service_used_index" on "extractions"("service_used");
CREATE INDEX "extractions_analysis_type_index" on "extractions"(
  "analysis_type"
);
CREATE INDEX "extractions_verified_at_index" on "extractions"("verified_at");
CREATE INDEX "extractions_created_at_index" on "extractions"("created_at");
CREATE INDEX "extractions_updated_at_index" on "extractions"("updated_at");
CREATE INDEX "quotations_document_id_index" on "quotations"("document_id");
CREATE INDEX "quotations_quotation_number_index" on "quotations"(
  "quotation_number"
);
CREATE INDEX "quotations_client_name_index" on "quotations"("client_name");
CREATE INDEX "quotations_client_email_index" on "quotations"("client_email");
CREATE INDEX "quotations_origin_port_index" on "quotations"("origin_port");
CREATE INDEX "quotations_destination_port_index" on "quotations"(
  "destination_port"
);
CREATE INDEX "quotations_cargo_type_index" on "quotations"("cargo_type");
CREATE INDEX "quotations_valid_until_index" on "quotations"("valid_until");
CREATE INDEX "quotations_sent_at_index" on "quotations"("sent_at");
CREATE INDEX "quotations_accepted_at_index" on "quotations"("accepted_at");
CREATE INDEX "quotations_rejected_at_index" on "quotations"("rejected_at");
CREATE INDEX "intake_files_mime_type_index" on "intake_files"("mime_type");
CREATE INDEX "intake_files_filename_index" on "intake_files"("filename");
CREATE INDEX "intake_files_updated_at_index" on "intake_files"("updated_at");
CREATE TABLE IF NOT EXISTS "shipping_carriers"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "code" varchar not null,
  "website_url" varchar,
  "api_endpoint" varchar,
  "specialization" text,
  "service_types" text,
  "service_level" varchar check("service_level" in('Premium', 'Standard', 'Regional')) not null default 'Standard',
  "is_active" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "shipping_carriers_code_unique" on "shipping_carriers"(
  "code"
);
CREATE TABLE IF NOT EXISTS "ports"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "code" varchar not null,
  "country" varchar,
  "region" varchar,
  "is_active" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime,
  "type" varchar not null default 'both',
  "shipping_codes" text,
  "is_european_origin" tinyint(1) not null default '0',
  "is_african_destination" tinyint(1) not null default '0',
  "port_type" varchar not null default 'both'
);
CREATE UNIQUE INDEX "ports_code_unique" on "ports"("code");
CREATE TABLE IF NOT EXISTS "shipping_schedules"(
  "id" integer primary key autoincrement not null,
  "carrier_id" integer not null,
  "pol_id" integer not null,
  "pod_id" integer not null,
  "service_name" varchar,
  "frequency_per_week" numeric,
  "frequency_per_month" numeric,
  "transit_days" integer,
  "vessel_name" varchar,
  "vessel_class" varchar,
  "ets_pol" date,
  "eta_pod" date,
  "next_sailing_date" date,
  "last_updated" datetime not null default CURRENT_TIMESTAMP,
  "is_active" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime,
  "voyage_number" varchar,
  foreign key("carrier_id") references "shipping_carriers"("id"),
  foreign key("pol_id") references "ports"("id"),
  foreign key("pod_id") references "ports"("id")
);
CREATE TABLE IF NOT EXISTS "schedule_updates_log"(
  "id" integer primary key autoincrement not null,
  "carrier_code" varchar not null,
  "pol_code" varchar not null,
  "pod_code" varchar not null,
  "schedules_found" integer not null default '0',
  "schedules_updated" integer not null default '0',
  "schedules_created" integer not null default '0',
  "error_message" text,
  "status" varchar check("status" in('success', 'partial', 'failed')) not null default 'success',
  "started_at" datetime not null,
  "completed_at" datetime,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE TABLE IF NOT EXISTS "schedule_sync_logs"(
  "id" integer primary key autoincrement not null,
  "sync_type" varchar not null default 'manual',
  "schedules_updated" integer not null default '0',
  "carriers_processed" integer not null default '0',
  "status" text not null default 'success',
  "error_message" text,
  "details" text,
  "started_at" datetime not null,
  "completed_at" datetime,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "schedule_sync_logs_sync_type_started_at_index" on "schedule_sync_logs"(
  "sync_type",
  "started_at"
);
CREATE UNIQUE INDEX "shipping_schedules_unique_voyage" on "shipping_schedules"(
  "carrier_id",
  "pol_id",
  "pod_id",
  "service_name",
  "vessel_name",
  "ets_pol"
);
CREATE TABLE IF NOT EXISTS "offer_templates"(
  "id" integer primary key autoincrement not null,
  "template_code" varchar not null,
  "template_name" varchar not null,
  "template_type" varchar check("template_type" in('intro', 'end', 'slot')) not null,
  "service_type" varchar not null,
  "customer_type" varchar,
  "content" text not null,
  "available_variables" text,
  "sort_order" integer not null default '0',
  "is_active" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "offer_templates_service_type_template_type_is_active_index" on "offer_templates"(
  "service_type",
  "template_type",
  "is_active"
);
CREATE INDEX "offer_templates_template_code_index" on "offer_templates"(
  "template_code"
);
CREATE UNIQUE INDEX "offer_templates_template_code_unique" on "offer_templates"(
  "template_code"
);
CREATE TABLE IF NOT EXISTS "quotation_requests"(
  "id" integer primary key autoincrement not null,
  "request_number" varchar not null,
  "source" varchar check("source" in('customer', 'prospect', 'intake')) not null,
  "requester_type" varchar not null,
  "contact_email" varchar not null,
  "contact_name" varchar,
  "contact_company" varchar,
  "contact_phone" varchar,
  "service_type" varchar not null,
  "trade_direction" varchar not null,
  "routing" text not null,
  "cargo_details" text not null,
  "cargo_description" text,
  "special_requirements" text,
  "selected_schedule_id" integer,
  "preferred_carrier" varchar,
  "preferred_departure_date" date,
  "robaws_offer_id" varchar,
  "robaws_offer_number" varchar,
  "robaws_sync_status" varchar check("robaws_sync_status" in('pending', 'synced', 'failed')) not null default 'pending',
  "robaws_synced_at" datetime,
  "intake_id" integer,
  "status" varchar check("status" in('pending', 'processing', 'quoted', 'accepted', 'rejected', 'expired')) not null default 'pending',
  "quoted_at" datetime,
  "expires_at" datetime,
  "customer_role" varchar,
  "customer_type" varchar,
  "subtotal" numeric,
  "discount_amount" numeric not null default '0',
  "discount_percentage" numeric not null default '0',
  "total_excl_vat" numeric,
  "vat_amount" numeric,
  "vat_rate" numeric not null default '21',
  "total_incl_vat" numeric,
  "pricing_currency" varchar not null default 'EUR',
  "intro_template_id" integer,
  "end_template_id" integer,
  "intro_text" text,
  "end_text" text,
  "template_variables" text,
  "assigned_to" integer,
  "created_by" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "customer_reference" varchar,
  "por" varchar,
  "pol" varchar,
  "pod" varchar,
  "fdest" varchar,
  "client_name" varchar,
  "client_email" varchar,
  "client_tel" varchar,
  "robaws_client_id" integer,
  "contact_function" varchar,
  "total_commodity_items" integer not null default '0',
  "robaws_cargo_field" text,
  "robaws_dim_field" text,
  "commodity_type" varchar,
  "simple_service_type" varchar,
  foreign key("selected_schedule_id") references "shipping_schedules"("id") on delete SET NULL,
  foreign key("intake_id") references "intakes"("id") on delete SET NULL,
  foreign key("intro_template_id") references "offer_templates"("id"),
  foreign key("end_template_id") references "offer_templates"("id"),
  foreign key("assigned_to") references "users"("id"),
  foreign key("created_by") references "users"("id")
);
CREATE INDEX "quotation_requests_status_source_index" on "quotation_requests"(
  "status",
  "source"
);
CREATE INDEX "quotation_requests_robaws_offer_id_index" on "quotation_requests"(
  "robaws_offer_id"
);
CREATE INDEX "quotation_requests_requester_email_index" on "quotation_requests"(
  "contact_email"
);
CREATE UNIQUE INDEX "quotation_requests_request_number_unique" on "quotation_requests"(
  "request_number"
);
CREATE TABLE IF NOT EXISTS "quotation_request_files"(
  "id" integer primary key autoincrement not null,
  "quotation_request_id" integer not null,
  "filename" varchar not null,
  "original_filename" varchar not null,
  "file_path" varchar not null,
  "mime_type" varchar not null,
  "file_size" integer not null,
  "file_type" varchar check("file_type" in('cargo_info', 'specification', 'packing_list', 'photo', 'other')) not null,
  "description" text,
  "uploaded_by" integer,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("quotation_request_id") references "quotation_requests"("id") on delete cascade,
  foreign key("uploaded_by") references "users"("id")
);
CREATE INDEX "quotation_request_files_quotation_request_id_index" on "quotation_request_files"(
  "quotation_request_id"
);
CREATE TABLE IF NOT EXISTS "schedule_offer_links"(
  "id" integer primary key autoincrement not null,
  "shipping_schedule_id" integer not null,
  "robaws_offer_id" varchar not null,
  "selected_articles" text not null,
  "linked_by" integer not null,
  "linked_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("shipping_schedule_id") references "shipping_schedules"("id") on delete cascade,
  foreign key("linked_by") references "users"("id")
);
CREATE INDEX "schedule_offer_links_shipping_schedule_id_robaws_offer_id_index" on "schedule_offer_links"(
  "shipping_schedule_id",
  "robaws_offer_id"
);
CREATE TABLE IF NOT EXISTS "robaws_sync_logs"(
  "id" integer primary key autoincrement not null,
  "sync_type" varchar not null,
  "items_synced" integer not null default '0',
  "started_at" datetime not null,
  "completed_at" datetime,
  "error_message" text,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "robaws_sync_logs_sync_type_started_at_index" on "robaws_sync_logs"(
  "sync_type",
  "started_at"
);
CREATE TABLE IF NOT EXISTS "article_children"(
  "id" integer primary key autoincrement not null,
  "parent_article_id" integer not null,
  "child_article_id" integer not null,
  "sort_order" integer not null default '0',
  "is_required" tinyint(1) not null default '1',
  "is_conditional" tinyint(1) not null default '0',
  "conditions" text,
  "created_at" datetime,
  "updated_at" datetime,
  "cost_type" varchar,
  "default_quantity" numeric not null default '1',
  "default_cost_price" numeric,
  "unit_type" varchar,
  foreign key("parent_article_id") references "robaws_articles_cache"("id") on delete cascade,
  foreign key("child_article_id") references "robaws_articles_cache"("id") on delete cascade
);
CREATE UNIQUE INDEX "article_children_parent_article_id_child_article_id_unique" on "article_children"(
  "parent_article_id",
  "child_article_id"
);
CREATE INDEX "article_children_parent_article_id_index" on "article_children"(
  "parent_article_id"
);
CREATE TABLE IF NOT EXISTS "quotation_request_articles"(
  "id" integer primary key autoincrement not null,
  "quotation_request_id" integer not null,
  "article_cache_id" integer not null,
  "parent_article_id" integer,
  "item_type" varchar check("item_type" in('parent', 'child', 'standalone')) not null default 'standalone',
  "quantity" integer not null default '1',
  "unit_price" numeric not null,
  "selling_price" numeric not null,
  "subtotal" numeric not null,
  "currency" varchar not null default 'EUR',
  "formula_inputs" text,
  "calculated_price" numeric,
  "notes" text,
  "created_at" datetime,
  "updated_at" datetime,
  "unit_type" varchar not null default 'unit',
  foreign key("quotation_request_id") references "quotation_requests"("id") on delete cascade,
  foreign key("article_cache_id") references "robaws_articles_cache"("id") on delete cascade,
  foreign key("parent_article_id") references "robaws_articles_cache"("id") on delete cascade
);
CREATE INDEX "quotation_request_articles_quotation_request_id_article_cache_id_index" on "quotation_request_articles"(
  "quotation_request_id",
  "article_cache_id"
);
CREATE INDEX "quotation_request_articles_quotation_request_id_parent_article_id_index" on "quotation_request_articles"(
  "quotation_request_id",
  "parent_article_id"
);
CREATE TABLE IF NOT EXISTS "quotation_commodity_items"(
  "id" integer primary key autoincrement not null,
  "quotation_request_id" integer not null,
  "line_number" integer not null,
  "commodity_type" varchar check("commodity_type" in('vehicles', 'machinery', 'boat', 'general_cargo')) not null,
  "category" varchar,
  "make" varchar,
  "type_model" varchar,
  "fuel_type" varchar,
  "condition" varchar,
  "wheelbase_cm" numeric,
  "quantity" integer not null default '1',
  "length_cm" numeric,
  "width_cm" numeric,
  "height_cm" numeric,
  "cbm" numeric,
  "weight_kg" numeric,
  "bruto_weight_kg" numeric,
  "netto_weight_kg" numeric,
  "has_parts" tinyint(1) not null default '0',
  "parts_description" text,
  "has_trailer" tinyint(1) not null default '0',
  "has_wooden_cradle" tinyint(1) not null default '0',
  "has_iron_cradle" tinyint(1) not null default '0',
  "is_forkliftable" tinyint(1) not null default '0',
  "is_hazardous" tinyint(1) not null default '0',
  "is_unpacked" tinyint(1) not null default '0',
  "is_ispm15" tinyint(1) not null default '0',
  "unit_price" numeric,
  "line_total" numeric,
  "extra_info" text,
  "attachments" text,
  "input_unit_system" varchar not null default 'metric',
  "created_at" datetime,
  "updated_at" datetime,
  "year" integer,
  foreign key("quotation_request_id") references "quotation_requests"("id") on delete cascade
);
CREATE INDEX "quotation_commodity_items_quotation_request_id_line_number_index" on "quotation_commodity_items"(
  "quotation_request_id",
  "line_number"
);
CREATE INDEX "intakes_service_type_index" on "intakes"("service_type");
CREATE TABLE IF NOT EXISTS "webhook_configurations"(
  "id" integer primary key autoincrement not null,
  "provider" varchar not null default 'robaws',
  "webhook_id" varchar,
  "secret" text not null,
  "url" varchar not null,
  "events" text not null,
  "is_active" tinyint(1) not null default '1',
  "registered_at" datetime,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "webhook_configurations_provider_is_active_index" on "webhook_configurations"(
  "provider",
  "is_active"
);
CREATE TABLE IF NOT EXISTS "robaws_webhook_logs"(
  "id" integer primary key autoincrement not null,
  "event_type" varchar not null,
  "robaws_id" varchar not null,
  "payload" text not null,
  "status" varchar not null default('received'),
  "error_message" text,
  "processed_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "retry_count" integer not null default '0',
  "processing_duration_ms" integer,
  "article_id" integer,
  foreign key("article_id") references "robaws_articles_cache"("id") on delete set null
);
CREATE INDEX "robaws_webhook_logs_event_type_robaws_id_index" on "robaws_webhook_logs"(
  "event_type",
  "robaws_id"
);
CREATE INDEX "robaws_webhook_logs_status_index" on "robaws_webhook_logs"(
  "status"
);
CREATE INDEX "robaws_webhook_logs_article_id_index" on "robaws_webhook_logs"(
  "article_id"
);
CREATE TABLE IF NOT EXISTS "robaws_articles_cache"(
  "id" integer primary key autoincrement not null,
  "robaws_article_id" varchar not null,
  "article_code" varchar,
  "article_name" varchar not null,
  "description" text,
  "category" varchar not null,
  "applicable_services" text,
  "applicable_carriers" text,
  "applicable_routes" text,
  "customer_type" varchar,
  "min_quantity" integer not null default('1'),
  "max_quantity" integer not null default('1'),
  "tier_label" varchar,
  "unit_price" numeric,
  "currency" varchar not null default('EUR'),
  "unit_type" varchar,
  "pricing_formula" text,
  "profit_margins" text,
  "is_parent_article" tinyint(1) not null default('0'),
  "is_surcharge" tinyint(1) not null default('0'),
  "is_active" tinyint(1) not null default('1'),
  "requires_manual_review" tinyint(1) not null default('0'),
  "last_synced_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  "shipping_line" varchar,
  "service_type" varchar,
  "pol_terminal" varchar,
  "is_parent_item" tinyint(1),
  "article_info" text,
  "update_date" date,
  "validity_date" date,
  "pol" varchar,
  "pod" varchar,
  "last_modified_at" datetime,
  "sales_name" text,
  "brand" varchar,
  "barcode" varchar,
  "article_number" varchar,
  "sale_price" numeric,
  "cost_price" numeric,
  "sale_price_strategy" varchar,
  "cost_price_strategy" varchar,
  "margin" numeric,
  "weight_kg" numeric,
  "vat_tariff_id" varchar,
  "stock_article" tinyint(1) not null default('0'),
  "time_operation" tinyint(1) not null default('0'),
  "installation" tinyint(1) not null default('0'),
  "wappy" tinyint(1) not null default('0'),
  "image_id" varchar,
  "composite_items" text,
  "commodity_type" varchar,
  "pod_code" varchar,
  "transport_mode" varchar,
  "article_type" varchar,
  "cost_side" varchar,
  "pol_code" varchar,
  "is_mandatory" tinyint(1) not null default '0',
  "mandatory_condition" varchar,
  "notes" text
);
CREATE INDEX "article_filter_idx" on "robaws_articles_cache"(
  "shipping_line",
  "service_type",
  "pol_terminal"
);
CREATE INDEX "robaws_articles_cache_article_code_index" on "robaws_articles_cache"(
  "article_code"
);
CREATE INDEX "robaws_articles_cache_article_number_index" on "robaws_articles_cache"(
  "article_number"
);
CREATE INDEX "robaws_articles_cache_brand_index" on "robaws_articles_cache"(
  "brand"
);
CREATE INDEX "robaws_articles_cache_category_is_active_index" on "robaws_articles_cache"(
  "category",
  "is_active"
);
CREATE INDEX "robaws_articles_cache_customer_type_is_active_index" on "robaws_articles_cache"(
  "customer_type",
  "is_active"
);
CREATE INDEX "robaws_articles_cache_is_parent_article_index" on "robaws_articles_cache"(
  "is_parent_article"
);
CREATE INDEX "robaws_articles_cache_is_parent_item_index" on "robaws_articles_cache"(
  "is_parent_item"
);
CREATE INDEX "robaws_articles_cache_last_modified_at_index" on "robaws_articles_cache"(
  "last_modified_at"
);
CREATE INDEX "robaws_articles_cache_pod_name_index" on "robaws_articles_cache"(
  "pod"
);
CREATE INDEX "robaws_articles_cache_pol_code_index" on "robaws_articles_cache"(
  "pol"
);
CREATE INDEX "robaws_articles_cache_pol_terminal_index" on "robaws_articles_cache"(
  "pol_terminal"
);
CREATE UNIQUE INDEX "robaws_articles_cache_robaws_article_id_unique" on "robaws_articles_cache"(
  "robaws_article_id"
);
CREATE INDEX "robaws_articles_cache_service_type_index" on "robaws_articles_cache"(
  "service_type"
);
CREATE INDEX "robaws_articles_cache_shipping_line_index" on "robaws_articles_cache"(
  "shipping_line"
);
CREATE INDEX "robaws_articles_cache_stock_article_index" on "robaws_articles_cache"(
  "stock_article"
);
CREATE TABLE IF NOT EXISTS "robaws_customers_cache"(
  "id" integer primary key autoincrement not null,
  "robaws_client_id" varchar not null,
  "name" varchar not null,
  "role" varchar,
  "email" varchar,
  "phone" varchar,
  "mobile" varchar,
  "address" text,
  "street" varchar,
  "street_number" varchar,
  "city" varchar,
  "postal_code" varchar,
  "country" varchar,
  "country_code" varchar,
  "vat_number" varchar,
  "website" varchar,
  "language" varchar,
  "currency" varchar not null default 'EUR',
  "client_type" varchar,
  "is_active" tinyint(1) not null default '1',
  "metadata" text,
  "last_synced_at" datetime,
  "last_pushed_to_robaws_at" datetime,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "robaws_customers_cache_role_is_active_index" on "robaws_customers_cache"(
  "role",
  "is_active"
);
CREATE INDEX "robaws_customers_cache_last_synced_at_index" on "robaws_customers_cache"(
  "last_synced_at"
);
CREATE INDEX "robaws_customers_cache_name_email_index" on "robaws_customers_cache"(
  "name",
  "email"
);
CREATE UNIQUE INDEX "robaws_customers_cache_robaws_client_id_unique" on "robaws_customers_cache"(
  "robaws_client_id"
);
CREATE INDEX "robaws_customers_cache_name_index" on "robaws_customers_cache"(
  "name"
);
CREATE INDEX "robaws_customers_cache_role_index" on "robaws_customers_cache"(
  "role"
);
CREATE INDEX "robaws_customers_cache_email_index" on "robaws_customers_cache"(
  "email"
);
CREATE INDEX "robaws_customers_cache_city_index" on "robaws_customers_cache"(
  "city"
);
CREATE INDEX "robaws_customers_cache_country_index" on "robaws_customers_cache"(
  "country"
);
CREATE INDEX "robaws_customers_cache_vat_number_index" on "robaws_customers_cache"(
  "vat_number"
);
CREATE INDEX "idx_articles_commodity" on "robaws_articles_cache"(
  "commodity_type"
);
CREATE INDEX "idx_articles_pol_pod" on "robaws_articles_cache"(
  "pol",
  "pod_code"
);
CREATE INDEX "idx_articles_parent_match" on "robaws_articles_cache"(
  "is_parent_item",
  "shipping_line",
  "service_type",
  "pol",
  "pod_code",
  "commodity_type"
);
CREATE INDEX "idx_articles_transport_mode" on "robaws_articles_cache"(
  "transport_mode"
);
CREATE INDEX "idx_articles_mode_pol_pod" on "robaws_articles_cache"(
  "transport_mode",
  "pol_code",
  "pod_code"
);

INSERT INTO migrations VALUES(1,'0001_01_01_000000_create_users_table',1);
INSERT INTO migrations VALUES(2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO migrations VALUES(3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO migrations VALUES(4,'2024_12_28_120000_add_export_tracking_fields_to_intakes_table',1);
INSERT INTO migrations VALUES(5,'2025_01_27_140000_create_pricing_tiers_table',1);
INSERT INTO migrations VALUES(6,'2025_01_27_140001_add_pricing_tier_to_quotation_requests',1);
INSERT INTO migrations VALUES(7,'2025_08_15_193325_create_vin_wmis_table',1);
INSERT INTO migrations VALUES(8,'2025_08_15_193326_create_vehicle_specs_table',1);
INSERT INTO migrations VALUES(9,'2025_08_15_205308_create_intakes_table',1);
INSERT INTO migrations VALUES(10,'2025_08_15_205321_create_documents_table',1);
INSERT INTO migrations VALUES(11,'2025_08_15_205334_create_extractions_table',1);
INSERT INTO migrations VALUES(12,'2025_08_23_044116_add_missing_columns_to_extractions_table',1);
INSERT INTO migrations VALUES(13,'2025_08_25_202527_add_robaws_fields_to_documents_table',1);
INSERT INTO migrations VALUES(14,'2025_08_25_202548_create_quotations_table',1);
INSERT INTO migrations VALUES(15,'2025_08_27_061324_add_analysis_type_to_extractions_table',1);
INSERT INTO migrations VALUES(16,'2025_08_27_201146_add_robaws_sync_fields_to_documents_table',1);
INSERT INTO migrations VALUES(17,'2025_08_30_083846_add_file_upload_tracking_to_documents_table',1);
INSERT INTO migrations VALUES(18,'2025_08_30_090802_make_intake_id_nullable_in_documents_table',1);
INSERT INTO migrations VALUES(19,'2025_08_30_090811_make_intake_id_nullable_in_documents_table',1);
INSERT INTO migrations VALUES(20,'2025_08_30_094835_add_storage_disk_to_documents_table',1);
INSERT INTO migrations VALUES(21,'2025_08_30_095128_make_intake_id_nullable_in_extractions_table',1);
INSERT INTO migrations VALUES(22,'2025_08_30_100621_add_robaws_quotation_id_to_extractions_table',1);
INSERT INTO migrations VALUES(23,'2025_08_30_114333_add_sync_tracking_to_documents_and_extractions_table',1);
INSERT INTO migrations VALUES(24,'2025_08_30_130127_fix_extractions_raw_json_nullable',1);
INSERT INTO migrations VALUES(25,'2025_08_31_105059_add_email_dedupe_columns_to_documents',1);
INSERT INTO migrations VALUES(26,'2025_08_31_110453_add_storage_and_robaws_columns_to_documents_table',1);
INSERT INTO migrations VALUES(27,'2025_08_31_114908_create_robaws_documents_table',1);
INSERT INTO migrations VALUES(28,'2025_08_31_115458_add_unique_constraint_to_robaws_documents_table',1);
INSERT INTO migrations VALUES(29,'2025_08_31_120658_add_robaws_fields_to_intakes_table',1);
INSERT INTO migrations VALUES(30,'2025_08_31_194203_add_status_to_documents_table',1);
INSERT INTO migrations VALUES(31,'2025_09_02_142420_create_intake_files_table',1);
INSERT INTO migrations VALUES(32,'2025_09_02_163632_add_contact_and_export_error_to_intakes_table',1);
INSERT INTO migrations VALUES(33,'2025_09_02_165313_add_customer_name_to_intakes_table',1);
INSERT INTO migrations VALUES(34,'2025_09_02_165647_add_performance_indexes_to_intake_tables',1);
INSERT INTO migrations VALUES(35,'2025_09_02_200000_safe_indexes_on_intakes_and_intake_files',1);
INSERT INTO migrations VALUES(36,'2025_09_02_223640_add_robaws_client_id_to_intakes_table',1);
INSERT INTO migrations VALUES(37,'2025_09_06_135648_make_intake_contact_fields_nullable',1);
INSERT INTO migrations VALUES(38,'2025_09_07_200809_fix_intake_file_paths',1);
INSERT INTO migrations VALUES(39,'2025_09_08_211654_fix_export_attempt_count',1);
INSERT INTO migrations VALUES(40,'2025_09_08_215528_add_robaws_exported_at_to_intakes_table',1);
INSERT INTO migrations VALUES(41,'2025_09_09_114559_add_image_extraction_meta_to_documents',1);
INSERT INTO migrations VALUES(42,'2025_09_09_114754_add_flags_to_intakes_table',1);
INSERT INTO migrations VALUES(43,'2025_09_21_164728_fix_upload_status_constraint',1);
INSERT INTO migrations VALUES(44,'2025_09_21_173444_add_robaws_client_id_to_documents_table',1);
INSERT INTO migrations VALUES(45,'2025_09_22_190454_add_critical_performance_indexes',1);
INSERT INTO migrations VALUES(46,'2025_10_06_175822_create_shipping_carriers_table',1);
INSERT INTO migrations VALUES(47,'2025_10_06_175825_create_ports_table',1);
INSERT INTO migrations VALUES(48,'2025_10_06_175828_create_shipping_schedules_table',1);
INSERT INTO migrations VALUES(49,'2025_10_06_175832_create_schedule_updates_log_table',1);
INSERT INTO migrations VALUES(50,'2025_10_06_215143_create_schedule_sync_logs_table',1);
INSERT INTO migrations VALUES(51,'2025_10_07_173940_add_port_type_to_ports_table',1);
INSERT INTO migrations VALUES(52,'2025_10_07_185746_update_shipping_schedules_unique_constraint',1);
INSERT INTO migrations VALUES(53,'2025_10_07_193258_add_voyage_number_to_shipping_schedules_table',1);
INSERT INTO migrations VALUES(54,'2025_10_08_193900_fix_shipping_schedules_unique_constraint_for_multiple_voyages',1);
INSERT INTO migrations VALUES(55,'2025_10_11_072407_create_quotation_system_tables',1);
INSERT INTO migrations VALUES(56,'2025_10_11_175227_add_customer_reference_to_quotation_requests_table',1);
INSERT INTO migrations VALUES(57,'2025_10_11_180128_add_individual_route_fields_to_quotation_requests_table',1);
INSERT INTO migrations VALUES(58,'2025_10_11_223245_align_quotation_fields_with_robaws',1);
INSERT INTO migrations VALUES(59,'2025_10_12_201317_enhance_ports_table',1);
INSERT INTO migrations VALUES(60,'2025_10_14_203312_create_quotation_commodity_items_table',1);
INSERT INTO migrations VALUES(61,'2025_10_14_204341_add_commodity_fields_to_quotation_requests_table',1);
INSERT INTO migrations VALUES(62,'2025_10_15_063449_add_commodity_type_to_quotation_requests_table',1);
INSERT INTO migrations VALUES(63,'2025_10_15_190000_add_multi_document_support_to_intakes_table',1);
INSERT INTO migrations VALUES(64,'2025_10_15_213451_add_service_type_to_intakes_table',1);
INSERT INTO migrations VALUES(65,'2025_10_16_194539_add_article_metadata_columns_to_robaws_articles_cache_table',1);
INSERT INTO migrations VALUES(66,'2025_10_16_194555_enhance_article_children_pivot_table',1);
INSERT INTO migrations VALUES(67,'2025_10_16_210419_make_is_parent_item_nullable_in_robaws_articles_cache_table',1);
INSERT INTO migrations VALUES(68,'2025_10_16_220825_add_pol_pod_to_robaws_articles_cache_table',1);
INSERT INTO migrations VALUES(69,'2025_10_17_000155_add_last_modified_to_robaws_articles_cache_table',1);
INSERT INTO migrations VALUES(70,'2025_10_18_093132_create_webhook_configurations_table',1);
INSERT INTO migrations VALUES(71,'2025_10_20_000001_enhance_webhook_logs_schema',1);
INSERT INTO migrations VALUES(72,'2025_10_21_081518_add_standard_robaws_fields_to_articles_cache',1);
INSERT INTO migrations VALUES(73,'2025_10_21_082140_change_sales_name_to_text_in_robaws_articles_cache',1);
INSERT INTO migrations VALUES(74,'2025_10_21_182733_create_robaws_customers_cache_table',1);
INSERT INTO migrations VALUES(75,'2025_10_22_150233_add_customer_role_to_intakes_table',1);
INSERT INTO migrations VALUES(76,'2025_10_23_123123_add_simple_service_type_to_quotation_requests',1);
INSERT INTO migrations VALUES(77,'2025_10_23_212100_add_commodity_fields_to_robaws_articles_cache',1);
INSERT INTO migrations VALUES(78,'2025_10_28_100000_remove_redundant_article_fields',1);
INSERT INTO migrations VALUES(79,'2025_10_28_100001_rename_pol_pod_fields',1);
INSERT INTO migrations VALUES(80,'2025_10_29_070000_add_draft_status_to_quotation_requests',1);
INSERT INTO migrations VALUES(81,'2025_11_02_184403_normalize_article_pol_pod_format',1);
INSERT INTO migrations VALUES(82,'2025_11_03_185153_add_year_to_quotation_commodity_items_table',1);
INSERT INTO migrations VALUES(83,'2025_11_09_183928_add_unit_type_to_quotation_request_articles_table',1);
INSERT INTO migrations VALUES(84,'2025_11_09_215640_add_transport_mode_fields_to_robaws_articles_cache_table',1);
INSERT INTO migrations VALUES(85,'2025_12_28_120000_add_export_tracking_fields_to_intakes_table',1);
