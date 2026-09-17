<?php

namespace Database\Factories;

use App\Models\CustomerAccount;
use App\Models\Reseller;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerAccount>
 */
class CustomerAccountFactory extends Factory
{
    protected $model = CustomerAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'store_type' => 'main',
            'reseller_id' => null,
            'status' => 'active',
        ];
    }

    public function forReseller(?Reseller $reseller = null): static
    {
        return $this->state(fn () => [
            'store_type' => 'reseller',
            'reseller_id' => $reseller?->id ?? Reseller::factory(),
        ]);
    }

    /** مهمان: عضویت بدون Identity (بند ۳۵ بلوپرینت) */
    public function guest(): static
    {
        return $this->state(fn () => ['user_id' => null]);
    }

    public function blocked(): static
    {
        return $this->state(fn () => ['status' => 'blocked']);
    }
}
