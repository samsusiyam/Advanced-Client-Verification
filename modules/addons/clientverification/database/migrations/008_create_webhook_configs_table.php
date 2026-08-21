<?php

use Illuminate\Database\Capsule\Manager as Capsule;

function migration_008_create_webhook_configs_table()
{
    if (Capsule::schema()->hasTable('mod_cv_webhook_configs')) {
        return;
    }

    Capsule::schema()->create('mod_cv_webhook_configs', function ($table) {
        $table->increments('id');
        $table->string('event_type', 50);
        $table->string('url', 500);
        $table->text('secret');
        $table->boolean('active')->default(1);
        $table->unsignedInteger('failure_count')->default(0);
        $table->timestamp('last_attempt_at')->nullable();
        $table->timestamps();
        $table->index('event_type');
    });
}
