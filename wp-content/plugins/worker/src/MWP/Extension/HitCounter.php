<?php
/*
 * This file is part of the ManageWP Worker plugin.
 *
 * (c) ManageWP LLC <contact@managewp.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

class MWP_Extension_HitCounter
{

    private $context;

    /**
     * @var int
     */
    private $numberOfDays;

    const OPTION_NAME = 'user_hit_count';

    /**
     * @param MWP_WordPress_Context $context
     * @param int                   $numberOfDays Number of days to keep the log.
     */
    public function __construct(MWP_WordPress_Context $context, $numberOfDays = 1)
    {
        $this->context      = $context;
        $this->numberOfDays = $numberOfDays;
    }

    /**
     * @param int      $incrementBy
     * @param DateTime $dateTime
     *
     * Note: Type hint removed from $dateTime parameter to fix PHP 8.4+ deprecation warning
     * about implicitly nullable parameters while maintaining backward compatibility with PHP 5.5+.
     * The nullable type syntax (?DateTime) is not supported in PHP 5.5-7.0.
     */
    public function increment($incrementBy = 1, $dateTime = null)
    {
        if ($dateTime === null) {
            $dateTime = new DateTime('now', new DateTimeZone('UTC'));
        }
        /** @var DateTime $dateTime */
        $date = $dateTime->format('Y-m-d');

        $hitCount = (array)$this->getHitCount();

        if (!isset($hitCount[$date])) {
            $hitCount[$date] = 0;

            ksort($hitCount);

            $logSince = clone $dateTime;
            $logSince->modify(sprintf('-%d day', $this->numberOfDays));
            $logSinceDate = $logSince->format('Y-m-d');
            foreach ($hitCount as $hitDate => $hitTotal) {
                // The old functionality had a bug where keys were invalid dates, hence the date length check.
                if ($hitDate <= $logSinceDate || strlen($hitDate) !== 10) {
                    unset($hitCount[$hitDate]);
                }
            }
        }

        $hitCount[$date] += $incrementBy;

        $this->context->optionSet(self::OPTION_NAME, $hitCount);
    }

    /**
     * @return array
     */
    public function getHitCount()
    {
        $hitCount = $this->context->optionGet(self::OPTION_NAME, array());

        return $hitCount;
    }
}
