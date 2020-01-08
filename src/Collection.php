<?php


namespace Yunbuye\ThinkApiDoc;


class Collection extends \think\Collection
{

    public function map(callable $callback)
    {
        $keys = array_keys($this->items);
        $items = array_map($callback, $this->items, $keys);
        return new static(array_combine($keys, $items));
    }
    public function collapse()
    {
        return new static(Arr::collapse($this->items));
    }
    public function transform(callable $callback)
    {
        $this->items = $this->map($callback)->all();

        return $this;
    }
    /**
     * Get a value retrieving callback.
     *
     * @param  callable|string|null  $value
     * @return callable
     */
    protected function valueRetriever($value)
    {
        if ($this->useAsCallable($value)) {
            return $value;
        }

        return function ($item) use ($value) {
            return Arr::data_get($item, $value);
        };
    }
    protected function useAsCallable($value)
    {
        return ! is_string($value) && is_callable($value);
    }
    public function groupBy($groupBy, $preserveKeys = false)
    {
        if (is_array($groupBy)) {
            $nextGroups = $groupBy;

            $groupBy = array_shift($nextGroups);
        }

        $groupBy = $this->valueRetriever($groupBy);

        $results = [];

        foreach ($this->items as $key => $value) {
            $groupKeys = $groupBy($value, $key);

            if (!is_array($groupKeys)) {
                $groupKeys = [$groupKeys];
            }

            foreach ($groupKeys as $groupKey) {
                $groupKey = is_bool($groupKey) ? (int)$groupKey : $groupKey;

                if (!array_key_exists($groupKey, $results)) {
                    $results[$groupKey] = new static;
                }

                $results[$groupKey]->offsetSet($preserveKeys ? $key : null, $value);
            }
        }

        $result = new static($results);

        if (!empty($nextGroups)) {
            return $result->map(function ($value)use ($nextGroups, $preserveKeys){
                if(!$value instanceof Collection){
                    $value=new static($value);
                }
                $value->groupBy($nextGroups, $preserveKeys);
            });
        }
        return $result;
    }
    public function values(){
        return new static(array_values($this->items));
    }
}