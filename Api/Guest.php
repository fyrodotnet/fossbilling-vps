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
 * All public methods in this class are exposed to public. Always think
 * what kind of information you are exposing. Emails, passwords and other
 * information should NOT be returned by functions in this class.
 *
 * This module can be called from API or in template
 */

namespace Box\Mod\Vps\Api;

use FOSSBilling\Api\AbstractApi;
use FOSSBilling\InformationException;

class Guest extends AbstractApi
{
    public function getService(): \Box\Mod\Vps\Service
    {
        return $this->di['mod_service']('Vps');
    }

    /**
     * Return extension README.
     *
     * @return string
     */
    public function readme(): string
    {
        // We'll be using the file_get_contents to fetch the full content of the README file
        // Our example admin and client area pages will use this function to fetch the README data
        // Then, we'll tell Twig to parse and display the markdown output

        return file_get_contents(PATH_MODS . '/Vps/README.md');
    }

    /**
     * Return a random number between 1 and 100.
     *
     * @return int
     */
    public function random_number(): int
    {
        return random_int(1, 100);
    }

    public function run_provision_task($data): array
    {
        if (!isset($data['task_id']) || !isset($data['worker_token'])) {
            throw new InformationException('Missing required provisioning task parameters', [], 400);
        }

        return $this->getService()->runProvisionTaskByToken((int) $data['task_id'], (string) $data['worker_token']);
    }

    public function run_delete_task($data): array
    {
        if (!isset($data['task_id']) || !isset($data['worker_token'])) {
            throw new InformationException('Missing required delete task parameters', [], 400);
        }

        return $this->getService()->runDeleteTaskByToken((int) $data['task_id'], (string) $data['worker_token']);
    }

    public function run_snapshot_task($data): array
    {
        if (!isset($data['task_id']) || !isset($data['worker_token'])) {
            throw new InformationException('Missing required snapshot task parameters', [], 400);
        }

        return $this->getService()->runSnapshotTaskByToken((int) $data['task_id'], (string) $data['worker_token']);
    }

    public function run_container_task($data): array
    {
        if (!isset($data['task_id']) || !isset($data['worker_token'])) {
            throw new InformationException('Missing required container task parameters', [], 400);
        }

        return $this->getService()->runContainerTaskByToken((int) $data['task_id'], (string) $data['worker_token']);
    }
}
