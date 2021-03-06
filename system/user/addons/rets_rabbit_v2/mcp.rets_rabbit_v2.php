<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

require_once PATH_THIRD . 'rets_rabbit_v2/vendor/autoload.php';

use Anecka\RetsRabbit\Core\ApiService;
use Anecka\RetsRabbit\Core\Bridges\EEBridge;
use Anecka\RetsRabbit\Core\Resources\ServersResource;

class Rets_rabbit_v2_mcp
{
    private $apiService = null;
    private $siteId = 1;
    private $sideBar = null;

    public function __construct()
    {
        ee()->load->library('Rr_v2_cache', null, 'Rr_cache');
        ee()->load->model('rets_rabbit_v2_config', 'Rr_config');
        ee()->load->library('Rr_v2_token_service', null, 'Rr_token');

        // Set up ee bridge and token fetcher blueprint
        $bridge = new EEBridge;
        $bridge->setTokenFetcher(function () {
            return ee()->Rr_cache->get('access_token', true);
        });

        // Set up api service instance
        $this->apiService = new ApiService($bridge);
        ee()->Rr_token->setApiService($this->apiService);

        // Fetch site id config
        $this->siteId = ee()->config->item('site_id');
        ee()->Rr_config->getBySiteId($this->siteId);

        // Create sidebar
        $this->sidebar = ee('CP/Sidebar')->make();
        $home_nav = $this->sidebar->addHeader(lang('settings_short'), ee('CP/URL', 'addons/settings/rets_rabbit_v2'));
        $server_nav = $this->sidebar->addHeader(lang('server'), ee('CP/URL', 'addons/settings/rets_rabbit_v2/servers'));
        $explore_nav = $this->sidebar->addHeader(lang('explore'), ee('CP/URL', 'addons/settings/rets_rabbit_v2/explore'));

        // See if dev set custom endpoint for some reason
        if($customEndpoint = ee()->Rr_config->api_endpoint) {
            $this->apiService->overrideBaseApiEndpoint($customEndpoint);
        }

        // Refreshtoken if not valid
        if(!ee()->Rr_token->isValid()) {
            ee()->Rr_token->refresh();
        }
    }

    /**
     * Display the settings for the plugin on the "home page".
     * 
     * These settings are per site.
     *
     * @return mixed
     */
    public function index()
    {
        ee()->load->helper('form');
        ee()->load->library('table');
        ee()->Rr_config->getBySiteId($this->siteId);

        $log_searches_yes = [
            'name' => 'log_searches',
            'id'   => 'log_searches_y',
            'value' => 1,
            'checked' => ee()->Rr_config->log_searches == 1 ? TRUE : FALSE ,
        ];

        $log_searches_no = [
            'name' => 'log_searches',
            'id'   => 'log_searches_n',
            'value' => 0,
            'checked' => ee()->Rr_config->log_searches == 0 ? TRUE : FALSE ,
        ];

        $form = array(
            'client_id'		=> form_hidden('id', (ee()->Rr_config->id == null ? '' : ee()->Rr_config->id) ).
                form_hidden('site_id', (ee()->Rr_config->id == null ? $this->siteId : ee()->Rr_config->site_id) ).
                form_input('client_id', (ee()->Rr_config->id == null ? '': ee()->Rr_config->client_id) ),
            'client_secret'		=> form_input('client_secret', (ee()->Rr_config->id == null ? '': ee()->Rr_config->client_secret) ),
            'log_searches'  => form_radio($log_searches_yes)." Yes &nbsp;".form_radio($log_searches_no)." No",
            'api_endpoint'  => form_input('api_endpoint', (ee()->Rr_config->api_endpoint == null ? '': ee()->Rr_config->api_endpoint) ),
        );

        $vars = array();

        $vars["data"] = $form;

        return array(
            'body' => ee()->load->view('index', $vars, TRUE),
            'breadcrumb' => array(
                ee('CP/URL', 'addons/settings/rets_rabbit_v2')->compile() => lang('rets_rabbit_module_name')
            ),
            'heading' => lang('settings_short')
        );
    }

    /**
     * Save rets rabbit api config
     *
     * @return mixed
     */
    public function save_config()
    {
        $data = array();
		$id = ee()->input->post('id');
        $data['site_id'] = $this->siteId;
		$data['client_id'] = ee()->input->post('client_id');
		$data['client_secret'] = ee()->input->post('client_secret');
        $data['api_endpoint'] = ee()->input->post('api_endpoint');
        $data['log_searches'] = ee()->input->post('log_searches');

		if(is_null($id) || empty($id))  {
			ee()->Rr_config->insert($data);
		} else {
			ee()->Rr_config->update($data, $id);
		}

        ee()->Rr_cache->delete();

		ee()->session->set_flashdata('message_success', lang('config_form_success'));

		ee()->functions->redirect(ee('CP/URL', 'addons/settings/rets_rabbit_v2'));
    }

