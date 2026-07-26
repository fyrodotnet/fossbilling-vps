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
 * All public methods in this class are exposed to the client using API.
 * Always think about the information you are exposing.
 */

namespace Box\Mod\Vps\Api;
use FOSSBilling\Api\AbstractApi;
use FOSSBilling\InformationException;

class Client extends AbstractApi
{
    private ?array $clusterResourcesByVmid = null;
    private array $vmConfigCache = [];

    public function getService(): \Box\Mod\Vps\Service
    {
        return $this->di['mod_service']('Vps');
    }

    protected function getClientId(): int
    {
        return (int) $this->di['loggedin_client']->id;
    }

    protected function assertVmAccess(int $vmid): void
    {
        if (!$this->getService()->clientOwnsVm($this->getClientId(), $vmid)) {
            throw new \FOSSBilling\InformationException('You do not have access to this VM', [], 403);
        }
    }

    protected function requireParams(array $data, array $keys): void
    {
        foreach ($keys as $key) {
            if (!isset($data[$key]) || $data[$key] === '') {
                throw new InformationException('Required parameter missing: ' . $key, [], 400);
            }
        }
    }

    public function provision_vm($data): array
    {
        $this->requireParams($data, ['vm_name', 'vm_os', 'vm_disk_size', 'vm_disk_type']);

        return $this->getService()->requestProvisionVm($this->di['loggedin_client'], $data);
    }

    public function provision_task_status($data): array
    {
        $this->requireParams($data, ['task_id']);

        return $this->getService()->getProvisionTaskStatusForClient(
            $this->getClientId(),
            (int) $data['task_id']
        );
    }

    public function vm_access_details($data): array
    {
        $this->requireParams($data, ['vmid']);

        return $this->getService()->getVmAccessDetails($this->getClientId(), (int) $data['vmid']);
    }

    public function vm_reset_root_password($data): array
    {
        $this->requireParams($data, ['vmid']);

        return $this->getService()->resetVmRootPassword($this->getClientId(), (int) $data['vmid']);
    }

    public function vm_start($data): bool
    {
        $this->requireParams($data, ['vmid']);

        return $this->getService()->startVm($this->getClientId(), (int) $data['vmid']);
    }

    public function vm_stop($data): bool
    {
        $this->requireParams($data, ['vmid']);

        return $this->getService()->stopVm($this->getClientId(), (int) $data['vmid']);
    }

    public function vm_reboot($data): bool
    {
        $this->requireParams($data, ['vmid']);

        return $this->getService()->rebootVm($this->getClientId(), (int) $data['vmid']);
    }

    public function vm_duplicate($data): array
    {
        $this->requireParams($data, ['vmid']);

        return $this->getService()->duplicateVm(
            $this->getClientId(),
            (int) $data['vmid'],
            $data['vm_name'] ?? null
        );
    }

    public function vm_delete($data): bool
    {
        $this->requireParams($data, ['vmid']);

        return $this->getService()->deleteVm($this->getClientId(), (int) $data['vmid']);
    }

    public function vm_delete_async($data): array
    {
        $this->requireParams($data, ['vmid']);

        return $this->getService()->requestDeleteVm($this->getClientId(), (int) $data['vmid']);
    }

    public function vm_delete_task_status($data): array
    {
        $this->requireParams($data, ['task_id']);

        return $this->getService()->getDeleteTaskStatusForClient($this->getClientId(), (int) $data['task_id']);
    }

    public function vm_snapshot_take_async($data): array
    {
        $this->requireParams($data, ['vmid']);

        return $this->getService()->requestTakeSnapshot($this->getClientId(), (int) $data['vmid']);
    }

    public function vm_snapshot_restore_async($data): array
    {
        $this->requireParams($data, ['vmid', 'snapshot_name']);

        return $this->getService()->requestRestoreSnapshot(
            $this->getClientId(),
            (int) $data['vmid'],
            (string) $data['snapshot_name']
        );
    }

