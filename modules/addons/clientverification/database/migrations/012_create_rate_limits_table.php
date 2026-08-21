<?php

use Illuminate\Database\Capsule\Manager as Capsule;

function migration_012_create_rate_limits_table()
{
    if (Capsule::schema()->hasTable('mod_cv_rate_limits')) {
        return;
    }

    Capsule::schema()->create('mod_cv_rate_limits', function ($table) {
        $table->increments('id');
        $table->string('bucket', 100);
        $table->string('key', 100);
        $table->unsignedInteger('attempts')->default(0);
        $table->dateTime('window_start');
        $table->timestamps();
        $table->index(['bucket', 'key']);
    });
}
