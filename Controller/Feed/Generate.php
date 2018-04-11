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

class Generate extends \Magento\Framework\App\Action\Action
{

    protected $generator;
    /**
     * Constructor
     *
     * @param \Magento\Framework\App\Action\Context  $context
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \SearchSpring\Feed\Helper\Generator $generator
    ) {
        parent::__construct($context);
        $this->generator = $generator;
    }

    /**
     * Execute view action
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $req = $this->getRequest();
        $response = $this->generator->generate();
    }


}
