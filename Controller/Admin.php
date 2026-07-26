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

/**
 * This file connects FOSSBilling admin area interface and API
 * Class does not extend any other class.
 */

namespace Box\Mod\Vps\Controller;

class Admin implements \FOSSBilling\InjectionAwareInterface
{
    protected $di;

    public function setDi(\Pimple\Container|null $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    /**
     * This method registers menu items in admin area navigation block
     * This navigation is cached in data/cache/{hash}. To see changes please
     * remove the file.
     *
     * @return array
     */
    public function fetchNavigation(): array
    {
        return [
            'subpages' => [
                [
                    'location' => 'extensions',
                    'label' => __trans('VPS'),
                    'index' => 1500,
                    'uri' => $this->di['url']->adminLink('vps'),
                    'class' => '',
                ],
            ],
        ];
    }

    /**
     * Methods maps admin areas urls to corresponding methods
     * Always use your module prefix to avoid conflicts with other modules in future.
     */
    public function register(\Box_App &$app): void
    {
        $app->get('/vps', 'get_index', [], static::class);
    }

    public function get_index(\Box_App $app)
    {
        $this->di['is_admin_logged'];
        $api = $this->di['api_admin'];
        $vms = $api->vps_list_vms();

        return $app->render('vps_admin_index', [
            'vms' => $vms,
            'vps_domain' => $this->di['mod_service']('Vps')->getVpsDomain(),
        ]);
    }

}
