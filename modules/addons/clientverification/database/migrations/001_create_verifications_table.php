<?php

use Illuminate\Database\Capsule\Manager as Capsule;

function migration_001_create_verifications_table()
{
    if (Capsule::schema()->hasTable('mod_cv_verifications')) {
        return;
    }

    Capsule::schema()->create('mod_cv_verifications', function ($table) {
        $table->increments('id');
        $table->unsignedInteger('client_id');
        $table->string('verification_method', 50);
        $table->enum('status', ['pending', 'in_progress', 'approved', 'rejected', 'under_review'])->default('pending');
        $table->string('didit_session_id', 255)->nullable();
        $table->string('didit_vendor_data', 255)->nullable();
        $table->string('vendor_data', 255)->nullable();
        $table->string('client_ref', 50)->nullable();
        $table->decimal('risk_score', 5, 2)->default(0);
        $table->enum('risk_level', ['low', 'medium', 'high'])->default('low');
        $table->boolean('manual_review_required')->default(0);
        $table->unsignedInteger('assigned_admin_id')->nullable();
        $table->dateTime('submitted_at')->nullable();
        $table->dateTime('reviewed_at')->nullable();
        $table->string('reviewed_by', 100)->nullable();
        $table->dateTime('expires_at')->nullable();
        $table->text('audit_log')->nullable();
        $table->timestamps();

        $table->index('client_id');
        $table->index('verification_method');
        $table->index('status');
        $table->index('submitted_at');
    });
}
