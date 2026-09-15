<?php

namespace App\Filament\Resources\ResellerResource\Pages;

use App\Filament\Resources\ResellerResource;
use App\Services\Resellers\ResellerService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Hash;

class EditReseller extends EditRecord
{
    protected static string $resource = ResellerResource::class;

    /** توکن پیش از ذخیره — برای تشخیص اینکه آیا وب‌هوک باید دوباره ثبت شود */
    protected ?string $tokenBeforeSave = null;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['email'] = $this->record->user?->email;

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->tokenBeforeSave = $this->record->bot_token;

        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;
        unset($data['email'], $data['password']);

        if ($email || $password) {
            $this->record->user?->update(array_filter([
                'email' => $email,
                'password' => $password ? Hash::make($password) : null,
            ]));
        }

        return $data;
    }

    /**
     * تغییر توکن ربات بدون ثبت مجدد وب‌هوک، ربات نماینده را کاملاً از
     * کار می‌انداخت (توکن جدید هیچ وب‌هوکی نداشت) — و چون هیچ خطایی
     * نمایش داده نمی‌شد، تشخیصش سخت بود. حالا خودکار انجام می‌شود.
     */
    protected function afterSave(): void
    {
        $result = app(ResellerService::class)
            ->syncWebhookIfTokenChanged($this->record->refresh(), $this->tokenBeforeSave);

        if ($this->tokenBeforeSave === $this->record->bot_token) {
            return;
        }

        $result['success']
            ? Notification::make()->title('توکن تغییر کرد و وب‌هوک دوباره ثبت شد.')->success()->send()
            : Notification::make()->title('توکن تغییر کرد ولی ثبت وب‌هوک ناموفق بود: '.$result['description'])->danger()->send();
    }
}