    public function vm_snapshot_task_status($data): array
    {
        $this->requireParams($data, ['task_id']);

        return $this->getService()->getSnapshotTaskStatusForClient($this->getClientId(), (int) $data['task_id']);
    }

    public function container_templates($data = []): array
    {
        $storage = isset($data['storage']) && $data['storage'] !== '' ? (string) $data['storage'] : \Box\Mod\Vps\Service::STORAGE_SLOW;

        return $this->getService()->getContainerTemplates($storage);
    }

    public function container_provision($data): array
    {
        $this->requireParams($data, ['ct_name', 'template', 'ct_disk_size', 'ct_disk_type', 'network_mode']);

        return $this->getService()->requestProvisionContainer($this->di['loggedin_client'], $data);
    }

    public function container_provision_task_status($data): array
    {
        $this->requireParams($data, ['task_id']);

        return $this->getService()->getContainerProvisionTaskStatusForClient($this->getClientId(), (int) $data['task_id']);
    }

    public function containers_overview($data = []): array
    {
        return $this->getService()->getClientContainerOverview($this->getClientId());
    }

    public function container_access_details($data): array
    {
        $this->requireParams($data, ['ctid']);

        return $this->getService()->getContainerAccessDetails($this->getClientId(), (int) $data['ctid']);
    }

    public function container_reset_root_password($data): array
    {
        $this->requireParams($data, ['ctid']);

        return $this->getService()->resetContainerRootPassword($this->getClientId(), (int) $data['ctid']);
    }

    public function container_start($data): bool
    {
        $this->requireParams($data, ['ctid']);

        return $this->getService()->startContainer($this->getClientId(), (int) $data['ctid']);
    }

    public function container_stop($data): bool
    {
        $this->requireParams($data, ['ctid']);

        return $this->getService()->stopContainer($this->getClientId(), (int) $data['ctid']);
    }

    public function container_reboot($data): bool
    {
        $this->requireParams($data, ['ctid']);

        return $this->getService()->rebootContainer($this->getClientId(), (int) $data['ctid']);
    }

    public function container_delete($data): bool
    {
        $this->requireParams($data, ['ctid']);

        return $this->getService()->deleteContainer($this->getClientId(), (int) $data['ctid']);
    }

    public function get_client_vms(): array
    {
        return $this->getService()->getClientVmIds($this->getClientId());
    }

    public function has_active_subscription($data = []): bool
    {
        return $this->getService()->hasActiveVpsSubscription($this->getClientId());
    }

    public function billing_bypass_enabled($data = []): bool
    {
        return $this->getService()->isProvisionBillingBypassed();
    }

    public function vms_overview($data = []): array
    {
        return $this->getService()->getClientVmOverview($this->getClientId());
    }

    public function usage_summary($data = []): array
    {
        return $this->getService()->getClientUsageSummary($this->getClientId());
    }

    public function vm_folders($data = []): array
    {
        return $this->getService()->getClientVmFolders($this->getClientId());
    }

    public function vm_folder_create($data): array
    {
        $this->requireParams($data, ['name']);

        return $this->getService()->createClientVmFolder($this->getClientId(), (string) $data['name']);
    }

    public function vm_folder_rename($data): array
    {
        $this->requireParams($data, ['folder_id', 'name']);

        return $this->getService()->renameClientVmFolder(
            $this->getClientId(),
            (int) $data['folder_id'],
            (string) $data['name']
        );
    }

    public function vm_folder_delete($data): array
    {
        $this->requireParams($data, ['folder_id']);

        return $this->getService()->deleteClientVmFolder($this->getClientId(), (int) $data['folder_id']);
    }

    public function vm_folder_assign($data): array
    {
        $this->requireParams($data, ['vmid']);
        $folderId = isset($data['folder_id']) && $data['folder_id'] !== '' ? (int) $data['folder_id'] : null;

        return $this->getService()->assignVmToFolder($this->getClientId(), (int) $data['vmid'], $folderId);
    }

    public function container_folder_assign($data): array
    {
        $this->requireParams($data, ['ctid']);
        $folderId = isset($data['folder_id']) && $data['folder_id'] !== '' ? (int) $data['folder_id'] : null;

        return $this->getService()->assignContainerToFolder($this->getClientId(), (int) $data['ctid'], $folderId);
    }

