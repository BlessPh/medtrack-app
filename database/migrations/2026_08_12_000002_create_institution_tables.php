<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institutions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('type', 30)->index();
            $table->string('name');
            $table->string('short_name', 100)->nullable();
            $table->string('registration_number', 100)->nullable()->unique();
            $table->string('status', 20)->default('PENDING')->index();
            $table->text('description')->nullable();
            $table->string('website')->nullable();
            $table->string('logo_url', 500)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('institution_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('institution_units')->nullOnDelete();
            $table->string('type', 30);
            $table->string('code', 50)->nullable();
            $table->string('name', 200);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();
            $table->unique(['institution_id', 'code']);
            $table->index('parent_id');
        });

        Schema::create('institution_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->string('label', 100)->nullable();
            $table->text('address_line');
            $table->string('city', 100);
            $table->string('province', 100)->nullable();
            $table->string('country', 100)->default('CD');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('institution_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('value');
            $table->string('label', 100)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['institution_id', 'type', 'value']);
        });

        Schema::create('institution_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('institution_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['institution_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_memberships');
        Schema::dropIfExists('institution_contacts');
        Schema::dropIfExists('institution_addresses');
        Schema::dropIfExists('institution_units');
        Schema::dropIfExists('institutions');
    }
};
