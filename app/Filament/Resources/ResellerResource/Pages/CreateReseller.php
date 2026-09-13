<?php

namespace App\Filament\Resources\ResellerResource\Pages;

use App\Filament\Resources\ResellerResource;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Resellers\ResellerService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * طبق درخواست صریح: یک صفحه که توکن ربات، نام لاتین (آدرس پنل)،
 * ایمیل/رمز را می‌گیرد و همه‌چیز (ساخت Reseller، تنظیم اعتبار ورود،
 * اتصال خودکار وب‌هوک) را یک‌جا انجام می‌دهد. عمداً روی
 * Resource::getEloquentQuery()/handleRecordCreation پیش‌فرض تکیه
 * نمی‌کند چون این عملیات به بیش از یک جدول (users + resellers +
 * reseller_admins + یک تماس واقعی به API تلگرام) دست می‌زند.
 */
class CreateReseller extends CreateRecord
{
    protected static string $resource = ResellerResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $user = User::query()->findOrFail($data['user_id']);
        $user->update([
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        /** @var Reseller $reseller */
        $reseller = app(ResellerService::class)->create($user, [
            'bot_token' => $data['bot_token'],
            'slug' => $data['slug'],
        ]);

        $webhookResult = app(ResellerService::class)->registerWebhook($reseller);

        $webhookResult['success']
            ? Notification::make()->title('نماینده ساخته شد و وب‌هوک با موفقیت وصل شد.')->success()->send()
            : Notification::make()
                ->title('نماینده ساخته شد، ولی اتصال وب‌هوک ناموفق بود.')
                ->body($webhookResult['description'].' — از دکمه‌ی «اتصال مجدد وب‌هوک» در لیست دوباره امتحان کنید.')
                ->warning()
                ->send();

        return $reseller;
    }
}
