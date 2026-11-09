<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // DMARC aggregate reports other mail servers send about a hosted
        // domain (rua=mailto:dmarc-reports@<domain>), one row per report.
        Schema::create('dmarc_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_domain_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('org_name');
            $table->string('org_email')->nullable();
            $table->string('report_id');
            $table->dateTime('begins_at');
            $table->dateTime('ends_at')->index();
            $table->string('policy', 16)->nullable();
            $table->unsignedInteger('messages')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->timestamps();
            $table->unique(['mail_domain_id', 'org_name', 'report_id']);
        });
        // One sending source in a report: how many messages, and how they fared.
        Schema::create('dmarc_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dmarc_report_id')->constrained()->cascadeOnDelete();
            $table->string('source_ip', 45)->index();
            $table->unsignedInteger('count');
            $table->string('disposition', 16);
            $table->string('dkim', 8);
            $table->string('spf', 8);
            $table->string('header_from');
            $table->string('dkim_domain')->nullable();
            $table->string('spf_domain')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dmarc_records');
        Schema::dropIfExists('dmarc_reports');
    }
};
