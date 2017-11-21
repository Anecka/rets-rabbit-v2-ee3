<?php

require PATH_THIRD . "rets_rabbit_v2/libraries/tags/Base_tag.php";

class Properties_tag extends Base_tag
{
    /**
     * The different params this tag can handle
     *
     * @var array
     */
    protected $attrs = array(
        'select'        => null,
        'filter'        => null,
        'orderby'       => null,
        'top'           => 500,
        'skip'          => null,
        'short_code'    => null,
        'cache'         => null,
        'cache_duration'=> 3600,
        'strip_tags'    => false,
    );

    /**
     * Final output of the filled in attrs
     *
     * @var array
     */
    protected $params = array();

    /**
     * Map template params to query params
     *
     * @var array
     */
    protected $map = array(
        'select'    => '$select',
        'filter'    => '$filter',
        'orderby'   => '$orderby',
        'top'       => '$top',
        'skip'      => '$skip'
    );

    /**
     * Casts for param types
     *
     * @var array
     */
    protected $casts = array(
        'cache' => 'bool'
    );

    /**
     * Hide these from toArray which is used for building the query params
     *
     * @var array
     */
    protected $apiParams = array('select', 'filter', 'orderby', 'top', 'skip');

    /**
     * Rules for value setting
     *
     * @var array
     */
    protected $rules = array(
        'top' => 'max:500'
    );

    /**
     * Parse the template params
     *
     * @return void
     */
    public function parseParams()
    {
        $params = array();

        foreach($this->attrs as $k => $_v) {
            $key = $k;

            if(is_integer($k)) {
                $key = $_v;
            }

            $v = ee()->TMPL->fetch_param($key);

            if($v) {
                if(in_array($key, array_keys($this->rules))) {
                    $rule = $this->rules[$key];

                    if(strpos($rule, 'max:') !== FALSE) {
                        $max = str_replace('max:', '', $rule);

                        if($v > $max) {
                            throw new \Exception("$key must be less than $max");
                        }
                    } else if(strpos($rule, 'min:') !== FALSE) {
                        $min = str_replace('min:', '', $rule);

                        if($v < $max) {
                            throw new \Exception("$key must be greater than $min");
                        }
                    }
                }

                $params[$key] = $v;

                if(in_array($key, array_keys($this->casts))) {
                    if($this->casts[$key] === 'bool') {
                        $params[$key] = $params[$key] === 'true';
                    } else {
                        settype($params[$key], $this->casts[$key]);
                    }
                }
            }
        }

        $this->params = $params;
    }
}
