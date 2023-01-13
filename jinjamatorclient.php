<?php
declare (strict_types = 1);

class JinjamatorClientException extends Exception
{}

class JinjamatorClientAuthorizationExpiredException extends Exception
{}

class JinjamatorClientAuthorizationInvalidException extends Exception
{}

class JinjamatorClient
{

    protected string $username;
    protected string $password;
    protected $http;
    protected array $headers = [];
    protected int $timeout;
    protected int $bearer_expires_at;
    protected int $bearer_expires_in;

    protected string $baseurl;
    protected int $user_id;
    protected array $http_status;
    protected JinjamatorTaskList $task_list;

    public function __construct(string $baseurl)
    {

        $this->baseurl = $baseurl;
        $this->bearer_expires_at = -1;
        $this->http = curl_init($baseurl);
        $this->configuration = [];

        curl_setopt($this->http, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->http, CURLOPT_HEADERFUNCTION, function ($ch, $header) {
            $tmp = explode(':', trim($header), 2);
            switch ($tmp[0]) {
                case 'Authorization':
                case 'access_token':
                    $this->headers["Authorization"] = trim(strval($tmp[1]));
                    $token_data = $this->decode_jwt($this->headers["Authorization"]);
                    $this->bearer_expires_at = $token_data->exp;
                    $this->bearer_expires_in = $token_data->exp - $token_data->iat;
                    break;
            }
            return strlen($header);
        });
    }

    public function __call(string $name, array $arguments)
    {
        // lazy eval things
        switch ($name) {
            case "tasks":
                // caching
                if (!isset($this->task_list)) {
                    $this->task_list = new JinjamatorTaskList($this, $this->_get('/tasks')['tasks']);
                }

                // return $this->task_list;
                switch (count($arguments)) {
                    case 0:
                        return $this->task_list;
                    case 1:
                        if (array_key_exists($arguments[0], $this->task_list->get())) {
                            return $this->task_list[$arguments[0]];
                        } else {

                            throw new JinjamatorClientException('Task: ' . $arguments[0] . " not found. Valid Tasks:\n" . implode("\n", array_keys($this->task_list->get())));
                        }

                }
        }
        return $this;
    }

    private function _check_response()
    {
        $this->http_status = curl_getinfo($this->http);
        if (curl_errno($this->http)) {
            die('Couldn\'t send request: ' . curl_error($this->http));
        } else {
            $http_status = curl_getinfo($this->http, CURLINFO_HTTP_CODE);
            switch ($http_status) {
                case 200:
                case 308:
                    break;
                case 401:
                    if ($this->bearer_expires_at != -1) {
                        $this->_renew_token();
                    }

                    throw new JinjamatorClientAuthorizationInvalidException("Request failed: Invalid username or password. HTTP status code: " . $http_status);
                default:
                    die('Request failed: HTTP status code: ' . $http_status);
            }
        }

    }

    private function _get_headers()
    {
        $retval = array();
        foreach ($this->headers as $k => $v) {
            array_push($retval, $k . ":" . $v);
        }
        return $retval;
    }

    public function _get(string $url)
    {

        $resource = $this->baseurl . $url;
        $this->headers['Content-Type'] = "application/x-www-form-urlencoded";
        curl_setopt($this->http, CURLOPT_URL, $resource);
        curl_setopt($this->http, CURLOPT_HTTPHEADER, $this->_get_headers());
        curl_setopt($this->http, CURLOPT_HTTPGET, true);

        $res = curl_exec($this->http);
        $this->last_request_curl_info = curl_getinfo($this->http);
        $this->_check_response();

        return json_decode($res, true);

    }

    public function _post(string $url, array $payload)
    {        
        $json_payload = json_encode($payload);
        $resource = $this->baseurl . $url;
        $this->headers['Content-Type'] = 'application/json';
        curl_setopt($this->http, CURLOPT_URL, $resource);
        curl_setopt($this->http, CURLOPT_HTTPHEADER, $this->_get_headers());
        curl_setopt($this->http, CURLOPT_POST, true);
        curl_setopt($this->http, CURLOPT_POSTFIELDS, $json_payload);

        $res = curl_exec($this->http);
        $this->_check_response();

        return json_decode($res, true);

    }

