<?php

use Illuminate\Database\Capsule\Manager as Capsule;

function migration_004_create_product_rules_table()
{
    if (Capsule::schema()->hasTable('mod_cv_product_rules')) {
        return;
    }

    Capsule::schema()->create('mod_cv_product_rules', function ($table) {
        $table->increments('id');
        $table->unsignedInteger('product_id');
        $table->enum('requirement', ['required', 'optional', 'not_required'])->default('required');
        $table->timestamps();

        $table->index('product_id');
    });
}
