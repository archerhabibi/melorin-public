<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'name' => $this->faker->words(3, true),
            'main_price' => $this->faker->randomFloat(2, 50000, 500000),
            'traffic_gb' => $this->faker->randomElement([10, 30, 50, 100]),
            'duration_days' => $this->faker->randomElement([30, 60, 90]),
            'status' => 'active',
        ];
    }
}
