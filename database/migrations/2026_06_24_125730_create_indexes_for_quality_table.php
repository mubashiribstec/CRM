<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Whether an index already exists on a table. Lets this migration be
     * idempotent — some deployments (e.g. those seeded from the SQL dump)
     * already carry one or more of these indexes, and a blind ADD INDEX
     * would fail with "1061 Duplicate key name".
     */
    private function indexExists(string $table, string $index): bool
    {
        $rows = DB::select(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1',
            [$table, $index]
        );

        return count($rows) > 0;
    }

    private function addIndex(string $table, array $columns, string $name): void
    {
        if ($this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($columns, $name) {
            $t->index($columns, $name);
        });
    }

    private function dropIndexIfExists(string $table, string $name): void
    {
        if (!$this->indexExists($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($name) {
            $t->dropIndex($name);
        });
    }

    public function up(): void
    {
        // applicants — WHERE status = 1 (filters the entire base query)
        $this->addIndex('applicants', ['status', 'id'], 'idx_applicants_status_id');

        // quality_notes — ROW_NUMBER() PARTITION BY applicant_id, sale_id
        $this->addIndex('quality_notes', ['moved_tab_to', 'applicant_id', 'sale_id', 'id'], 'idx_qn_tab_applicant_sale_id');

        // cv_notes — ROW_NUMBER() PARTITION BY applicant_id, sale_id
        $this->addIndex('cv_notes', ['status', 'applicant_id', 'sale_id', 'id'], 'idx_cn_status_applicant_sale_id');
        $this->addIndex('cv_notes', ['applicant_id', 'sale_id', 'id'], 'idx_cn_applicant_sale_id');

        // history — ROW_NUMBER() PARTITION BY applicant_id, sale_id (sub_stage + status)
        $this->addIndex('history', ['sub_stage', 'status', 'applicant_id', 'sale_id', 'id'], 'idx_history_substage_status_applicant_sale');

        // revert_stages — ROW_NUMBER() PARTITION BY applicant_id, sale_id (stage)
        $this->addIndex('revert_stages', ['stage', 'applicant_id', 'sale_id', 'id'], 'idx_revert_stage_applicant_sale_id');

        // sales — joined on sales.id, office_id, unit_id
        $this->addIndex('sales', ['office_id', 'unit_id'], 'idx_sales_office_unit');
    }

    public function down(): void
    {
        $this->dropIndexIfExists('applicants', 'idx_applicants_status_id');
        $this->dropIndexIfExists('quality_notes', 'idx_qn_tab_applicant_sale_id');
        $this->dropIndexIfExists('cv_notes', 'idx_cn_status_applicant_sale_id');
        $this->dropIndexIfExists('cv_notes', 'idx_cn_applicant_sale_id');
        $this->dropIndexIfExists('history', 'idx_history_substage_status_applicant_sale');
        $this->dropIndexIfExists('revert_stages', 'idx_revert_stage_applicant_sale_id');
        $this->dropIndexIfExists('sales', 'idx_sales_office_unit');
    }
};
