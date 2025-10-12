<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Quotation Requests - Main table for all quotation requests
        Schema::create('quotation_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number')->unique(); // QR-2025-0001
            $table->enum('source', ['customer', 'prospect', 'intake']);
            $table->string('requester_type'); // customer, prospect, team
            $table->string('requester_email');
            $table->string('requester_name')->nullable();
            $table->string('requester_company')->nullable();
            $table->string('requester_phone')->nullable();
            
            // Service & Route
            $table->string('service_type'); // FCL_CONSOL_EXPORT, RORO_EXPORT, etc.
            $table->string('trade_direction'); // EXPORT, IMPORT, CROSS_TRADE
            $table->json('routing'); // POR, POL, POD, FDEST
            
            // Cargo Information
            $table->json('cargo_details');
            $table->text('cargo_description')->nullable();
            $table->text('special_requirements')->nullable();
            
            // Schedule Selection (read-only reference)
            $table->foreignId('selected_schedule_id')->nullable()
                ->constrained('shipping_schedules')->onDelete('SET NULL');
            $table->string('preferred_carrier')->nullable();
            $table->date('preferred_departure_date')->nullable();
            
            // Robaws Integration
            $table->string('robaws_offer_id')->nullable();
            $table->string('robaws_offer_number')->nullable();
            $table->enum('robaws_sync_status', ['pending', 'synced', 'failed'])->default('pending');
            $table->timestamp('robaws_synced_at')->nullable();
            
            // Intake Link (read-only reference)
            $table->foreignId('intake_id')->nullable()
                ->constrained('intakes')->onDelete('SET NULL');
            
            // Status
            $table->enum('status', ['pending', 'processing', 'quoted', 'accepted', 'rejected', 'expired'])
                ->default('pending');
            $table->timestamp('quoted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            
            // Pricing & Customer Info
            $table->string('customer_role')->nullable(); // RORO, POV, CONSIGNEE, FORWARDER, etc.
            $table->string('customer_type')->nullable(); // FORWARDERS, GENERAL, CIB, PRIVATE
            $table->decimal('subtotal', 10, 2)->nullable(); // Sum of all article subtotals
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('discount_percentage', 5, 2)->default(0);
            $table->decimal('total_excl_vat', 10, 2)->nullable(); // subtotal - discount
            $table->decimal('vat_amount', 10, 2)->nullable();
            $table->decimal('vat_rate', 5, 2)->default(21.00); // Belgium VAT rate
            $table->decimal('total_incl_vat', 10, 2)->nullable();
            $table->string('pricing_currency', 3)->default('EUR');
            
            // Template Integration
            $table->foreignId('intro_template_id')->nullable()->constrained('offer_templates');
            $table->foreignId('end_template_id')->nullable()->constrained('offer_templates');
            $table->text('intro_text')->nullable(); // Rendered intro with variables replaced
            $table->text('end_text')->nullable(); // Rendered end with variables replaced
            $table->json('template_variables')->nullable(); // Values for template variables
            
            $table->foreignId('assigned_to')->nullable()->constrained('users');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['status', 'source']);
            $table->index('robaws_offer_id');
            $table->index('requester_email');
        });

        // Quotation Request Files
        Schema::create('quotation_request_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_request_id')->constrained()->onDelete('cascade');
            $table->string('filename');
            $table->string('original_filename');
            $table->string('file_path');
            $table->string('mime_type');
            $table->integer('file_size');
            $table->enum('file_type', ['cargo_info', 'specification', 'packing_list', 'photo', 'other']);
            $table->text('description')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users');
            $table->timestamps();
            
            $table->index('quotation_request_id');
        });

        // Robaws Articles Cache
        Schema::create('robaws_articles_cache', function (Blueprint $table) {
            $table->id();
            $table->string('robaws_article_id')->unique();
            $table->string('article_code')->nullable(); // BWFCLIMP, BWA-FCL, etc.
            $table->string('article_name');
            $table->text('description')->nullable();
            $table->string('category'); // seafreight, precarriage, customs, warehouse, etc.
            
            // Service type mapping
            $table->json('applicable_services')->nullable(); // RORO_IMPORT, FCL_EXPORT, etc.
            $table->json('applicable_carriers')->nullable();
            $table->json('applicable_routes')->nullable();
            
            // Customer type filtering
            $table->string('customer_type')->nullable(); // FORWARDERS, GENERAL, CIB, PRIVATE
            
            // Quantity tier pricing
            $table->integer('min_quantity')->default(1);
            $table->integer('max_quantity')->default(1);
            $table->string('tier_label')->nullable(); // "2 pack", "3 pack", etc.
            
            // Pricing
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->string('unit_type')->nullable(); // LM, unit, shipment, lumpsum, car, container
            
            // Formula-based pricing (for CONSOL services)
            $table->json('pricing_formula')->nullable(); // {type: 'formula', divisor: 2, fixed_amount: 800}
            
            // Profit margins per role
            $table->json('profit_margins')->nullable();
            
            // Parent-child indicator
            $table->boolean('is_parent_article')->default(false);
            $table->boolean('is_surcharge')->default(false);
            
            // Admin flags
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_manual_review')->default(false);
            $table->timestamp('last_synced_at');
            $table->timestamps();
            
            $table->index(['category', 'is_active']);
            $table->index('article_code');
            $table->index(['customer_type', 'is_active']);
            $table->index('is_parent_article');
        });

        // Schedule-Offer Links
        Schema::create('schedule_offer_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_schedule_id')->constrained()->onDelete('cascade');
            $table->string('robaws_offer_id');
            $table->json('selected_articles');
            $table->foreignId('linked_by')->constrained('users');
            $table->timestamp('linked_at');
            $table->timestamps();
            
            $table->index(['shipping_schedule_id', 'robaws_offer_id']);
        });

        // Robaws Webhook Logs
        Schema::create('robaws_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_type');
            $table->string('robaws_id');
            $table->json('payload');
            $table->enum('status', ['received', 'processing', 'processed', 'failed'])->default('received');
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            
            $table->index(['event_type', 'robaws_id']);
            $table->index('status');
        });

        // Robaws Sync Logs
        Schema::create('robaws_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('sync_type'); // articles, offers, projects
            $table->integer('items_synced')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            
            $table->index(['sync_type', 'started_at']);
        });

        // Article Children (Parent-Child Relationships)
        Schema::create('article_children', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_article_id')->constrained('robaws_articles_cache')->onDelete('cascade');
            $table->foreignId('child_article_id')->constrained('robaws_articles_cache')->onDelete('cascade');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_required')->default(true); // Auto-include when parent selected
            $table->boolean('is_conditional')->default(false); // Optional based on conditions
            $table->json('conditions')->nullable(); // When to include (e.g., only for certain routes)
            $table->timestamps();
            
            $table->unique(['parent_article_id', 'child_article_id']);
            $table->index('parent_article_id');
        });

        // Quotation Request Articles (Pivot table with pricing)
        Schema::create('quotation_request_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_request_id')->constrained()->onDelete('cascade');
            $table->foreignId('article_cache_id')->constrained('robaws_articles_cache')->onDelete('cascade');
            $table->foreignId('parent_article_id')->nullable()->constrained('robaws_articles_cache')->onDelete('cascade');
            $table->enum('item_type', ['parent', 'child', 'standalone'])->default('standalone');
            
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 10, 2); // Base price at time of selection
            $table->decimal('selling_price', 10, 2); // With profit margin
            $table->decimal('subtotal', 10, 2); // quantity * selling_price
            $table->string('currency', 3)->default('EUR');
            
            // Formula calculation storage (for CONSOL)
            $table->json('formula_inputs')->nullable(); // {ocean_freight: 1600, divisor: 2, fixed: 800}
            $table->decimal('calculated_price', 10, 2)->nullable(); // Result of formula
            
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index(['quotation_request_id', 'article_cache_id']);
            $table->index(['quotation_request_id', 'parent_article_id']);
        });

        // Offer Templates (Intro/End Standard Texts)
        Schema::create('offer_templates', function (Blueprint $table) {
            $table->id();
            $table->string('template_code')->unique(); // FCL_EXP_INTRO_PICKUP, RORO_IMP_INTRO_ENG
            $table->string('template_name'); // "FCL EXP - intro - with pick up"
            $table->enum('template_type', ['intro', 'end', 'slot']); // intro, end, or slot (middle section)
            $table->string('service_type'); // RORO_IMPORT, FCL_EXPORT, etc.
            $table->string('customer_type')->nullable(); // FORWARDERS, POV, GENERAL
            $table->text('content'); // Template with ${variables}
            $table->json('available_variables')->nullable(); // List of variables this template uses
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index(['service_type', 'template_type', 'is_active']);
            $table->index('template_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offer_templates');
        Schema::dropIfExists('quotation_request_articles');
        Schema::dropIfExists('article_children');
        Schema::dropIfExists('robaws_sync_logs');
        Schema::dropIfExists('robaws_webhook_logs');
        Schema::dropIfExists('schedule_offer_links');
        Schema::dropIfExists('robaws_articles_cache');
        Schema::dropIfExists('quotation_request_files');
        Schema::dropIfExists('quotation_requests');
    }
};
