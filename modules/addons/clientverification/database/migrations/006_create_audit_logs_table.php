<?php

use Illuminate\Database\Capsule\Manager as Capsule;

function migration_006_create_audit_logs_table()
{
    if (Capsule::schema()->hasTable('mod_cv_audit_logs')) {
        return;
    }

    Capsule::schema()->create('mod_cv_audit_logs', function ($table) {
        $table->increments('id');
        $table->unsignedInteger('verification_id')->nullable();
        $table->unsignedInteger('admin_id')->default(0);
        $table->string('action', 100);
        $table->text('note')->nullable();
        $table->string('ip', 45)->nullable();
        $table->timestamp('created_at')->nullable();
        $table->index('verification_id');
        $table->index('created_at');
    });
}
