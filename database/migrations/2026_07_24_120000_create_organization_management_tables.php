<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('organization_user')
            ->where('role', 'member')
            ->update(['role' => 'analyst']);

        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('pending_email', 254)->nullable();
            $table->string('role', 32);
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('accepted_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'pending_email']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['email', 'created_at']);
        });

        Schema::create('organization_audit_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('event', 64);
            $table->foreignId('subject_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignUlid('subject_invitation_id')
                ->nullable()
                ->constrained('organization_invitations')
                ->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_audit_events');
        Schema::dropIfExists('organization_invitations');

        DB::table('organization_user')
            ->where('role', '!=', 'owner')
            ->update(['role' => 'member']);
    }
};
