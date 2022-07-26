<?php

namespace Blu;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class Controller
{
	public static $perPage = 25;

	public static function loadConfigWithRelation($config_name)
	{
		$config = config($config_name);

		// リレーションの処理
		foreach ($config as $field => $value)
		{
			if(isset($value['relation']))
			{
				if (is_string($value['relation']))
				{
					$config[$field]['relation'] = static::loadConfigWithRelation($value['relation']);
				}

				/*
				if (
					($value['type'] == 'belongsTo' || $value['type'] == 'manyMany') && // TODO: lowercase ?
					isset($value['relation']['model'])
				)
				{
					$model = $value['relation']['model'];
					$all = $model::all();
					$options = [];
					foreach ($all as $item)
					{
						$options[$item->id] = $item->{$value['relation']['model']['label']};
					
					}

					$config[$field]['options'] = $options;
				}
				*/
			}
		}

		return $config;
	}

	public static function queryWithSearch(
		$request,
		$config,
		$q
	) // TODO: ver8 で名前つき引数に
	{
		// AND のみを提供する、もしくは TODO: where 生成用の配列を返す関数に分離
		foreach ($config as $field => $value)
		{
			if (!isset($value['search']) || $value['search'] === false) continue;

			$searches = $value['search'];
			if (!is_array($searches)) $searches = [$field => $searches];

			foreach ($searches as $key => $searchConfig)
			{
				if (!isset($request->{$key})) continue;

				if (isset($searchConfig['compare']) && $searchConfig['compare'] == false) continue; // この場合は外でやる

				$compare = isset($searchConfig['compare']) ? strtoupper($searchConfig['compare']) : '=';

				if (!in_array($compare, ['=', 'LIKE', '>', '<', '>=', '<=', '!='])) $compare = '=';
				$search = $compare == 'LIKE' ? '%'.$request->{$key}.'%' : $request->{$key};
				
				$searchField = isset($searchConfig['field']) ? $searchConfig['field'] : $field;

				if (strpos($searchField, '.'))
				{
					$searchFields = explode('.', $searchField);
					$q->whereHas($searchFields[0], function($q) use ($searchFields, $compare, $search)
					{
						$q->where($searchFields[1], $compare, $search);
					
					});
				}
				else
				{
					$q->where($field, $compare, $search);
				}
			}
		}

		return $q;
	}

	public static function queryWithOrder(
		$request,
		$config,
		$q
	)
	{
		if ($request->order && $request->orderBy)
		{
			if (isset($config[$request->orderBy]['sort']) && $config[$request->orderBy]['sort'])
			{
				if (is_string($config[$request->orderBy]['sort']))
				{
					$q->orderBy($config[$request->orderBy]['sort'], $request->order);
				}
				else
				{
					$q->orderBy($request->orderBy, $request->order);
				}
			}
		}

		return $q;
	}


	public static function queryWithSearchOrder(
		$request,
		$config,
		$q
	)
	{
		$q = static::queryWithSearch(
			$request,
			$config,
			$q
		);

		$q = static::queryWithOrder(
			$request,
			$config,
			$q
		);

		return $q;
	}


	public static function itemsWithSearchOrder(
		$request,
		$config,
		$q
	) // TODO: ver8 で名前つき引数に
	{

		$q = static::queryWithSearch(
			$request,
			$config,
			$q
		);

		$q = static::queryWithOrder(
			$request,
			$config,
			$q
		);

		$items = $q->paginate($request->perPage ?: static::$perPage); // TODO: perPage config?

		return new ResourceCollection($items, $config);
	}

	/*
	 * item with attributes
	 */
	public static function itemWithAttributes($item, $config)
	{
		$loads = [];
		foreach ($config as $field => $value)
		{
			if (isset($value['attribute']))
			{
				$loads[] = $value['attribute'];
				$item->{$value['attribute']};
			}

			if ($config['type'] == 'datetime-local')
			{
				$item->{$field} = $item->{$field}->format('Y-m-d\TH:i');
			}
		}

		Log::debug($item);
		return $item;
	}

	public static function validate($request, $item, $config, $rules, $useDefault = false, $customs = [])
	{
		// TODO: begin transaction ? 再起呼び出しのために外におく？

		return static::saveRecursive($request, $item, $config, $live = false, $rules, $useDefault, $namePrefix = '', $customs);
		
		// TODO: end transaction
	}

