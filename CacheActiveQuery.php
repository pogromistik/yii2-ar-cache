<?php

namespace sitkoru\cache\ar;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * Class CacheActiveQuery
 *
 * @package sitkoru\cache\ar
 */
class CacheActiveQuery extends ActiveQuery
{
    private $dropConditions = [];
    private $noCache = false;

    /**
     * @inheritdoc
     */
    public function all($db = null)
    {
        $command = $this->createCommand($db);
        $rawSql = $command->rawSql;
        $key = $this->generateCacheKey($rawSql, 'all');
        /**
         * @var ActiveRecord[] $fromCache
         */
        \Yii::info(
            "Look in cache for " . $key,
            'cache'
        );
        $fromCache = \Yii::$app->cache->get($key);
        if (!$this->noCache && $fromCache) {
            ActiveQueryCacheHelper::profile(ActiveQueryCacheHelper::PROFILE_RESULT_HIT_ALL, $key, $rawSql);
            \Yii::info(
                "Success for " . $key,
                'cache'
            );
            $resultFromCache = [];
            foreach ($fromCache as $i => $model) {
                $key = $i;
                if ($model instanceof ActiveRecord) {
                    $model->afterFind();
                }
                if (is_string($this->indexBy)) {
                    $key = $model instanceof ActiveRecord ? $model->{$this->indexBy} : $model[$this->indexBy];
                }
                $resultFromCache[$key] = $model;
            }
            return $resultFromCache;
        } else {
            \Yii::info(
                "Miss for " . $key,
                'cache'
            );
            ActiveQueryCacheHelper::profile(
                $this->noCache ? ActiveQueryCacheHelper::PROFILE_RESULT_NO_CACHE : ActiveQueryCacheHelper::PROFILE_RESULT_MISS_ALL,
                $key,
                $rawSql
            );
            $models = parent::all($db);
            if ($models) {
                if (!$this->noCache) {
                    $this->insertInCacheAll($key, $models);
                }

                return $models;
            } else {
                return [];
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function one($db = null)
    {
        $command = $this->createCommand($db);
        $rawSql = $command->rawSql;
        $key = $this->generateCacheKey($command->rawSql, 'one');
        /**
         * @var ActiveRecord $fromCache
         */
        \Yii::info(
            "Look in cache for " . $key,
            'cache'
        );
        $fromCache = \Yii::$app->cache->get($key);
        if (!$this->noCache && $fromCache) {
            ActiveQueryCacheHelper::profile(ActiveQueryCacheHelper::PROFILE_RESULT_HIT_ONE, $key, $rawSql);
            \Yii::info(
                "Success for " . $key,
                'cache'
            );
            if ($fromCache instanceof ActiveRecord) {
                $fromCache->afterFind();
            }

            return $fromCache;
        } else {
            ActiveQueryCacheHelper::profile(
                $this->noCache ? ActiveQueryCacheHelper::PROFILE_RESULT_NO_CACHE : ActiveQueryCacheHelper::PROFILE_RESULT_MISS_ONE,
                $key,
                $rawSql
            );
            \Yii::info(
                "Miss for " . $key,
                'cache'
            );
            $model = parent::one();
            if ($model && $model instanceof ActiveRecord) {
                if (!$this->noCache) {
                    $this->insertInCacheOne($key, $model);
                }

                return $model;
            } else {
                return null;
            }
        }
    }

    /**
     * @param string  $sql
     *
     * @param         $mode
     *
     * @return string
     */
    private function generateCacheKey($sql, $mode)
    {
        $key = $mode;
        $key .= strtolower($this->modelClass);
        $key .= $sql;
        if (count($this->where) == 0 && count($this->dropConditions) == 0) {
            $this->dropCacheOnCreate();
        }
        //pagination
        if ($this->limit > 0) {
            $key .= "limit" . $this->limit;
        }
        if ($this->offset > 0) {
            $key .= "offset" . $this->offset;
        }
        \Yii::info(
            "Generate cache key for " . $key . ':  . md5($key)',
            'cache'
        );
        return md5($key);
    }

    /**
     * @param              $key
     * @param ActiveRecord $model
     *
     * @return bool
     */
    private function insertInCacheOne($key, $model)
    {
        /** @var $class ActiveRecord */
        $class = $this->modelClass;
        $keys = $model->getPrimaryKey(true);
        $pk = reset($keys);
        $indexes = [
            $class::tableName() => [
                $pk
            ]
        ];
        $toCache = clone $model;
        $toCache->fromCache = true;
        ActiveQueryCacheHelper::insertInCache($key, $toCache, $indexes, $this->dropConditions);

        return true;
    }

    /**
     * @param                $key
     * @param ActiveRecord[] $models
     *
     * @return bool
     */
    private function insertInCacheAll($key, $models)
    {
        /** @var $class ActiveRecord */
        $class = $this->modelClass;
        $indexes = [
            $class::tableName() => [
            ]
        ];
        $toCache = $models;
        foreach ($toCache as $index => $model) {
            $mToCache = clone $model;
            $mToCache->fromCache = true;
            $toCache[$index] = $mToCache;
            $pks = $mToCache->getPrimaryKey(true);
            $indexes[$class::tableName()][] = reset($pks);
        }

        ActiveQueryCacheHelper::insertInCache($key, $toCache, $indexes, $this->dropConditions);

        return true;
    }

    /**
     * @param string|null  $param
     * @param string|array $value
     *
     * @return self
     */
    public function dropCacheOnCreate($param = null, $value = null)
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        foreach ($value as $val) {
            $event = [
                'type'  => 'create',
                'param' => $param,
                'value' => $val
            ];
            $this->dropConditions[] = $event;
        }

        return $this;
    }

    /**
     * @param string     $param
     * @param null|array $condition
     *
     * @return self
     */
    public function dropCacheOnUpdate($param, $condition = null)
    {
        $event = [
            'type'       => 'update',
            'param'      => $param,
            'conditions' => []
        ];
        if ($condition) {
            foreach ($condition as $param => $value) {
                $event['conditions'] = [$param => $value];
            }
        }
        $this->dropConditions[] = $event;

        return $this;
    }

    /**
     * @return static
     */
    public function noCache()
    {
        $this->noCache = true;

        return $this;
    }

    public function asArray($value = true)
    {
        if ($value) {
            $this->noCache = true;
        }
        return parent::asArray($value);
    }

    public function deleteAll()
    {
        /**
         * @var $class ActiveRecord
         */
        $params = [];
        $class = $this->modelClass;
        return $class::deleteAll($this->where, $params);
    }


}
