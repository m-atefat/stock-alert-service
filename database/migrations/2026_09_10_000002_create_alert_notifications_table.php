<?php

use App\Enums\NotificationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The delivery ledger, and the outbox that makes a lost publish recoverable:
 * AlertClaimer writes the row inside the same transaction that claims the
 * alert, before anything is published to RabbitMQ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_notifications', function (Blueprint $table) {
            $table->id();

            // Unique on alert_id alone: an alert fires exactly once in its
            // lifetime, so the alert is a sufficient idempotency key. This is
            // the last guard at the email edge — a duplicate INSERT means
            // "already recorded", and AlertClaimer relies on it for the
            // ON CONFLICT DO NOTHING that makes re-claiming a no-op.
            $table->foreignId('alert_id')->unique()->constrained('price_alerts')->cascadeOnDelete();

            $table->tinyInteger('status')->default(NotificationStatus::Pending->value);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        // Partial index, so it covers only the rows the relay actually scans.
        // Laravel's Blueprint has no partial-index API, hence raw SQL.
        $pending = NotificationStatus::Pending->value;

        DB::statement(<<<SQL
            CREATE INDEX alert_notifications_pending_lookup
            ON alert_notifications (created_at)
            WHERE status = {$pending}
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_notifications');
    }
};
