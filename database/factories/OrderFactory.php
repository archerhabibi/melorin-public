<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'product_id' => Product::factory(),
            'sales_channel' => 'main_bot',
            'main_price' => 100000,
            'reseller_price' => null,
            'customers_price' => null,
            'status' => 'pending',
        ];
    }
}
