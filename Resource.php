<?php

namespace Blu;

use Illuminate\Http\Resources\Json\JsonResource;

use Illuminate\Support\Facades\Log;

class Resource extends JsonResource
{
    public $config = [];

    public function __construct($resource, $config)
    {
        parent::__construct($resource);

        if ($config && is_array($config)) $this->config = $config;
    }

    public function toArray($request, $config = [])
    {
        if ($config && is_array($config)) $this->config = $config;

        $arr = parent::toArray($request);

        if (!$this->config) return $arr;

        /*
        Log::debug('$request');
        Log::debug($request);
        Log::debug('$arr');
        Log::debug($arr);
        Log::debug('$this->resource');
        Log::debug($this->resource);
        */
        
        // add attribute
        foreach ($this->config as $field => $value)
		{
			if (isset($value['attribute']))
			{
				$arr[$value['attribute']] = $this->resource->{$value['attribute']};
			}


			if ($this->resource->{$field} && $value['type'] == 'datetime-local')
			{
				$arr[$field] = $this->resource->{$field}->format('Y-m-d\TH:i');
			}

			if ($this->resource->{$field} && $value['type'] == 'date')
			{
				$arr[$field] = $this->resource->{$field}->format('Y-m-d');
			}


            /*
			if (isset($value['options']) && isset($value['options'][$this->resource->{$field}]))
			{
				$arr[$field] = $value['options'][$this->resource->{$field}];
			}
            */


            /*
            if (isset($value['relation']))
            {
                foreach ($this->config as $field => $value)
                {
                    new \Blu\Resource($, $value['relation'])
                    $this->resource->{$field};
                }
            }
            */

            /*
            if (isset($value['relation']))
            {
                $arr[$field] = $this->resource
            }
            */
		}

        // add relation ?

        return $arr;
    }
}