    public function _download_file(string $url, int $timeout = 600)
    {

        $this->headers['Content-Type'] = "application/x-www-form-urlencoded";
        $fp = fopen('php://temp', 'w');
        curl_setopt($this->http, CURLOPT_URL, $this->baseurl . $url);
        curl_setopt($this->http, CURLOPT_HTTPHEADER, $this->_get_headers());
        curl_setopt($this->http, CURLOPT_HTTPGET, true);
        curl_setopt($this->http, CURLOPT_FILE, $fp);
        curl_setopt($this->http, CURLOPT_TIMEOUT, $timeout);
        $res = curl_exec($this->http);
        $this->_check_response();
        rewind($fp);
        return $fp;
    }

    private function decode_jwt($token)
    {
        // yes it's from stackoverflow
        return json_decode(base64_decode(str_replace('_', '/', str_replace('-', '+', explode('.', $this->headers['Authorization'])[1]))));
    }

    private function _renew_token()
    {
        if ($this->bearer_expires_at - time() <= 0) {
            throw new JinjamatorClientAuthorizationExpiredException('Authorization expired, please reauthenticate');
        }
        if ($this->bearer_expires_at - time() < $this->bearer_expires_in / 2) {
            curl_setopt($this->http, CURLOPT_URL, $this->baseurl . "/aaa/token");
            curl_setopt($this->http, CURLOPT_HTTPHEADER, $this->_get_headers());
            curl_setopt($this->http, CURLOPT_HTTPGET, true);
            $data = json_decode(curl_exec($this->http), true);
            $this->last_request_curl_info = curl_getinfo($this->http);
            $this->_check_response();
        }
    }

    public function login(string $username, string $password)
    {
        $this->username = $username;
        $data = $this->_post("/aaa/login/local", array("username" => $username, "password" => $password));
        $this->headers['Authorization'] = $data['access_token'];
        $this->user_id = $data['user_id'];
        $this->bearer_expires_at = $data['expires_at'];
        $this->bearer_expires_in = $data['expires_in'];
        return $this;
    }

}

class JinjamatorTaskList implements ArrayAccess
{
    private JinjamatorClient $parent;
    protected array $task_list;

    public function __construct(JinjamatorClient $parent, array $definition)
    {
        $this->parent = $parent;
        $this->task_list = [];
        foreach ($definition as $obj) {
            $this->task_list[$obj['path']] = new JinjamatorTask($parent, $obj);
        }

    }

    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) {
            $this->task_list[] = $value;
        } else {
            $this->task_list[$offset] = $value;
        }
    }

    public function offsetExists($offset)
    {
        return isset($this->task_list[$offset]);
    }

    public function offsetUnset($offset)
    {
        unset($this->task_list[$offset]);
    }

    public function offsetGet($offset)
    {
        return isset($this->task_list[$offset]) ? $this->task_list[$offset] : null;
    }

    public function get()
    {
        return $this->task_list;
    }

}

class JinjamatorJobResults
{
    protected string $job_id;
    protected JinjamatorClient $client;
    protected array $raw_results;

    public function __construct(JinjamatorClient $client, string $job_id, array $results)
    {
        $this->client = $client;
        $this->job_id = $job_id;
        $this->raw_results = $results;

    }

    public function files()
    {
        return $this->raw_results["files"];
    }

    public function get_file($filename)
    {
        return $this->client->_download_file('/files/download/' . $this->job_id . "/" . $filename);
    }

    public function save_file_to($filename, $destionation_path)
    {
        $fp_src = $this->get_file($filename);
        $fp_dst = fopen($destionation_path, 'w');
        while (!feof($fp_src)) {
            fwrite($fp_dst, fread($fp_src, 4096));
        }
        fclose($fp_src);
        fclose($fp_dst);
        return $destionation_path;
    }

    public function __toString()
    {
        ob_start();
        // var_dump($this->raw_results);
        $content = ob_get_contents();
        ob_end_clean();
        return $content;
    }

