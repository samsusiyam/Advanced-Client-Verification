<?php

use Illuminate\Database\Capsule\Manager as Capsule;

function migration_011_create_document_types_table()
{
    if (Capsule::schema()->hasTable('mod_cv_document_types')) {
        return;
    }

    Capsule::schema()->create('mod_cv_document_types', function ($table) {
        $table->increments('id');
        $table->string('name', 50)->unique();
        $table->string('label', 100);
        $table->unsignedInteger('sides_required')->default(1);
        $table->boolean('is_required')->default(1);
        $table->timestamps();
    });
}
