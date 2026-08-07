<?php

return [

    /**
     * 图片缩略图配置
     *
     * 用于定义图片上传后的缩略图生成规则。
     *
     * 默认尺寸：
     * - s：small（小图）
     * - m：medium（中图）
     * - l：large（大图）
     */
    'thumb' => [

        /**
         * Small 缩略图
         */
        's' => [
            'width' => 200,
            'height' => null,
        ],

        /**
         * Medium 缩略图
         */
        'm' => [
            'width' => 500,
            'height' => null,
        ],

        /**
         * Large 缩略图
         */
        'l' => [
            'width' => 800,
            'height' => null,
        ],
    ],
];
