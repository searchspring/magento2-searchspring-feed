<?php
/**
 * Module to generate a SearchSpring CSV Feed
 * Copyright (C) 2017  SearchSpring
 *
 * This file is part of SearchSpring/Feed.
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 */

namespace SearchSpring\Feed\Controller\Feed;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use SearchSpring\Feed\Helper\Generator;

class Generate implements HttpGetActionInterface
{
    /**
     * @var Generator
     */
    protected $generator;

    /**
     * Constructor
     *
     * @param Generator $generator
     */
    public function __construct(
        Generator $generator
    ) {
        $this->generator = $generator;
    }

    /**
     * Execute view action
     *
     * @return ResultInterface
     */
    public function execute()
    {
        $this->generator->generate();
    }
}
