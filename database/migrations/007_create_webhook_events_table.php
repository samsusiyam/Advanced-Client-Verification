<?php

use Illuminate\Database\Capsule\Manager as Capsule;

function migration_007_create_webhook_events_table()
{
    if (Capsule::schema()->hasTable('mod_cv_webhook_events')) {
        return;
    }

    Capsule::schema()->create('mod_cv_webhook_events', function ($table) {
        $table->increments('id');
        $table->string('event_id', 255)->nullable();
        $table->string('session_id', 255)->nullable();
        $table->string('source', 50)->default('didit');
        $table->text('payload')->nullable();
        $table->string('signature', 255)->nullable();
        $table->boolean('processed')->default(0);
        $table->string('result', 50)->nullable();
        $table->timestamp('received_at')->nullable();
        $table->index('event_id');
        $table->index('session_id');
        $table->index('received_at');
    });
}
