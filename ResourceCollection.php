<?php

namespace Blu;

use Illuminate\Support\Facades\Log;

class ResourceCollection extends \Illuminate\Http\Resources\Json\ResourceCollection
{
    public $config = [];

    public function __construct($resource, $config = [])
    {
        parent::__construct($resource);

        $this->config = $config;
    }


    /**
     * Transform the resource collection into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        // return parent::toArray($request);

        if (!$this->config) return parent::toArray($request);

        $arr = $this->collection->map->toArray($request, $this->config)->all();
        
        return $arr;
    }
}
