<?php

namespace App\Channels\Website\Services;

use App\Models\User;
use App\Services\Core\Customer\ProfileCenterException;
use App\Services\Core\Customer\ProfileCenterService;
use App\Services\Core\Customer\ProfileOverview;
use App\Services\Core\Store\StoreContext;

/**
 * Adapter نازک روی ProfileCenterService (B3.5). هیچ منطق و قاعده‌ای اینجا نیست؛ فقط صدا زدن Core.
 */
class WebsiteProfileFacade
{
    public function __construct(protected ProfileCenterService $center) {}

    public function overview(User $user, StoreContext $store): ProfileOverview
    {
        return $this->center->overview($user, $store);
    }

    /**
     * @param  array{full_name?: mixed, phone?: mixed}  $input
     * @return list<string> فیلدهای تغییرکرده
     *
     * @throws ProfileCenterException
     */
    public function update(User $user, array $input): array
    {
        return $this->center->update($user, $input);
    }
}
