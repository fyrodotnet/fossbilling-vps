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
 * This file connects FOSSBilling client area interface and API
 * Class does not extend any other class.
 */

namespace Box\Mod\Vps\Controller;

class Client implements \FOSSBilling\InjectionAwareInterface
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

    private function getCommonRenderVars(): array
    {
        return [
            'vps_domain' => $this->di['mod_service']('Vps')->getVpsDomain(),
        ];
    }

    /**
     * Methods maps client areas urls to corresponding methods
     * Always use your module prefix to avoid conflicts with other modules
     * in future.
     *
     * @param \Box_App $app - returned by reference
     */
    public function register(\Box_App &$app): void
    {
        //$app->get('/vps', 'get_index', [], static::class);
        $app->get('/vps', 'get_protected', [], static::class);
        $app->get('/vps/containers', 'get_containers', [], static::class);
        $app->get('/vps/manage/vm', 'get_manage', [], static::class);
        $app->get('/vps/manage/vm/:id', 'get_manage_vm', ['id' => '[0-9]+'], static::class);
        $app->get('/vps/control/vm/:id', 'get_control_vm', ['id' => '[0-9]+'], static::class);
    }

    public function get_index(\Box_App $app)
    {
        return $app->render('vps_client_index', $this->getCommonRenderVars());
    }

    public function get_protected(\Box_App $app)
    {
        $this->di['is_client_logged'];
        $pricing = $this->di['mod_service']('Vps')->getPricingRates();

        return $app->render('vps_client_index', array_merge($this->getCommonRenderVars(), ['show_protected' => true], $pricing));
    }

    public function get_containers(\Box_App $app)
    {
        $this->di['is_client_logged'];
        $pricing = $this->di['mod_service']('Vps')->getPricingRates();

        return $app->render('vps_client_index', array_merge($this->getCommonRenderVars(), ['show_protected' => true, 'containers_page' => true], $pricing));
    }

    public function get_manage(\Box_App $app)
    {
        $this->di['is_client_logged'];
        $pricing = $this->di['mod_service']('Vps')->getPricingRates();

        return $app->render('mod_vps_manage', array_merge($this->getCommonRenderVars(), ['show_protected' => true], $pricing));
    }

    public function get_manage_vm(\Box_App $app)
    {
        $this->di['is_client_logged'];
        return $app->render('mod_vps_manage_vm', array_merge($this->getCommonRenderVars(), ['show_protected' => true]));
    }

    public function get_control_vm(\Box_App $app)
    {
        $this->di['is_client_logged'];
        return $app->render('mod_vps_control_vm', array_merge($this->getCommonRenderVars(), ['show_protected' => true]));
    }

}
