<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // 1. حذف حقل الكود نهائياً
            if (Schema::hasColumn('organizations', 'code')) {
                $table->dropColumn('code');
            }

            // 2. جعل plan_id يقبل قيمة فارغة (null)
            // ملاحظة: نستخدم change() لتعديل حقل موجود مسبقاً
            $table->unsignedBigInteger('plan_id')->nullable()->change();

            // 3. جعل حالة الاشتراك تقبل null وإزالة القيمة الافتراضية 'trial'
            $table->string('subscription_status')->nullable()->default(null)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // كود التراجع (في حال أردت التراجع عن هذا التعديل مستقبلاً)
            $table->string('code')->unique()->nullable();
            $table->unsignedBigInteger('plan_id')->nullable(false)->change();
            $table->string('subscription_status')->default('trial')->change();
        });
    }
};
