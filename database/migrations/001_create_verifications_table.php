<?php

use Illuminate\Database\Capsule\Manager as Capsule;

function migration_001_create_verifications_table()
{
    if (!Capsule::schema()->hasTable('mod_cv_verifications')) {
        Capsule::schema()->create('mod_cv_verifications', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('client_id');
            $table->string('verification_method', 50);
            $table->string('status', 50)->default('pending');
            $table->string('didit_session_id', 255)->nullable();
            $table->string('didit_vendor_data', 255)->nullable();
            $table->string('didit_status', 50)->nullable();
            $table->string('didit_decision', 50)->nullable();
            $table->string('vendor_data', 255)->nullable();
            $table->string('client_ref', 50)->nullable();
            $table->decimal('risk_score', 5, 2)->default(0);
            $table->string('risk_level', 50)->default('low');
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
    } else {
        if (!Capsule::schema()->hasColumn('mod_cv_verifications', 'didit_status')) {
            Capsule::schema()->table('mod_cv_verifications', function ($table) {
                $table->string('didit_status', 50)->nullable();
            });
        }
        if (!Capsule::schema()->hasColumn('mod_cv_verifications', 'didit_decision')) {
            Capsule::schema()->table('mod_cv_verifications', function ($table) {
                $table->string('didit_decision', 50)->nullable();
            });
        }
        if (!Capsule::schema()->hasColumn('mod_cv_verifications', 'document_number')) {
            Capsule::schema()->table('mod_cv_verifications', function ($table) {
                $table->string('document_number', 100)->nullable()->after('client_ref');
            });
        }
        if (!Capsule::schema()->hasColumn('mod_cv_verifications', 'rejection_reason')) {
            Capsule::schema()->table('mod_cv_verifications', function ($table) {
                $table->text('rejection_reason')->nullable();
            });
        }
        if (!Capsule::schema()->hasColumn('mod_cv_verifications', 'info_request_note')) {
            Capsule::schema()->table('mod_cv_verifications', function ($table) {
                $table->text('info_request_note')->nullable();
            });
        }
    }
}
