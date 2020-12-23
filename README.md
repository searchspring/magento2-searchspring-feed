This Magento 2 module is used to generate a Magento 2 feed for use in a
[SearchSpring](http://www.searchspring.com) integration.

# Installation

1. Create folder `app/code/SearchSpring/Feed`
2. Copy contents of this repository into that folder.
3. Run `php bin/magento module:enable SearchSpring_Feed`
4. Run `php bin/magento setup:upgrade`

*The commands above should be run from the Magento 2 base directory*

# Security
Searchspring recommends protecting both the module feed generation endpoint and generated feed file by using simple authentication via .htaccess rules or blocking access by IP address. You can find Searchspring IP addresses for white listing [here](https://searchspring.zendesk.com/hc/en-us/articles/360021246692-Searchspring-IP-Addresses). The URLs that you should setup simple authentication or block based on IP addresses are:
* https://www.YOURDOMAIN.com/searchspring/feed/generate
* https://www.YOURDOMAIN.com/media/searchspring/*