    public function vm_folder_toggle($data): array
    {
        $this->requireParams($data, ['folder_id', 'collapsed']);
        $scope = isset($data['scope']) ? (string) $data['scope'] : 'vm';

        return $this->getService()->setFolderCollapsed(
            $this->getClientId(),
            (int) $data['folder_id'],
            (bool) $data['collapsed'],
            $scope
        );
    }
    /**
     * From client API you can call any other module API.
     *
     * This method will collect data from all APIs and merge
     * into one result.
     *
     * Be careful not to expose sensitive data from the Admin API.
     */
    public function get_info($data): array
    {
        // call custom event hook. All active modules will be notified
        $this->di['events_manager']->fire(['event' => 'onAfterClientCalledExampleModule', 'params' => ['key' => 'value']]);

        // Log message
        $this->di['logger']->info('Log something to the log file');

        $systemService = $this->di['mod_service']('System');
        $clientService = $this->di['mod_service']('Client');

        $type = $data['type'] ?? 'info';

        return [
            'data' => $data,
            'version' => $systemService->getVersion(),
            'profile' => $clientService->toApiArray($this->di['loggedin_client']),
            'messages' => $systemService->getMessages($type),
        ];
    }

		public function proxmox_all_vms() {
      $proxmox = $this->getService()->getRootProxmox();
			$allNodes = $proxmox->get('/nodes/' . $this->getService()->getProxmoxNode() . '/qemu');
			foreach ($allNodes as $node) {
				for ($i=0;$i<count($node);$i++) {
					$uptime = round(($node[$i]["uptime"]/86400),2);
					$memory = round(($node[$i]["maxmem"]/pow(1024,3)),2);
					$curmem= round(($node[$i]["mem"]/pow(1024,3)),2);
					$disk = ($node[$i]["maxdisk"]/pow(1024,3));
					$netout = round(($node[$i]["netout"]/pow(1024,3)),2);
					$netin = round(($node[$i]["netin"]/pow(1024,3)),2);
					if (strlen($node[$i]["name"]) > 10) {
						$name = substr($node[$i]["name"],0,10)."..";
					} else {
						$name = $node[$i]["name"];
					}
					$vmid = $node[$i]["vmid"];
					$status = $node[$i]["status"];
					$cpu = round($node[$i]["cpu"],2);
					$cpus = $node[$i]["cpus"];
					$vmarray[$vmid] = ["uptime" => "$uptime", "memory" => "$memory", "curmem" => "$curmem", "disk" => "$disk", "name" => "$name", "status" => "$status", "cpu" => "$cpu", "cpus" => "$cpus", "netout" => "$netout", "netin" => "$netin"];	
				}
			}
			return $vmarray;
		}

	public function proxmox_vms_by_user($uid) {
        if ($uid === null || $uid === '') {
            $uid = $this->getClientId();
        }

        return $this->getService()->getClientVmIds((int) $uid);
	}

	public function proxmox_vm_node_by_id($vmid) {
      $resource = $this->getClusterResource((int) $vmid);
      if (isset($resource['node'])) {
          return (string) $resource['node'];
      }

      $proxmox = $this->getService()->getRootProxmox();
      return $this->getService()->getVmNode($proxmox, (int) $vmid);
	}

