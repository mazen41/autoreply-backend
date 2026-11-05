<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Visitor Properties (Tenants, Internal, Customer Stores)
        if (!Schema::hasTable('visitor_properties')) {
            Schema::create('visitor_properties', function (Blueprint $table) {
                $table->id();
                $table->string('property_uid', 64)->unique();
                $table->unsignedBigInteger('business_id')->nullable()->index();
                $table->string('name', 190);
                $table->string('domain', 190);
                $table->json('allowed_domains')->nullable();
                $table->string('type', 32)->default('customer_store'); // 'internal', 'customer_store', 'custom_site'
                $table->string('platform', 32)->default('custom'); // 'nextjs', 'shopify', 'salla', 'woocommerce', 'custom'
                $table->json('settings')->nullable(); // anonymize_ip, respect_dnt, cookieless
                $table->boolean('is_active')->default(true);
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
            });
        }

        // 2. Visitor Identities (Pseudonymous tracking & Identity linking to registered Users)
        if (!Schema::hasTable('visitor_identities')) {
            Schema::create('visitor_identities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('property_id')->constrained('visitor_properties')->cascadeOnDelete();
                $table->string('anonymous_id', 96)->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->json('traits')->nullable();
                $table->timestamps();

                $table->unique(['property_id', 'anonymous_id'], 'prop_anon_unique');
            });
        }

        // 3. Visitor Sessions
        if (!Schema::hasTable('visitor_sessions')) {
            Schema::create('visitor_sessions', function (Blueprint $table) {
                $table->id();
                $table->string('session_id', 96)->index();
                $table->foreignId('property_id')->constrained('visitor_properties')->cascadeOnDelete();
                $table->unsignedBigInteger('visitor_identity_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('ip_hash', 64)->nullable()->index();
                $table->string('country', 100)->nullable();
                $table->string('country_code', 8)->nullable();
                $table->string('city', 100)->nullable();
                $table->string('device_type', 32)->default('desktop'); // desktop, mobile, tablet
                $table->string('browser', 64)->nullable();
                $table->string('os', 64)->nullable();
                $table->text('referrer')->nullable();
                $table->string('referrer_domain', 190)->nullable()->index();
                $table->text('entry_url')->nullable();
                $table->text('exit_url')->nullable();
                $table->string('utm_source', 100)->nullable()->index();
                $table->string('utm_medium', 100)->nullable()->index();
                $table->string('utm_campaign', 100)->nullable()->index();
                $table->string('utm_term', 100)->nullable();
                $table->string('utm_content', 100)->nullable();
                $table->unsignedInteger('pageviews_count')->default(0);
                $table->unsignedInteger('events_count')->default(0);
                $table->unsignedInteger('duration_seconds')->default(0);
                $table->boolean('is_bounce')->default(true);
                $table->timestamp('started_at')->useCurrent()->index();
                $table->timestamp('ended_at')->nullable();
                $table->timestamp('last_activity_at')->useCurrent()->index();
                $table->timestamps();
            });
        }

        // 4. Visitor Events (Pageviews, Custom, Ecommerce)
        if (!Schema::hasTable('visitor_events')) {
            Schema::create('visitor_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('property_id')->constrained('visitor_properties')->cascadeOnDelete();
                $table->string('session_id', 96)->index();
                $table->unsignedBigInteger('visitor_identity_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('event_type', 32)->default('pageview')->index(); // pageview, custom, conversion, ecommerce
                $table->string('event_name', 100)->index();
                $table->string('category', 64)->default('general');
                $table->text('url')->nullable();
                $table->string('path', 190)->index();
                $table->string('page_title', 255)->nullable();
                $table->json('properties')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }

        // 5. Visitor Conversions (Signups, Subscriptions, Sales)
        if (!Schema::hasTable('visitor_conversions')) {
            Schema::create('visitor_conversions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('property_id')->constrained('visitor_properties')->cascadeOnDelete();
                $table->string('session_id', 96)->index();
                $table->unsignedBigInteger('visitor_identity_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();
                $table->string('goal_name', 100)->index();
                $table->decimal('revenue', 12, 2)->default(0);
                $table->string('currency', 10)->default('SAR');
                $table->string('order_id', 100)->nullable()->index();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_conversions');
        Schema::dropIfExists('visitor_events');
        Schema::dropIfExists('visitor_sessions');
        Schema::dropIfExists('visitor_identities');
        Schema::dropIfExists('visitor_properties');
    }
};
