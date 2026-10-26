<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('baganriki_r122_leases','job_id')) {
            Schema::table('baganriki_r122_leases',function(Blueprint $t) { $t->unsignedBigInteger('job_id')->nullable(); });
        }
    }
    public function down(): void
    {
        throw new \RuntimeException('Stop and review active queue ownership before removing job deduplication metadata.');
    }
};