	public function proxmox_vm_by_id($vmid) {
      $vmid = (int) $vmid;
      if (isset($this->vmConfigCache[$vmid])) {
          return $this->vmConfigCache[$vmid];
      }

      $proxmox = $this->getService()->getRootProxmox();
			$vmNode = $this->proxmox_vm_node_by_id($vmid);

			$nodeInfo = $proxmox->get("/nodes/$vmNode/qemu/$vmid/config");
			$vmData = json_decode(json_encode($nodeInfo['data']));
			$vmArray = array();
			// Get network info
			for ($i=0;$i<4;$i++) {
				$netname = "net$i";
				if (isset($vmData->$netname)) {
        	if (preg_grep('/tag/', explode("\n", $vmData->$netname))) {
          	$net[$i]['mac'] = preg_replace('/^(.*)(virtio|vmxnet3)=(.*),bridge=(.*),tag=(.*)/', '$2', $vmData->$netname);
          	$net[$i]['name'] = preg_replace('/^(.*)(virtio|vmxnet3)=(.*),bridge=(.*),tag=(.*)/', '$3', $vmData->$netname);
          	$net[$i]['vlan'] = preg_replace('/^(.*)(virtio|vmxnet3)=(.*),bridge=(.*),tag=(.*)/', '$4', $vmData->$netname);
        	} else {
          	$net[$i]['mac'] = preg_replace('/^(.*)(virtio|vmxnet3)=(.*),bridge=(.*)/', '$2', $vmData->$netname);
          	$net[$i]['name'] = preg_replace('/^(.*)(virtio|vmxnet3)=(.*),bridge=(.*)/', '$3', $vmData->$netname);
          	$net[$i]['vlan'] = "Default";
        	}
					$vmArray["net"][$i]["mac"] = $net[$i]["mac"];
					$vmArray["net"][$i]["name"] = $net[$i]["name"];
					$vmArray["net"][$i]["vlan"] = $net[$i]["vlan"];
				}
			}
			for ($i=0;$i<4;$i++) {
				$virtioname = "virtio$i";
				if (isset($vmData->$virtioname)) {
					$diskReg = preg_replace('/^(.*)size=(.*)/', '$2', $vmData->$virtioname);
					//$vmArray["virtio"][$i][$vmData->$virtioname] = $diskReg;
					$vmArray['disk'][$vmData->$virtioname] = $diskReg;
				}
			}
			for ($i=0;$i<4;$i++) {
				$scsiname = "scsi$i";
				if (isset($vmData->$scsiname)) {
					$diskReg = preg_replace('/^(.*)size=(.*),(.*)/', '$2', $vmData->$scsiname);
					//$vmArray["scsi"][$i][$vmData->$scsiname] = $diskReg;
					$vmArray['disk'][$vmData->$scsiname] = $diskReg;
				}
			}
			for ($i=0;$i<4;$i++) {
				$idename = "ide$i";
				if (isset($vmData->$idename)) {
					if (preg_grep('/^(local:iso(.*))|(none.*)/i', explode("\n", $vmData->$idename))) {
						$diskReg = "";
					} else {
						$diskReg = preg_replace('/^(.*)size=(.*),(.*)/', '$2', $vmData->$idename);
					}
					//$vmArray["ide"][$i][$vmData->$idename] = $diskReg;
					$vmArray['disk'][$vmData->$idename] = $diskReg;
				}
			}

			$vmArray['meta'] = $vmData->meta;
			$vmArray['digest'] = $vmData->digest;
			$vmArray['numa'] = $vmData->numa;
			$vmArray['sockets'] = $vmData->sockets;
			$vmArray['memory'] = $vmData->memory;
			$vmArray['scsihw'] = $vmData->scsihw;
			$vmArray['cores'] = $vmData->cores;
			$vmArray['ostype'] = $vmData->ostype;
			$vmArray['vmgenid'] = $vmData->vmgenid;
			$vmArray['smbios1'] = $vmData->smbios1;
			$vmArray['name'] = $vmData->name;
			if (isset($vmData->cpu)) { $vmArray['cpu'] = $vmData->cpu; }
			if (isset($vmData->machine)) { $vmArray['cpu'] = $vmData->machine; }
			$vmArray['boot'] = $vmData->boot;
            $resource = $this->getClusterResource($vmid);
            if (!empty($resource)) {
                if (isset($resource['cpus'])) {
                    $vmArray['sockets'] = $resource['cpus'];
                }
                if (isset($resource['maxmem'])) {
                    $vmArray['memory'] = (int) round(((int) $resource['maxmem']) / 1048576);
                }
            }

            $this->vmConfigCache[$vmid] = $vmArray;
			return $vmArray;
		}

