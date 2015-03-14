# Examples
 All examples assumes composer installation. If you installed TinyWs using different method you need to modify examples code to include necessary files.  
`_RequiredFiles.php` contains necessary includes to run them without autoloader present - just replace `require_once('../../../autoload.php')` with `require_once('_RequiredFiles.php')`. Also keep in mind CherryHttp is still required, and expected to reside in composer-like location.

Examples also uses [Shout](https://github.com/kiler129/Shout) as log target. You can replace it with any [PSR-3 complaint logger](https://packagist.org/search/?tags=psr-3) or just pass NullLogger() to run in complete salience.
