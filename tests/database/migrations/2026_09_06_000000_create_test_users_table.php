<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->default('Test User');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->timestamp('last_login_at')->nullable();
                $table->timestamp('marketing_emails_opted_out_at')->nullable();
                $table->boolean('is_admin')->default(false);
                $table->string('password')->default('$2y$12$e8g3m90F1/t...');
                $table->rememberToken();
                $table->timestamps();
            });
        } else {
            Schema::table('users', function (Blueprint $table): void {
                if (! Schema::hasColumn('users', 'last_login_at')) {
                    $table->timestamp('last_login_at')->nullable();
                }
                if (! Schema::hasColumn('users', 'marketing_emails_opted_out_at')) {
                    $table->timestamp('marketing_emails_opted_out_at')->nullable();
                }
                if (! Schema::hasColumn('users', 'is_admin')) {
                    $table->boolean('is_admin')->default(false);
                }
            });
        }

        if (! Schema::hasTable('properties')) {
            Schema::create('properties', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id');
                $table->string('title')->default('Property 1');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