    public function last()
    {
        $last_msg_idx = count($this->raw_results['log']) - 1;
        $timestamp = array_key_first($this->raw_results['log'][$last_msg_idx]);
        return $this->raw_results['log'][$last_msg_idx][$timestamp];
    }

    public function get_raw_results()
    {
        return $this->raw_results;
    }

}

class JinjamatorJob
{
    protected JinjamatorClient $parent;
    protected string $id;
    protected string $state;
    protected string $task_path;

    public function __construct(JinjamatorClient $parent, string $id)
    {
        $this->parent = $parent;
        $this->id = $id;
        $this->state = "unknown";

    }

    public function status()
    {
        $res = $this->parent->_get('/jobs/' . $this->id . '?log-level=TASKLET_RESULT');
        $this->state = $res['state'];
        $this->task_path = $res['jinjamator_task'];
        return $this->state;
    }

    public function logs($level = "ERROR")
    {
        return $this->parent->_get('/jobs/' . $this->id . '?log-level=' . $level);
    }

    public function results()
    {
        if ($this->state == "unknown") {
            $this->status();
        }
        return new JinjamatorJobResults($this->parent, $this->id, $this->parent->_get('/jobs/' . $this->id . '?log-level=INFO'));

    }

    public function __toString()
    {
        return $this->id;
    }

}

class JinjamatorTaskConfiguration implements ArrayAccess
{

    protected array $full_schema;
    public array $configuration = [];

    public function __construct(array $schema)
    {
        $this->full_schema = $schema;
    }

    private function build_object_properties()
    {
        foreach ($this->full_schema['properties'] as $prop_name => $prop_config) {
            switch (strtolower($prop_config['type'])) {
                case 'string':
                case 'boolean':
                case 'array':
                case 'object':
                case 'number':

            }

        }
    }

    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) {
            $this->configuration[] = $value;
        } else {
            $this->configuration[$offset] = $value;
        }
    }

    public function offsetExists($offset)
    {
        return isset($this->configuration[$offset]);
    }

    public function offsetUnset($offset)
    {
        unset($this->configuration[$offset]);
    }

    public function offsetGet($offset)
    {
        return isset($this->configuration[$offset]) ? $this->configuration[$offset] : null;
    }

    public function get()
    {
        return $this->configuration;
    }

}

class JinjamatorTask
{
    private JinjamatorClient $parent;
    protected string $id;
    protected string $path;
    protected string $base_dir;
    protected string $description;
    protected string $environment;
    protected string $output_plugin_name;

    protected bool $schema_is_dirty;
    public JinjamatorTaskConfiguration $configuration;

    public function __construct(JinjamatorClient $parent, array $definition)
    {
        $this->parent = $parent;

        $this->id = $definition['id'];
        $this->path = $definition['path'];
        $this->base_dir = $definition['base_dir'];
        $this->description = $definition['description'];
        $this->output_plugin_name = "console";
        $this->environment = "";
        $this->schema_is_dirty = true;
        $this->configuration = new JinjamatorTaskConfiguration([]);

    }

    public function set_environment(string $name)
    {
        $this->environment = $name;
        $this->schema_is_dirty = true;
        return $this;
    }

    public function set_output_plugin(string $name)
    {
        $this->output_plugin_name = $name;
        $this->schema_is_dirty = true;
        return $this;
    }

    public function run(array $vars = [])
    {
        $this->user_vars = $vars;
        $this->update_schema();

        return new JinjamatorJob($this->parent, $this->parent->_post('/tasks/' . $this->path, $this->configuration->get())['job_id']);

    }

    public function run_sync(array $vars = [], $timeout=600)
    {
        $job=$this->run($vars);
        while ($timeout > 0) {
            $status = $job->status();
            if ($status == "SUCCESS" or $status == "FAILURE") {
                break;
            }
            sleep(1);
            $timeout--;
        }
        return $job;
    }


    private function update_schema()
    {
        $vars = array();
        if ($this->schema_is_dirty) {
            $schema = $this->parent->_get('/tasks/' . $this->path . '/?schema-type=full');
            // $this->configuration=new JinjamatorTaskConfiguration($schema['schema']);
        }

        return $schema;
    }

}
?>