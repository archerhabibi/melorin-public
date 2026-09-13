<?php

namespace Database\Factories;

use App\Models\Reseller;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ResellerFactory extends Factory
{
    protected $model = Reseller::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'bot_token' => $this->faker->uuid(),
            'slug' => $this->faker->unique()->slug(2),
            'status' => 'active',
            'min_sale_price_rule' => null,
        ];
    }
}
