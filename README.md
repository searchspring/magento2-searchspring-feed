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

# Debugging the Feed
You can generate small pieces of the feed for checking out by following these steps

Hit this endpoint:
* https://www.YOURDOMAIN.com/searchspring/feed/generate?page={n}&filename={tempName}
You can also add in count={number} if you really want to slim it down

Once you've hit that endpoint you can go to
* https://www.YOURDOMAIN.com/media/searchspring/searchspring-{domainName}-{tempName}.tmp.cvs

This will download the temp file that has been added to by the generate call.

You can hit multipe pages of the generate. Say you hit the first 10 pages and then get the tmp it will have all of the calls in the one file
If your generate ever comes back with complete instead of continue you can get the entire feed with
* https://www.YOURDOMAIN.com/media/searchspring/searchspring-{domainName}-{tempName}.cvs
