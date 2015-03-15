# TinyWs
High performance, flexible, RFC-complaint & well documented WebSockets server library. It's licensed under MIT, so it can be used in any project (even commercial one) free of charge.
Despite repository short living time code is production-ready. In fact it's actively used everyday by many people, so I decided to make it open-source to make Internet a better place.

There are many PHP WebSocket libraries out there. Unfortunately most of them are cra^Cnot as good as they looks. Few of them are pretty decent, but their license doesn't allow flexible commercial-grade usage.
Everyone who knows me know can confirm I'm performance freak, which also reflects in that project.
TinyWs isn't perfect, it have some limitations. However none of them are really limiting it's usage. 

### What is supported and what's not?
WebSocket protocol was a insecure and buggy mess for long, long time. As of [RFC 6455](https://tools.ietf.org/html/rfc6455) situation changed - it can be considered as rather good protocol. 
Library is almost 100% RFC-complaint (see [tests](https://github.com/kiler129/TinyWs/tree/master/tests).) and supports 13th protocol version only. It's not worth supporting older ones (they are deprecated anyway).

However due to lack of time few features aren't implemented:
  * **SSL**: work in progress
  * **Deep configuration**: some parameters cannot be configured (eg. max payload)
  * **IPv6**: it should work, but it cannot be considered as tested
  * **Origin**: currently server ignores origin and accepts any
  * **Automatic fragmentation**: not an limitation, but missing improvement (but it isn't easy to implement **properly**)
  * **Protocol extensions**: there are actually no practical usage for them now (except per-packet compression)
  * **Packets compression**: considered more like Chrome-experiment with more cons than pros
  * **WebSocket over SPDY**: it's only an experiment for now
You could treat list above as small roadmap rather than list arguments against using this library ;)

## Usage
### Requirements
  * PHP >=5.3 (5.6+ is recommended due to performance improvements)
  * PHP modules: OpenSSL, mbstring
  * [CherryHttp](https://github.com/kiler129/CherryHttp)
  * [PSR-3 interfaces](https://github.com/php-fig/log)
  * [PSR-3 complaint logger](https://packagist.org/search/?tags=psr-3) is recommended (eg. lightweight [Shout](https://github.com/kiler129/Shout))

### Installation
#### Using composer
Composer is preferred method of installation due to it's simplicity and automatic dependencies management.

  0. You need composer of course - [installation takes less than a minute](https://getcomposer.org/download/)
  1. Run `php composer.phar require noflash/tinyws:dev-master` in your favourite terminal to install TinyWs with dependencies
  2. Include `vendor/autoload.php` in your application source code
  3. If you want to see logs from server you need to install [PSR-3 complaint logger](https://packagist.org/search/?tags=psr-3), eg. `php composer.phar require noflash/shout` 
 
#### Manual
Details will be available soon.  
*Basically you need to download [CherryHttp](https://github.com/kiler129/CherryHttp), [PSR-3 interfaces](https://github.com/php-fig/log) and [PSR-3 complaint logger](https://packagist.org/search/?tags=psr-3). Next put them in directory (eg. vendor) and include all files (or use PSR-4 complaint autoloader). Some details can be found in [examples README](https://github.com/kiler129/TinyWs/blob/master/examples/README.md)*

### Basic usage
Everytime message arrives, client connect/disconnect or some sort of exception occur TinyWs calls your own "ClientsHandler". It's just an object implementing (well documented) [`ClientsHandlerInterface`](https://github.com/kiler129/TinyWs/blob/master/src/ClientsHandlerInterface.php) interface. To simplify usage in really basic applications you can also just extend [`AbstractClientsHandler`](https://github.com/kiler129/TinyWs/blob/master/src/AbstractClientsHandler.php) and implement onMessage() method only.  
How simple is that? Take a look at [echo server implementation]((https://github.com/kiler129/TinyWs/blob/master/examples/echoServer.php).

## FAQ
#### Is it stable?
Long time before library was published on GitHub it was tested under various conditions. It was verified to work flawless in commercial application with multiple users using different software configurations.

#### Is it fast enough to **PLACE SCENARIO HERE**?
Every piece of that code is written with performance in mind. There's no faster PHP-bases WebSocket server on the market ;)

Notes:
  * Disable X-Debug in production environment (it really matters).  You need to comment out module in php.ini (setting xdebug.* to 0 is not enough).
  * Use *nix operating system - handling large amount of connections on Windows is not so efficient.

#### Could you add **PLACE FEATURE NAME HERE**?
Every feature request will be considered. Library is under active development, however I cannot implement everything right away myself, so pull-requests are kindly welcomed.

#### Does it work in HHVM environment?
Unfortunately I don't have professional expedience with this platform and cannot make any guaranties.

#### Why configuration is done using constants? It's against OOP!
I spent many hours rethinking & refactoring that part of library and it ended that way for many reasons.  
First of all you need to know multiuser network service differ from standard PHP a lot. Since it uses I/O multiplexing to handle multiple connections using single thread (more in *Internal notes* section) only one instance of Server can be running at the same time.  
Since there's no practical usage of multiple Server instances it's also not worth to support per instance configuration (which degrades performance significantly).

## Internal notes (for hackers)
#### How it works?
Before you start thinking about understanding internals of this library you have to understand protocol construction. It's best to read whole [RFC 6455](https://tools.ietf.org/html/rfc6455).
Simple chart for overall application flow is planned to land here. In meantime you can check code itself - it's well documented using industry standard [phpDocumentor](http://manual.phpdoc.org).

#### Multiple connections handling mechanism
It's covered by [CherryHttp README](https://github.com/kiler129/CherryHttp/blob/master/README.md).
