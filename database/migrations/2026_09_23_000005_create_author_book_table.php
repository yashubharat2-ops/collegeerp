<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Library Management (Phase 1) — book ⟷ author link.
 *
 * A book may have several authors and an author may have several books, so
 * the reference is a pivot rather than a single `author_id`. `sort_order`
 * preserves the order in which the authors are credited on the title page.
 *
 * `college_id` is carried on the pivot (same convention as
 * `admission_document_type_program`) so the link itself is tenant-attributable
 * even though it is only ever reached through a tenant-scoped book.
 *
 * Additive only: no existing table is modified here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('author_book', function (Blueprint $table) {
            $table->id();
            $table->foreignId('college_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('authors')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['book_id', 'author_id'], 'author_book_unique');
            $table->index(['college_id', 'author_id'], 'author_book_college_author_idx');
            $table->index(['college_id', 'book_id'], 'author_book_college_book_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('author_book');
    }
};
