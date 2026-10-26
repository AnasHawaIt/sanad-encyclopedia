<?php

namespace Tests\Feature\Access;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Audit\ActivityLogger;
use App\Services\Auth\SessionRevoker;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\DatabaseTestCase;

class AuditAndSessionTest extends DatabaseTestCase
{
    public function test_logger_hides_secret_fields_recursively(): void
    {
        $user = User::factory()->create();

        $log = app(ActivityLogger::class)->record('test.event', $user, [
            'name' => 'x',
            'password' => 'p1',
            'nested' => ['api_token' => 't1', 'remember_token' => 'r1', 'ok' => 'fine'],
        ], $user);

        $changes = $log->fresh()->changes;
        $this->assertSame('x', $changes['name']);
        $this->assertSame('[hidden]', $changes['password']);
        $this->assertSame('[hidden]', $changes['nested']['api_token']);
        $this->assertSame('[hidden]', $changes['nested']['remember_token']);
        $this->assertSame('fine', $changes['nested']['ok']);
        $this->assertSame('user', $log->subject_type);
        $this->assertTrue($log->subject->is($user));
    }

    public function test_logger_morph_alias_works_for_roles(): void
    {
        $role = Role::create(['name' => 'r_test', 'guard_name' => 'web']);

        $log = app(ActivityLogger::class)->record('roles.test', $role);

        $this->assertSame('role', $log->subject_type);
        $this->assertSame(1, ActivityLog::where('subject_type', 'role')->count());
    }

    public function test_revoker_deletes_all_tokens_of_the_user_only(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $a->createToken('1');
        $a->createToken('2');
        $b->createToken('1');

        app(SessionRevoker::class)->revoke($a);

        $this->assertSame(0, $a->tokens()->count());
        $this->assertSame(1, $b->tokens()->count());
    }

    public function test_revoker_deletes_database_sessions_except_the_given_one(): void
    {
        config(['session.driver' => 'database']);
        $a = User::factory()->create();
        $b = User::factory()->create();
        foreach ([['s1', $a->id], ['s2', $a->id], ['s3', $b->id]] as [$id, $uid]) {
            DB::table('sessions')->insert([
                'id' => $id, 'user_id' => $uid, 'ip_address' => '127.0.0.1', 'user_agent' => 't', 'payload' => '', 'last_activity' => time(),
            ]);
        }

        app(SessionRevoker::class)->revoke($a, 's1');

        $this->assertEqualsCanonicalizing(['s1', 's3'], DB::table('sessions')->pluck('id')->all());
    }
}