		public function proxmox_status_by_id($argArray) {
			$vmid = $argArray[0];
			$attribute = $argArray[1];
            $resource = $this->getClusterResource((int) $vmid);
            if (!empty($resource) && array_key_exists($attribute, $resource)) {
                if ($attribute === 'uptime') {
                    return round(((int) $resource[$attribute]) / 3600, 3);
                }

                return $resource[$attribute];
            }

			$proxmox = $this->getService()->getRootProxmox();
      $vmNode = $this->proxmox_vm_node_by_id($vmid);
			$nodeInfo = $proxmox->get("/nodes/$vmNode/qemu/$vmid/status/current");
			$vmData = json_decode(json_encode($nodeInfo['data']));
			// Do some formatting
			if ($attribute == "uptime") {
				$uptime = round($vmData->$attribute/3600,3); // convert to hours
				return $uptime;
			} else {
				return $vmData->$attribute;
			}
		}

		/* For requesting a NoVNC token and session */
		public function proxmox_vnc($vmid) {
			$vmid = (int) $vmid;
			$this->assertVmAccess($vmid);
			$proxmox = $this->getService()->getRootProxmox();
			// Get token
			$config = $this->getService()->getModuleConfig();
			$authRealm = $this->getService()->getProxmoxAuthRealm();
			$rootUser = (string) ($config['proxmox_root_user'] ?? \Box\Mod\Vps\Service::DEFAULT_PROXMOX_ROOT_USER);
			$rootPassword = $this->getService()->getRootPasswordForTicketing();
			if ($rootPassword === '') {
				throw new InformationException('Root password is required for VNC ticket requests', [], 500);
			}
			$tokenRequestArray['username'] = $rootUser.'@'.$authRealm;
			$tokenRequestArray['password'] = $rootPassword;
			$tokenData = $proxmox->create("/access/ticket",$tokenRequestArray);
			$tokenJson = json_decode(json_encode($tokenData['data']));
      $vmNode = $this->proxmox_vm_node_by_id($vmid);
			$vncproxy = $proxmox->create("/nodes/$vmNode/qemu/$vmid/vncproxy",['websocket' => 1]);
			$vncProxyArray = json_decode(json_encode($vncproxy['data']));
			$data['node'] = $vmNode;
			$data['port'] = $vncProxyArray->port;
			$data['vmid'] = $vmid;
			$data['vncticket'] = $vncProxyArray->ticket;
			if ($websocket = $proxmox->get("/nodes/$vmNode/qemu/$vmid/vncwebsocket",$data)) {
				setcookie("PVEAuthCookie", $tokenJson->ticket, 0, "/", '.' . $this->getService()->getVpsDomain());
				return $data;
			} else {
				return false;
			}
		}

		public function get_vmid_from_url() {
	   	if (preg_grep('/(.*)\/[0-9]+$/', explode("\n", $_GET['_url']))) {
				$vmId = preg_replace('/(.*)\/(.*)/i', '$2', $_GET['_url']);
				return $vmId;
			}	
		}

    private function getClusterResource(int $vmid): array
    {
        $resources = $this->getClusterResourcesByVmid();

        return $resources[$vmid] ?? [];
    }

    private function getClusterResourcesByVmid(): array
    {
        if (is_array($this->clusterResourcesByVmid)) {
            return $this->clusterResourcesByVmid;
        }

        $this->clusterResourcesByVmid = [];
        try {
            $proxmox = $this->getService()->getRootProxmox();
            $resources = $proxmox->get('/cluster/resources');
            foreach ($resources['data'] ?? [] as $resource) {
                if (($resource['type'] ?? '') !== 'qemu') {
                    continue;
                }
                $vmid = (int) ($resource['vmid'] ?? 0);
                if ($vmid > 0) {
                    $this->clusterResourcesByVmid[$vmid] = $resource;
                }
            }
        } catch (\Throwable $e) {
            $this->di['logger']->warning('VPS: unable to load cluster resources cache: ' . $e->getMessage());
        }

        return $this->clusterResourcesByVmid;
    }

}
