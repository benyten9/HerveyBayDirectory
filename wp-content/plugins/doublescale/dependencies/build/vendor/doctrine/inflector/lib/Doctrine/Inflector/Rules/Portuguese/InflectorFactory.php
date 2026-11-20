<?php

declare (strict_types=1);
namespace DoubleScale\Vendor\Doctrine\Inflector\Rules\Portuguese;

use DoubleScale\Vendor\Doctrine\Inflector\GenericLanguageInflectorFactory;
use DoubleScale\Vendor\Doctrine\Inflector\Rules\Ruleset;
final class InflectorFactory extends GenericLanguageInflectorFactory
{
    protected function getSingularRuleset() : Ruleset
    {
        return Rules::getSingularRuleset();
    }
    protected function getPluralRuleset() : Ruleset
    {
        return Rules::getPluralRuleset();
    }
}
