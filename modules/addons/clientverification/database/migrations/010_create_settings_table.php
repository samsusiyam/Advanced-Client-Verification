<?php

use Illuminate\Database\Capsule\Manager as Capsule;

function migration_010_create_settings_table()
{
    if (Capsule::schema()->hasTable('mod_cv_settings')) {
        return;
    }

    Capsule::schema()->create('mod_cv_settings', function ($table) {
        $table->increments('id');
        $table->string('setting_key', 100)->unique();
        $table->text('setting_value')->nullable();
        $table->timestamps();
    });
}
