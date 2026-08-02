<?php

declare (strict_types=1);
namespace DoubleScale\Pro\Vendor\Sabre\CalDAV\Notifications;

use DoubleScale\Pro\Vendor\Sabre\CalDAV;
use DoubleScale\Pro\Vendor\Sabre\DAV;
use DoubleScale\Pro\Vendor\Sabre\DAVACL;
/**
 * This node represents a list of notifications.
 *
 * It provides no additional functionality, but you must implement this
 * interface to allow the Notifications plugin to mark the collection
 * as a notifications collection.
 *
 * This collection should only return Sabre\CalDAV\Notifications\INode nodes as
 * its children.
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class Collection extends DAV\Collection implements ICollection, DAVACL\IACL
{
    use DAVACL\ACLTrait;
    /**
     * The notification backend.
     *
     * @var CalDAV\Backend\NotificationSupport
     */
    protected $caldavBackend;
    /**
     * Principal uri.
     *
     * @var string
     */
    protected $principalUri;
    /**
     * Constructor.
     *
     * @param string $principalUri
     */
    public function __construct(CalDAV\Backend\NotificationSupport $caldavBackend, $principalUri)
    {
        $this->caldavBackend = $caldavBackend;
        $this->principalUri = $principalUri;
    }
    /**
     * Returns all notifications for a principal.
     *
     * @return array
     */
    public function getChildren()
    {
        $children = [];
        $notifications = $this->caldavBackend->getNotificationsForPrincipal($this->principalUri);
        foreach ($notifications as $notification) {
            $children[] = new Node($this->caldavBackend, $this->principalUri, $notification);
        }
        return $children;
    }
    /**
     * Returns the name of this object.
     *
     * @return string
     */
    public function getName()
    {
        return 'notifications';
    }
    /**
     * Returns the owner principal.
     *
     * This must be a url to a principal, or null if there's no owner
     *
     * @return string|null
     */
    public function getOwner()
    {
        return $this->principalUri;
    }
}