    /**
     * Show which Rets Rabbit servers are connected to this account.
     *
     * @return mixed
     */
    public function servers()
    {
        ee()->load->library('table');
        ee()->load->model('rets_rabbit_v2_server', 'Rr_server');

        $vars = array(
            'servers' => array()
        );
        $resource = new ServersResource($this->apiService);
        $serversResponse = $resource->search();
        $servers = array();
        $matchedServerIds = array();
        // Get current servers for this siteId
        $eeServers = ee()->Rr_server->getBySiteId($this->siteId);

        if($serversResponse->didSucceed()) {
            $servers = $serversResponse->getResponse()['data'];
        }
        
        // Loop through rr account servers and do syncing and inserting
        foreach($servers as $index => $server) {
            $found = false;
            
            // See if we already have the rr account server stored locally
            foreach($eeServers as $eeS) {
                if($eeS->server_id === $server['id']) {
                    $data = array(
                        'name' => $server['name'],
                        'server_id'     => $server['id'],
                        'site_id'       => $eeS->site_id,
                        'short_code'    => $eeS->short_code,
                        'is_default'    => $eeS->is_default
                    );

                    // Mark this server as found
                    $matchedServerIds[] = $eeS->id;

                    ee()->Rr_server->update($data, $eeS->id);
                    $found = true;
                    break;
                }
            }
            
            // The account server wasn't found locally so save it
            if(!$found) {
                $data = array(
                    'site_id'       => $this->siteId,
                    'is_default'    => !$index ? true : false,
                    'server_id'     => $server['id'],
                    'name'          => $server['name'],
                );

                ee()->Rr_server->insert($data);
            }
        }

        // Remove servers this account does not have access to anymore
        foreach($eeServers as $localS) {
            if(!in_array($localS->id, $matchedServerIds)) {
                ee()->Rr_server->delete($localS->id);
            }
        }

        $eeServers = ee()->Rr_server->getBySiteId($this->siteId);
        $vars['servers'] = $eeServers;

        return array(
            'body' => ee()->load->view('servers', $vars, TRUE),
            'breadcrumb' => array(
                ee('CP/URL', 'addons/settings/rets_rabbit_v2/servers')->compile() => lang('rets_rabbit_module_name')
            ),
            'heading' => lang('server'),
            'sidebar' => $this->sidebar
        );
    }

    /**
     * Update Servers
     * @return void
     */
    public function update_servers()
    {
        ee()->load->model('rets_rabbit_v2_server', 'Rr_server');

        $ids = ee()->input->post('id');
		$shortcodes = ee()->input->post('short_code');
		$is_default = ee()->input->post('is_default');

        foreach($shortcodes as $index => $shortcode) {
            ee()->Rr_server->updateShortCode($ids[$index], $shortcode);
        }

        ee()->Rr_server->setDefault($this->siteId, $is_default);

        ee()->session->set_flashdata('message_success', lang('server_form_success'));
        ee()->functions->redirect(ee('CP/URL', 'addons/settings/rets_rabbit_v2/servers'));
    }

    /**
     * Refresh the cache
     *
     * @return void
     */
    public function servers_refresh()
    {
        $redirect = ee()->input->get('redirect', 'index');

        ee()->Rr_cache->delete();
        ee()->session->set_flashdata('message_success', lang('rets_rabbit_cache_cleared'));

        if($redirect == "server") {
            ee()->functions->redirect(ee('CP/URL', 'addons/settings/rets_rabbit_v2/servers'));
        } else {
            ee()->functions->redirect(ee('CP/URL', 'addons/settings/rets_rabbit_v2'));
        }
    }

    /**
     * Show the explorer view
     *
     * @return array
     */
    public function explore()
    {
        ee()->cp->load_package_js('axios.min');
        ee()->cp->load_package_js('vue.min');
        ee()->cp->load_package_js('explorer');
        ee()->load->model('rets_rabbit_v2_server', 'Rr_server');

        $vars = array(
            'servers' => ee()->Rr_server->all(),
            'resource_url' => ee('CP/URL', 'addons/settings/rets_rabbit_v2/exploreGetListings')
        );

        return array(
            'body' => ee()->load->view('explore', $vars, TRUE),
            'breadcrumb' => array(
                ee('CP/URL', 'addons/settings/rets_rabbit_v2')->compile() => lang('rets_rabbit_module_name')
            ),
            'heading' => lang('explore')
        );
    }

    /**
     * Get listings from the explorer
     *
     * @return mixed
     */
    public function exploreGetListings()
    {
        ee()->load->library('Rr_v2_property_service', null, 'Rr_properties');
        $filter = ee()->input->get('filter');
        $skip = ee()->input->get('skip');

        $params = array(
            '$top' => '1'
        );

        if($filter) {
            $params['$filter'] = $filter;
        }

        if($skip) {
            $params['$skip'] = $skip;
        }

        $res = ee()->Rr_properties->search($params);
        $listings = array();

        if($res->didSucceed()) {
            $listings = $res->getResponse()['value'];
        }

        ee()->output->set_header('Content-Type: application/json');
        exit(json_encode($listings));
        
    }
}
