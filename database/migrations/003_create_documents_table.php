<?php

use Illuminate\Database\Capsule\Manager as Capsule;

function migration_003_create_documents_table()
{
    if (Capsule::schema()->hasTable('mod_cv_documents')) {
        return;
    }

    Capsule::schema()->create('mod_cv_documents', function ($table) {
        $table->increments('id');
        $table->unsignedInteger('verification_id');
        $table->string('document_type', 50);
        $table->string('side', 20)->nullable();
        $table->string('original_filename', 255);
        $table->string('stored_filename', 255);
        $table->text('storage_path');
        $table->string('mime_type', 100);
        $table->unsignedBigInteger('file_size');
        $table->string('sha256_hash', 64);
        $table->boolean('encrypted')->default(0);
        $table->dateTime('expires_at')->nullable();
        $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
        $table->text('rejection_reason')->nullable();
        $table->timestamp('uploaded_at')->nullable();
        $table->timestamps();

        $table->index('verification_id');
    });
}
