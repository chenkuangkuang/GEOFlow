<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tasks')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('tasks', 'article_type_mode')) {
                $table->string('article_type_mode', 20)->default('smart_random')->after('knowledge_base_id');
            }
            if (! Schema::hasColumn('tasks', 'article_type_options')) {
                $table->text('article_type_options')->nullable()->after('article_type_mode');
            }
            if (! Schema::hasColumn('tasks', 'writing_style_mode')) {
                $table->string('writing_style_mode', 20)->default('random')->after('article_type_options');
            }
            if (! Schema::hasColumn('tasks', 'writing_style_options')) {
                $table->text('writing_style_options')->nullable()->after('writing_style_mode');
            }
            if (! Schema::hasColumn('tasks', 'length_mode')) {
                $table->string('length_mode', 20)->default('short')->after('writing_style_options');
            }
            if (! Schema::hasColumn('tasks', 'length_min')) {
                $table->integer('length_min')->nullable()->after('length_mode');
            }
            if (! Schema::hasColumn('tasks', 'length_max')) {
                $table->integer('length_max')->nullable()->after('length_min');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tasks')) {
            return;
        }

        Schema::table('tasks', function (Blueprint $table): void {
            foreach (['length_max', 'length_min', 'length_mode', 'writing_style_options', 'writing_style_mode', 'article_type_options', 'article_type_mode'] as $column) {
                if (Schema::hasColumn('tasks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
