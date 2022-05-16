<?php

namespace Blu;

use Illuminate\Http\Resources\Json\JsonResource;

use Illuminate\Support\Facades\Log;

class EditResource extends JsonResource
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

        // add attribute
        foreach ($this->config as $field => $value)
		{
			if (isset($value['attribute']))
			{
				$arr[$value['attribute']] = $this->resource->{$value['attribute']};
			}


            if ($value['type'] == 'belongsTo')
            {
                $arr[$field] = $this->resource->{$field}()->first() ? $this->resource->{$field}()->first()->id: 0;
            }

            if ($value['type'] == 'manyMany')
            {
                $arr[$field] = $this->resource->{$field}()->get()->pluck('id');
            }
		}

        // add relation ?

        return $arr;
    }
}
