<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->select(['id', 'name'])
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                DB::transaction(function () use ($users): void {
                    $organizationIds = DB::table('organizations')
                        ->whereIn('personal_user_id', $users->pluck('id'))
                        ->pluck('id', 'personal_user_id');

                    foreach ($users as $user) {
                        $organizationId = $organizationIds->get($user->id);
                        $now = now();

                        if ($organizationId === null) {
                            $organizationId = (string) Str::ulid();

                            DB::table('organizations')->insert([
                                'id' => $organizationId,
                                'name' => $user->name,
                                'type' => 'personal',
                                'personal_user_id' => $user->id,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        }

                        $membership = DB::table('organization_user')
                            ->where('organization_id', $organizationId)
                            ->where('user_id', $user->id);

                        if ($membership->exists()) {
                            $membership->update([
                                'role' => 'owner',
                                'updated_at' => $now,
                            ]);
                        } else {
                            DB::table('organization_user')->insert([
                                'id' => (string) Str::ulid(),
                                'organization_id' => $organizationId,
                                'user_id' => $user->id,
                                'role' => 'owner',
                                'joined_at' => $now,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ]);
                        }

                        DB::table('users')
                            ->where('id', $user->id)
                            ->whereNull('current_organization_id')
                            ->update(['current_organization_id' => $organizationId]);
                    }
                });
            });
    }

    public function down(): void
    {
        // Backfilled tenant ownership is intentionally retained on single-step rollbacks.
    }
};
