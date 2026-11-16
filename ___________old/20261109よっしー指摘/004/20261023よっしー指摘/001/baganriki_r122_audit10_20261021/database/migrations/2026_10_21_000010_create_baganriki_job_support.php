<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('baganriki_r122_jobs', function(Blueprint $t) {
            $t->bigIncrements('id'); $t->string('queue')->index(); $t->longText('payload');
            $t->unsignedTinyInteger('attempts'); $t->unsignedInteger('reserved_at')->nullable();
            $t->unsignedInteger('available_at'); $t->unsignedInteger('created_at');
        });
        Schema::create('baganriki_r122_job_runs', function(Blueprint $t) {
            $t->string('race_id',80)->primary(); $t->dateTime('created_at',6);
        });
        Schema::create('baganriki_r122_role_states', function(Blueprint $t) {
            $t->unsignedBigInteger('snapshot_id'); $t->string('role',8);
            $t->boolean('settled')->default(false); $t->char('fingerprint',64)->nullable();
            $t->dateTime('updated_at',6); $t->primary(['snapshot_id','role']);
        });
        Schema::create('baganriki_r122_leases', function(Blueprint $t) {
            $t->char('lease_key',64)->primary(); $t->char('owner',32);
            $t->unsignedBigInteger('until_epoch')->index();
        });
        Schema::create('baganriki_r122_call_metrics', function(Blueprint $t) {
            $t->unsignedBigInteger('attempt_id')->primary();
            $t->string('actual_model',160)->nullable(); $t->string('response_id',200)->nullable();
            $t->string('request_id',200)->nullable(); $t->longText('usage_json');
            $t->string('stop_reason',100)->nullable(); $t->unsignedInteger('elapsed_ms');
            $t->dateTime('recorded_at',6);
        });
    }
    public function down(): void
    {
        // Explicitly opt out of destructive automatic rollback of send ownership.
        throw new \RuntimeException('Archive active run ownership and stop workers before a separately reviewed rollback.');
    }
};

