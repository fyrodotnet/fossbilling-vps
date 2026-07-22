<?php

/**
 * FOSSBilling.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license   Apache-2.0
 *
 * Copyright FOSSBilling 2022
 * This software may contain code previously used in the BoxBilling project.
 * Copyright BoxBilling, Inc 2011-2021
 *
 * This source file is subject to the Apache-2.0 License that is bundled
 * with this source code in the file LICENSE
 */

namespace Box\Mod\Vps\Api;

use FOSSBilling\Api\AbstractApi;

class Admin extends AbstractApi
{
    public function getService(): \Box\Mod\Vps\Service
    {
        return $this->di['mod_service']('Vps');
    }

    public function list_vms($data = []): array
    {
        return $this->getService()->getAdminVmList();
    }

    public function verify_connection($data = []): array
    {
        return $this->getService()->verifyConnection();
    }

    public function auth_preview($data = []): array
    {
        return $this->getService()->getRootAuthPreview();
    }

    public function save_settings($data): bool
    {
        return $this->getService()->saveSecureSettings($data);
    }

    public function billing_report($data = []): array
    {
        $runLimit = isset($data['run_limit']) ? (int) $data['run_limit'] : 10;

        return $this->getService()->getBillingReport($runLimit);
    }

    public function run_billing_now($data = []): array
    {
        return $this->getService()->runBillingCycleNow();
    }
}