	public static function save($request, $item, $config, $rules, $useDefault = false, $customs = [])
	{
		// TODO: begin transaction ? 再起呼び出しのために外におく？

		return static::saveRecursive($request, $item, $config, $live = true, $rules, $useDefault, $namePrefix = '', $customs);
		
		// TODO: end transaction
	}


	/*
	 *
	 */
	public static function saveRecursive($request, $item, $config, $live = true, $rules, $useDefault = false, $namePrefix = '', $customs = [])
	{
		$errors = [];

		// そのまま値を保存 TODO: config setting
		$default_types = [
			'text',
			'order',
			'textarea',
			'select',
			'radio',
			'checkbox',
			'number',
			'range',
			'search',
			'tel',
			'email',
			'datetime-local',
			'date',
			'time',
			'month',
			'week',
			'url',
			// 'password', どっちにしても生で保存しないので、ここでは関与しない
			'hidden',
		];
		$coreRules = [];
		$coreAttributes = [];

		foreach ($config as $name => $fieldConfig)
		{
			if (!$fieldConfig['type']) continue;
			if (!in_array($fieldConfig['type'], $default_types)) continue;

			if (isset($rules[$name])) $coreRules[$name] = $rules[$name];

			if ($request->has($namePrefix.$name))
			{
				$item->{$name} = $request->input($namePrefix.$name);
			}
			else if ($useDefault && isset($fieldConfig['default']))
			{
				$item->{$name} = $fieldConfig['default'];
			}

			if (isset($fieldConfig['label'])) $coreAttributes[$name] = $fieldConfig['label'];
		}


		$validator = Validator::make($item->toArray(), $coreRules);
		$validator->setAttributeNames($coreAttributes);

		if (!$validator->fails())
		{
			if ($live) $item->save();
		}
		else
		{
			$errors = $validator->errors()->toArray();
			$live = false;
		}


		foreach ($config as $name => $fieldConfig)
		{
			if (!$fieldConfig['type']) continue;

			if (/*!$useDefault && */!$request->has($namePrefix.$name)) // useDefault いらない?
			{
				continue;
			}

			if (array_key_exists($fieldConfig['type'], $customs) && is_callable($customs[$fieldConfig['type']]))
			{
				$customs[$fieldConfig['type']]($name, $fieldConfig, $request, $item, $config, $live, $rules, $useDefault, $namePrefix);
			}

			switch ($fieldConfig['type'])
			{

			case 'hasOne':

				$hasOneItem = $item->{$name};
				$hasOneUseDefault = false;
				if (!$hasOneItem)
				{
					$hasOneModel = get_class($item->{$name}()->getRelated());
					$hasOneItem = new $hasOneModel;
					$hasOneUseDefault = true;
				}
				$hasOneRules = isset($rules[$name]) ? $rules[$name] : [];

				$hasOneSaveResult = static::saveRecursive($request, $hasOneItem, $fieldConfig['relation'], $live, $hasOneRules, $hasOneUseDefault, $namePrefix.$name.'.');
				if (!$hasOneSaveResult['errors'])
				{
					if ($live) $item->{$name}()->save($hasOneSaveResult['item']);
				}
				else
				{
					$errors[$name] = $hasOneSaveResult['errors'];
					$live = false;
				}

				break;
			case 'hasMany':
				$hasManyItems = $item->{$name} ?: [];
				$hasManyModel = get_class($item->{$name}()->getRelated());
				$hasManyRules = isset($rules[$name]) ? $rules[$name] : [];
				$hasManyForignKeyName = $item->{$name}()->getForeignKeyName();
				$hasManyValues = [];


				if ($request->has($namePrefix.$name))
				{
					$hasManyValues = $request->input($namePrefix.$name);
				}
				// 外部キーが デフォルトを持たない時のために、ないものはセットしておく (というかリレーションはこれで完結する)
				foreach ($hasManyValues as $k => $v)
				{
					if(!isset($v[$hasManyForignKeyName])) $hasManyValues[$k][$hasManyForignKeyName] = $item->id;
				}


				// default は取らない

				$hasManySaveResult = static::bulkSave($request, $hasManyModel, $hasManyValues, $hasManyItems, $fieldConfig['relation'], $live, $hasManyRules, $namePrefix.$name.'.', $customs);

				if (!$hasManySaveResult['errors'])
				{
					if ($live)
					{
						foreach ($hasManySaveResult['items'] as $hasManySavedNewItem)
						{
							$item->{$name}()->save($hasManySavedNewItem);
						}
					}
				}
				else
				{
					$errors[$name] = $hasManySaveResult['errors'];
					$live = false;
				}

				break;
			case 'belongsTo':
				$belongstToModel = get_class($item->{$name}()->getRelated());

				$belongsToId = 0;
				if ($request->has($namePrefix.$name))
				{
					$belongsToId = $request->input($namePrefix.$name);
				}
				else if ($useDefault && isset($fieldConfig['default']))
				{
					$belongsToId = $fieldConfig['default'];
				}

				// validation required ?

				$belongsToItem = $belongstToModel::find($belongsToId);
				
				if ($live)
				{
					if ($belongsToItem)
					{
						$item->{$name}()->associate($belongsToItem);
					}
					else
					{
						$item->{$name}()->dissociate();
					}

					
					// $item->refresh(); // 必要 ? Update ではあるとダメ
					$item->save();
				}

				break;
			case 'manyMany':
				$manyManyIds = [];
				if ($request->has($namePrefix.$name))
				{
					$manyManyIds = $request->input($namePrefix.$name);
				}
				else if ($useDefault && isset($fieldConfig['default']))
				{
					$manyManyIds = $fieldConfig['default'];
				}

				if (is_array($manyManyIds))
				{
					if ($live) $item->{$name}()->sync($manyManyIds);
				}

				break;
			case 'manyManyPivot':
				$manyManyPivotValues = [];
				if ($request->has($namePrefix.$name))
				{
					$manyManyPivotValues = $request->input($namePrefix.$name);
				}

				Log::debug('manyManyPivotValues');
				Log::debug($manyManyPivotValues);

				foreach ($manyManyPivotValues as $pivotValue)
				{
					// TODO: このままではおそらく pivot が複数の場合にダメ、
					if (isset($pivotValue['attach']))
					{
						if ($pivotValue['attach'])
						{
							if ($live)
							{
								$attachValue = [];
								$attachValue[$pivotValue['id']] = isset($pivotValue['pivot']) ? $pivotValue['pivot']: [];
								$item->{$name}()->syncWithoutDetaching($attachValue);
							}
						}
						else
						{
							if ($live) $item->{$name}()->detach($pivotValue['id']);
						}
					}
					else if (isset($pivotValue['pivot']))
					{
						if ($live)
						{
							$attachValue = [];
							$attachValue[$pivotValue['id']] = $pivotValue['pivot'];
							$item->{$name}()->syncWithoutDetaching($attachValue);
						}
					}
				}

				break;
			default:
				break;
			}

		}

	Log::debug(	$item );
		$item->refresh();

	Log::debug("refresh" );
	Log::debug(	$item );
		return [
			'item' => $item,
			'errors' => $errors,
		];

	}
	

