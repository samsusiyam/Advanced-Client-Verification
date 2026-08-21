<?php

use Illuminate\Database\Capsule\Manager as Capsule;

function migration_002_create_personal_data_table()
{
    if (!Capsule::schema()->hasTable('mod_cv_personal_data')) {
        Capsule::schema()->create('mod_cv_personal_data', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('verification_id');
            $table->string('document_number', 100)->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('phone', 50)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 30)->nullable();
            $table->string('country', 2)->nullable();
            $table->timestamps();

            $table->index('verification_id');
        });
    } else {
        if (!Capsule::schema()->hasColumn('mod_cv_personal_data', 'document_number')) {
            Capsule::schema()->table('mod_cv_personal_data', function ($table) {
                $table->string('document_number', 100)->nullable()->after('verification_id');
            });
        }
    }
}
