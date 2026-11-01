<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->foreignId('coupon_id');
            $table->foreignId('product_id')->constrained();
            $table->unsignedBigInteger('warehouse_id');
            $table->string('status')->index();
            $table->timestamps();
            $table->index(['warehouse_id', 'status']);
        });

        DB::statement('ALTER TABLE orders ADD CONSTRAINT chk_status CHECK (status <> \'\')');
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