	public static function bulkSave($request, $model, $values, $items = [], $config, $live = true, $rules, $namePrefix = '', $customs = [])
	{
		$errors = [];
		$newItems = [];

		foreach ($values as $key => $value)
		{
			// 同じ id のものを探す
			$existKey = null;
			if (isset($value['id']) && $value['id']) // TODO: pk
			{
				foreach ($items as $k => $v)
				{
					if ($v->id == $value['id'])
					{
						$existKey = $k;
						break;
					}
				}
			}

			// edit の処理
			if (! is_null($existKey)) // edit
			{
				// delete の処理
				if (isset($value['delete']) && $value['delete'])
				{
					// 
					if ($live) $items[$k]->delete();
				}
				else
				{
					$saveResult = static::saveRecursive($request, $items[$k], $config, $live, $rules, false, $namePrefix.$key.'.', $customs);
					if ($saveResult['errors']) $errors[$key] = $saveResult['errors'];
				}
			}
			else // create
			{
				$newItem = new $model;
				$saveResult = static::saveRecursive($request, $newItem, $config, $live, $rules, true, $namePrefix.$key.'.', $customs);
				$newItems[] = $saveResult['item'];
				if ($saveResult['errors']) $errors[$key] = $saveResult['errors'];
			}
		}

		return [
			'items' => $newItems,
			'errors' => $errors,
		];
	}
}
