<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\ProvisioningAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProvisioningAttemptFactory extends Factory
{
    protected $model = ProvisioningAttempt::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'operation_id' => null,
            'attempt_number' => 1,
            'status' => ProvisioningAttempt::STATUS_STARTED,
        ];
    }
}
