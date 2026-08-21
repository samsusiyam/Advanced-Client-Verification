<?php

use Illuminate\Database\Capsule\Manager as Capsule;

function migration_005_create_group_rules_table()
{
    if (Capsule::schema()->hasTable('mod_cv_group_rules')) {
        return;
    }

    Capsule::schema()->create('mod_cv_group_rules', function ($table) {
        $table->increments('id');
        $table->unsignedInteger('group_id');
        $table->enum('requirement', ['required', 'optional', 'not_required'])->default('required');
        $table->timestamps();

        $table->index('group_id');
    });
}
