<?php

declare (strict_types=1);
namespace DoubleScale\Vendor\Doctrine\Inflector;

use DoubleScale\Vendor\Doctrine\Inflector\Rules\English;
use DoubleScale\Vendor\Doctrine\Inflector\Rules\French;
use DoubleScale\Vendor\Doctrine\Inflector\Rules\NorwegianBokmal;
use DoubleScale\Vendor\Doctrine\Inflector\Rules\Portuguese;
use DoubleScale\Vendor\Doctrine\Inflector\Rules\Spanish;
use DoubleScale\Vendor\Doctrine\Inflector\Rules\Turkish;
use InvalidArgumentException;
use function sprintf;
final class InflectorFactory
{
    public static function create() : LanguageInflectorFactory
    {
        return self::createForLanguage(Language::ENGLISH);
    }
    public static function createForLanguage(string $language) : LanguageInflectorFactory
    {
        switch ($language) {
            case Language::ENGLISH:
                return new English\InflectorFactory();
            case Language::FRENCH:
                return new French\InflectorFactory();
            case Language::NORWEGIAN_BOKMAL:
                return new NorwegianBokmal\InflectorFactory();
            case Language::PORTUGUESE:
                return new Portuguese\InflectorFactory();
            case Language::SPANISH:
                return new Spanish\InflectorFactory();
            case Language::TURKISH:
                return new Turkish\InflectorFactory();
            default:
                throw new InvalidArgumentException(sprintf('Language "%s" is not supported.', $language));
        }
    }
}
