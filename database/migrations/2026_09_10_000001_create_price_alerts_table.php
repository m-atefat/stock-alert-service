<?php

use App\Enums\AlertStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 16);
            $table->decimal('target_price', 18, 8);
            $table->tinyInteger('direction');
            $table->tinyInteger('status')->default(AlertStatus::Active->value);
            $table->decimal('triggered_price', 18, 8)->nullable();
            $table->timestamp('triggered_at')->nullable();
            $table->timestamps();
        });

        $active = AlertStatus::Active->value;

        DB::statement(<<<SQL
            CREATE UNIQUE INDEX price_alerts_active_unique
            ON price_alerts (user_id, symbol, target_price, direction)
            WHERE status = {$active}
        SQL);

        DB::statement(<<<SQL
            CREATE INDEX price_alerts_active_lookup
            ON price_alerts (symbol, direction, target_price)
            WHERE status = {$active}
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('price_alerts');
    }
};
