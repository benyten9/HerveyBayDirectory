<?php

namespace SEOPressPro\Vendor\Composer;

use SEOPressPro\Vendor\Composer\Semver\VersionParser;
class InstalledVersions
{
    private static $installed = array('root' => array('pretty_version' => '10.4.x-dev', 'version' => '10.4.9999999.9999999-dev', 'aliases' => array(), 'reference' => 'b43174348a78227e5d7f8fa7464192d190f1f0dc', 'name' => 'wp-seopress-pro/wp-seopress-pro'), 'versions' => array('firebase/php-jwt' => array('pretty_version' => 'v6.10.0', 'version' => '6.10.0.0', 'aliases' => array(), 'reference' => 'a49db6f0a5033aef5143295342f1c95521b075ff'), 'google/apiclient' => array('pretty_version' => 'v2.16.1', 'version' => '2.16.1.0', 'aliases' => array(), 'reference' => 'd7066f3a205aaeb83cce439613770e69394a43c8'), 'google/apiclient-services' => array('pretty_version' => 'v0.355.0', 'version' => '0.355.0.0', 'aliases' => array(), 'reference' => '235e6a45ecafd77accc102b5ab6d529aab54da23'), 'google/auth' => array('pretty_version' => 'v1.37.2', 'version' => '1.37.2.0', 'aliases' => array(), 'reference' => '25eed0045d8cf107424a8b9010c9fdcc0734ceb0'), 'guzzlehttp/guzzle' => array('pretty_version' => '7.16.1', 'version' => '7.16.1.0', 'aliases' => array(), 'reference' => '81755d989b3007ca7f88a069857553cd08e7dc2e'), 'guzzlehttp/promises' => array('pretty_version' => '2.6.0', 'version' => '2.6.0.0', 'aliases' => array(), 'reference' => '3d88c829bd5f13d13847b39067dbf09a60af2154'), 'guzzlehttp/psr7' => array('pretty_version' => '2.13.2', 'version' => '2.13.2.0', 'aliases' => array(), 'reference' => 'adee536f95101929f262b8c59e8a61472f347d58'), 'matthiasmullie/minify' => array('pretty_version' => '1.3.75', 'version' => '1.3.75.0', 'aliases' => array(), 'reference' => '76ba4a5f555fd7bf4aa408af608e991569076671'), 'matthiasmullie/path-converter' => array('pretty_version' => '1.1.3', 'version' => '1.1.3.0', 'aliases' => array(), 'reference' => 'e7d13b2c7e2f2268e1424aaed02085518afa02d9'), 'monolog/monolog' => array('pretty_version' => '2.11.1', 'version' => '2.11.1.0', 'aliases' => array(), 'reference' => 'bf2403f591c5a431b6e0e1170cbadd3d64499564'), 'paragonie/constant_time_encoding' => array('pretty_version' => 'v2.8.2', 'version' => '2.8.2.0', 'aliases' => array(), 'reference' => 'e30811f7bc69e4b5b6d5783e712c06c8eabf0226'), 'paragonie/random_compat' => array('pretty_version' => 'v9.99.100', 'version' => '9.99.100.0', 'aliases' => array(), 'reference' => '996434e5492cb4c3edcb9168db6fbb1359ef965a'), 'phpseclib/phpseclib' => array('pretty_version' => '3.0.57', 'version' => '3.0.57.0', 'aliases' => array(), 'reference' => 'd17e0ddaeaf6f22f7e007cbb437d78792fe2a0e4'), 'psr/cache' => array('pretty_version' => '1.0.1', 'version' => '1.0.1.0', 'aliases' => array(), 'reference' => 'd11b50ad223250cf17b86e38383413f5a6764bf8'), 'psr/http-client' => array('pretty_version' => '1.0.3', 'version' => '1.0.3.0', 'aliases' => array(), 'reference' => 'bb5906edc1c324c9a05aa0873d40117941e5fa90'), 'psr/http-client-implementation' => array('provided' => array(0 => '1.0')), 'psr/http-factory' => array('pretty_version' => '1.1.0', 'version' => '1.1.0.0', 'aliases' => array(), 'reference' => '2b4765fddfe3b508ac62f829e852b1501d3f6e8a'), 'psr/http-factory-implementation' => array('provided' => array(0 => '1.0')), 'psr/http-message' => array('pretty_version' => '2.0', 'version' => '2.0.0.0', 'aliases' => array(), 'reference' => '402d35bcb92c70c026d1a6a9883f06b2ead23d71'), 'psr/http-message-implementation' => array('provided' => array(0 => '1.0')), 'psr/log' => array('pretty_version' => '1.1.4', 'version' => '1.1.4.0', 'aliases' => array(), 'reference' => 'd49695b909c3b7628b6289db5479a1c204601f11'), 'psr/log-implementation' => array('provided' => array(0 => '1.0.0 || 2.0.0 || 3.0.0')), 'ralouphie/getallheaders' => array('pretty_version' => '3.0.3', 'version' => '3.0.3.0', 'aliases' => array(), 'reference' => '120b605dfeb996808c31b6477290a714d356e822'), 'symfony/deprecation-contracts' => array('pretty_version' => 'v2.5.4', 'version' => '2.5.4.0', 'aliases' => array(), 'reference' => '605389f2a7e5625f273b53960dc46aeaf9c62918'), 'symfony/polyfill-php80' => array('pretty_version' => 'v1.43.0', 'version' => '1.43.0.0', 'aliases' => array(), 'reference' => '9c6a5d6b01ca51d486c813e9a9c0ed55f208bb74'), 'wp-seopress-pro/wp-seopress-pro' => array('pretty_version' => '10.4.x-dev', 'version' => '10.4.9999999.9999999-dev', 'aliases' => array(), 'reference' => 'b43174348a78227e5d7f8fa7464192d190f1f0dc')));
    public static function getInstalledPackages()
    {
        return array_keys(self::$installed['versions']);
    }
    public static function isInstalled($packageName)
    {
        return isset(self::$installed['versions'][$packageName]);
    }
    public static function satisfies(VersionParser $parser, $packageName, $constraint)
    {
        $constraint = $parser->parseConstraints($constraint);
        $provided = $parser->parseConstraints(self::getVersionRanges($packageName));
        return $provided->matches($constraint);
    }
    public static function getVersionRanges($packageName)
    {
        if (!isset(self::$installed['versions'][$packageName])) {
            throw new \OutOfBoundsException('Package "' . $packageName . '" is not installed');
        }
        $ranges = array();
        if (isset(self::$installed['versions'][$packageName]['pretty_version'])) {
            $ranges[] = self::$installed['versions'][$packageName]['pretty_version'];
        }
        if (array_key_exists('aliases', self::$installed['versions'][$packageName])) {
            $ranges = array_merge($ranges, self::$installed['versions'][$packageName]['aliases']);
        }
        if (array_key_exists('replaced', self::$installed['versions'][$packageName])) {
            $ranges = array_merge($ranges, self::$installed['versions'][$packageName]['replaced']);
        }
        if (array_key_exists('provided', self::$installed['versions'][$packageName])) {
            $ranges = array_merge($ranges, self::$installed['versions'][$packageName]['provided']);
        }
        return implode(' || ', $ranges);
    }
    public static function getVersion($packageName)
    {
        if (!isset(self::$installed['versions'][$packageName])) {
            throw new \OutOfBoundsException('Package "' . $packageName . '" is not installed');
        }
        if (!isset(self::$installed['versions'][$packageName]['version'])) {
            return null;
        }
        return self::$installed['versions'][$packageName]['version'];
    }
    public static function getPrettyVersion($packageName)
    {
        if (!isset(self::$installed['versions'][$packageName])) {
            throw new \OutOfBoundsException('Package "' . $packageName . '" is not installed');
        }
        if (!isset(self::$installed['versions'][$packageName]['pretty_version'])) {
            return null;
        }
        return self::$installed['versions'][$packageName]['pretty_version'];
    }
    public static function getReference($packageName)
    {
        if (!isset(self::$installed['versions'][$packageName])) {
            throw new \OutOfBoundsException('Package "' . $packageName . '" is not installed');
        }
        if (!isset(self::$installed['versions'][$packageName]['reference'])) {
            return null;
        }
        return self::$installed['versions'][$packageName]['reference'];
    }
    public static function getRootPackage()
    {
        return self::$installed['root'];
    }
    public static function getRawData()
    {
        return self::$installed;
    }
    public static function reload($data)
    {
        self::$installed = $data;
    }
}
