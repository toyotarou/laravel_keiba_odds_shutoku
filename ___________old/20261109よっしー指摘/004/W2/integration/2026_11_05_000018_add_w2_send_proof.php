<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddW2SendProof extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('baganriki_ai_attempts', 'w2_send_proof')) {
            Schema::table('baganriki_ai_attempts', function (Blueprint $table) {
                $table->char('w2_send_proof', 65)->nullable();
            });
        }
    }

    public function down()
    {
        // Rolls back application code independently. Existing send authorizations remain available for audit.
    }
}
