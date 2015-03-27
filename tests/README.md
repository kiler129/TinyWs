# Functional tests
TinyWs is tested before every repository push. Tests are performed using [Autobahn Testsuite](http://autobahn.ws/testsuite/usage.html).  
To run test by yourself you have to [install Autobahn Testsuite](http://autobahn.ws/testsuite/installation.html#installation) first. Next start [**fuzzingEchoServer** example](https://github.com/kiler129/TinyWs/blob/master/examples/fuzzingEchoServer.php) and than execute `wstest -m fuzzingclient -s ./autobahn_fuzzingclient.json`. Result are saved in `reports/tinyws/index.html` by default.  
Also remember to disable XDebug running tests - some of them have time limits. For example 9.4.1 can fail with after 100 seconds if XDebug is enabled (when it's disabled 9.4.1 takes no more than 3s).


## Why there are special settings for tests?
Don't blame me, it's IETF fault actually :)  
Standards are developed to be flexible and generally secure. Unfortunately some unrealistic assumptions were made, which could lead to real-world DoS.
Of course list below isn't closed - our users (and @kolorafa) invention is incredible:
  * Single(!) frame take up to 9,223,372,036,854,775,808 (~9.2 exabytes). Also single message can consist of unlimited number of frames :)
  * Large message can be fragmented, but there's no limitation for number of fragments. Eg. there can be 8MB frame fragmented into 1B chunks which generated massive CPU load.
  * Slow data transfer, exhausting resources ("slowloris" variant): since WebSocket protocol is, by definition, intended to handle long-lasting connection classic "slowloris" attack doesn't apply. Application could be protected by variants of this attack by utilizing relatively small packets (up to 65KB of payload) and implementing pings with timeout of no more than 10s.

## Expected results comments (for hackers)
To understand this section you need to know [RFC 6455](https://tools.ietf.org/html/rfc6455) in details. Normal library users aren't obligated to understand these details. 
In ideal world all test should just display green "Pass" sign, but since there's no ideal world there are some quirks ;)

### 6.4 Fail-fast on invalid UTF-8
Due to performance reasons encoding is validated after all data fragments are received. There's nothing wrong with "Non-strict" results in that section. Also trying to validate partial frames would be complicated and can result in many false-negatives.

### Section 12 & 13 - WebSocket Compression
All test cases from these sections are excluded. Packets compression specification aren't stable enough to be implemented into stable release of this server.  
Also due to little (or actually no) benefits of packets compression and problems & server load this extension implicates it's not supported *by design* in TinyWs. Situation may change in future.

# Unit tests
Currently code isn't covered by unit tests due to lack of time.
