<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supervisor_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('internship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supervisor_id')->constrained('users')->cascadeOnDelete();
            $table->text('content');
            $table->timestamps();
            $table->index(['internship_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supervisor_observations');
    }
};
