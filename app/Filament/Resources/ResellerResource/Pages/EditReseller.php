<?php

namespace App\Filament\Resources\ResellerResource\Pages;

use App\Filament\Resources\ResellerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Hash;

class EditReseller extends EditRecord
{
    protected static string $resource = ResellerResource::class;

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
}
