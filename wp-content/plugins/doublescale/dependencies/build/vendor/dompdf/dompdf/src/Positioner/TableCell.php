<?php

/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace DoubleScale\Vendor\Dompdf\Positioner;

use DoubleScale\Vendor\Dompdf\FrameDecorator\AbstractFrameDecorator;
use DoubleScale\Vendor\Dompdf\FrameDecorator\Table;
/**
 * Positions table cells
 *
 * @package dompdf
 */
class TableCell extends AbstractPositioner
{
    /**
     * @param AbstractFrameDecorator $frame
     */
    function position(AbstractFrameDecorator $frame) : void
    {
        $table = Table::find_parent_table($frame);
        $cellmap = $table->get_cellmap();
        $frame->set_position($cellmap->get_frame_position($frame));
    }
}
