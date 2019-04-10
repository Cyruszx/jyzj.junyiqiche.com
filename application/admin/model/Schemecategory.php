<?php

namespace app\admin\model;

use think\Model;

class Schemecategory extends Model
{
    // 表名
    protected $name = 'scheme_category';

    // 自动写入时间戳字段222
    protected $autoWriteTimestamp = 'int';

    // 定义时间戳字段名
    protected $createTime = 'createtime';
    protected $updateTime = 'updatetime';

    // 追加属性
    protected $append = [

    ];

    public function cities()
    {
        return $this->belongsTo('Cities', 'city_id', 'id', [], 'LEFT')->setEagerlyType(0);
    }








}
