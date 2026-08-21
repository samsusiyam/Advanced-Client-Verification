<?php

use Illuminate\Database\Capsule\Manager as Capsule;

function migration_009_create_api_tokens_table()
{
    if (Capsule::schema()->hasTable('mod_cv_api_tokens')) {
        return;
    }

    Capsule::schema()->create('mod_cv_api_tokens', function ($table) {
        $table->increments('id');
        $table->string('name', 100);
        $table->string('token_hash', 64); // sha256 of token
        $table->text('scopes'); // json array
        $table->boolean('active')->default(0);
        $table->dateTime('expires_at')->nullable();
        $table->unsignedInteger('rate_limit')->default(60); // per minute
        $table->unsignedInteger('request_count')->default(0);
        $table->dateTime('last_used_at')->nullable();
        $table->timestamps();
        $table->index('token_hash');
    });
}
