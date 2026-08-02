<?php

/**
 * @package php-font-lib
 * @link    https://github.com/PhenX/php-font-lib
 * @author  Fabien Ménager <fabien.menager@gmail.com>
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace DoubleScale\Vendor\FontLib\Table\Type;

use DoubleScale\Vendor\FontLib\Table\Table;
/**
 * `fpgm` font table.
 *
 * @package php-font-lib
 */
class fpgm extends Table
{
    private $rawData;
    protected function _parse()
    {
        $font = $this->getFont();
        $font->seek($this->entry->offset);
        $this->rawData = $font->read($this->entry->length);
    }
    function _encode()
    {
        return $this->getFont()->write($this->rawData, $this->entry->length);
    }
}
