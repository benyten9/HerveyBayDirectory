<?php

/**
 * REST Api: Page Visit Controller
 *
 * @since 1.2.0
 * @package DoubleScale\Pro\Pro
 * @subpackage Api
 */

namespace DoubleScale\Pro\Modules\WebsiteTracking\Rest\Controllers;

use DoubleScale\Core\UserRoles\Permissions;
use WP_Error;
use Exception;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use DoubleScale\Core\Abstracts\RestController;
use DoubleScale\Pro\Modules\WebsiteTracking\Models\PageVisitModel;
use DoubleScale\Modules\Contacts\Models\ContactModel;

/**
 * RestPageVisitController is REST api controller class for page visits
 *
 * @since 1.2.0
 */
class RestPageVisitController extends RestController
{

    /**
     * REST Base
     *
     * @since 1.2.0
     *
     * @var string
     */
    protected $rest_base = 'page-visits';

    /**
     * Register the routes for the controller.
     *
     * @since 1.2.0
     */
    public function register_routes()
    {
        // List page visits
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array($this, 'get_items'),
                    'permission_callback' => array($this, 'get_items_permissions_check'),
                    'args'                => array(
                        'per_page'   => array(
                            'description' => __('Number of items to fetch.', 'doublescale'),
                            'type'        => 'integer',
                            'default'     => 20,
                        ),
                        'page'       => array(
                            'description' => __('Page number.', 'doublescale'),
                            'type'        => 'integer',
                            'default'     => 1,
                        ),
                        'contact_id' => array(
                            'description' => __('Filter by contact ID.', 'doublescale'),
                            'type'        => 'integer',
                        ),
                        'path'       => array(
                            'description' => __('Filter by path.', 'doublescale'),
                            'type'        => 'string',
                        ),
                        'date_from'  => array(
                            'description' => __('Filter by date from (Y-m-d H:i:s).', 'doublescale'),
                            'type'        => 'string',
                        ),
                        'date_to'    => array(
                            'description' => __('Filter by date to (Y-m-d H:i:s).', 'doublescale'),
                            'type'        => 'string',
                        ),
                    ),
                ),
            )
        );

        // Get single page visit
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/(?P<id>\d+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array($this, 'get_item'),
                    'permission_callback' => array($this, 'get_item_permissions_check'),
                    'args'                => array(
                        'id' => array(
                            'description' => __('Page visit ID.', 'doublescale'),
                            'type'        => 'integer',
                            'required'    => true,
                        ),
                    ),
                ),
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array($this, 'delete_item'),
                    'permission_callback' => array($this, 'delete_item_permissions_check'),
                    'args'                => array(
                        'id' => array(
                            'description' => __('Page visit ID.', 'doublescale'),
                            'type'        => 'integer',
                            'required'    => true,
                        ),
                    ),
                ),
            )
        );

        // Get page visits for a specific contact
        register_rest_route(
            $this->namespace,
            '/contacts/(?P<contact_id>\d+)/' . $this->rest_base,
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array($this, 'get_contact_page_visits'),
                    'permission_callback' => array($this, 'get_items_permissions_check'),
                    'args'                => array(
                        'contact_id' => array(
                            'description' => __('Contact ID.', 'doublescale'),
                            'type'        => 'integer',
                            'required'    => true,
                        ),
                        'per_page'   => array(
                            'description' => __('Number of items to fetch.', 'doublescale'),
                            'type'        => 'integer',
                            'default'     => 20,
                        ),
                        'page'       => array(
                            'description' => __('Page number.', 'doublescale'),
                            'type'        => 'integer',
                            'default'     => 1,
                        ),
                        'date_from'  => array(
                            'description' => __('Filter by date from (Y-m-d H:i:s).', 'doublescale'),
                            'type'        => 'string',
                        ),
                        'date_to'    => array(
                            'description' => __('Filter by date to (Y-m-d H:i:s).', 'doublescale'),
                            'type'        => 'string',
                        ),
                    ),
                ),
            )
        );


        // Bulk delete page visits
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/bulk-delete',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array($this, 'bulk_delete'),
                    'permission_callback' => array($this, 'delete_item_permissions_check'),
                    'args'                => array(
                        'ids'        => array(
                            'description' => __('Array of page visit IDs to delete.', 'doublescale'),
                            'type'        => 'array',
                            'items'       => array(
                                'type' => 'integer',
                            ),
                        ),
                        'contact_id' => array(
                            'description' => __('Delete all page visits for a specific contact.', 'doublescale'),
                            'type'        => 'integer',
                        ),
                        'date_from'  => array(
                            'description' => __('Delete page visits from this date (Y-m-d H:i:s).', 'doublescale'),
                            'type'        => 'string',
                        ),
                        'date_to'    => array(
                            'description' => __('Delete page visits up to this date (Y-m-d H:i:s).', 'doublescale'),
                            'type'        => 'string',
                        ),
                    ),
                ),
            )
        );
    }

    /**
     * Get page visits with pagination and filters
     *
     * @since 1.2.0
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
     */
    public function get_items($request)
    {
        try {
            $per_page   = $request->get_param('per_page') ?: 20;
            $page       = $request->get_param('page') ?: 1;
            $contact_id = $request->get_param('contact_id');
            $path       = $request->get_param('path');
            $date_from  = $request->get_param('date_from');
            $date_to    = $request->get_param('date_to');

            $query = PageVisitModel::with('contact')
                ->orderBy('created_at', 'desc');

            // Apply filters
            if ($contact_id) {
                $query->where('contact_id', $contact_id);
            }

            if ($path) {
                $query->where('path', 'LIKE', '%' . $path . '%');
            }

            if ($date_from) {
                $query->where('created_at', '>=', $date_from);
            }

            if ($date_to) {
                $query->where('created_at', '<=', $date_to);
            }

            // Get total count before pagination
            $total = $query->count();

            // Apply pagination
            $offset = ($page - 1) * $per_page;
            $visits = $query->skip($offset)->take($per_page)->get();

            // Format response
            $items = array();
            foreach ($visits as $visit) {
                $items[] = $this->prepare_item_for_response($visit);
            }

            return new WP_REST_Response(
                array(
                    'data'       => $items,
                    'pagination' => array(
                        'total'        => $total,
                        'per_page'     => $per_page,
                        'current_page' => $page,
                        'total_pages'  => (int) ceil($total / $per_page),
                    ),
                ),
                200
            );
        } catch (Exception $e) {
            return new WP_Error(
                'error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * Get a single page visit
     *
     * @since 1.2.0
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
     */
    public function get_item($request)
    {
        try {
            $id    = $request->get_param('id');
            $visit = PageVisitModel::with('contact')->find($id);

            if (! $visit) {
                return new WP_Error(
                    'not_found',
                    __('Page visit not found.', 'doublescale'),
                    array('status' => 404)
                );
            }

            return new WP_REST_Response(
                $this->prepare_item_for_response($visit),
                200
            );
        } catch (Exception $e) {
            return new WP_Error(
                'error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * Get page visits for a specific contact
     *
     * @since 1.2.0
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
     */
    public function get_contact_page_visits($request)
    {
        try {
            $contact_id = $request->get_param('contact_id');
            $per_page   = $request->get_param('per_page') ?: 20;
            $page       = $request->get_param('page') ?: 1;
            $date_from  = $request->get_param('date_from');
            $date_to    = $request->get_param('date_to');

            // Verify contact exists
            $contact = ContactModel::find($contact_id);
            if (! $contact) {
                return new WP_Error(
                    'not_found',
                    __('Contact not found.', 'doublescale'),
                    array('status' => 404)
                );
            }

            $query = PageVisitModel::where('contact_id', $contact_id)
                ->orderBy('created_at', 'desc');

            if ($date_from) {
                $query->where('created_at', '>=', $date_from);
            }

            if ($date_to) {
                $query->where('created_at', '<=', $date_to);
            }

            // Get total count before pagination
            $total = $query->count();

            // Apply pagination
            $offset = ($page - 1) * $per_page;
            $visits = $query->skip($offset)->take($per_page)->get();

            // Format response
            $items = array();
            foreach ($visits as $visit) {
                $items[] = $this->prepare_item_for_response($visit);
            }

            return new WP_REST_Response(
                array(
                    'data'       => $items,
                    'pagination' => array(
                        'total'        => $total,
                        'per_page'     => $per_page,
                        'current_page' => $page,
                        'total_pages'  => (int) ceil($total / $per_page),
                    ),
                ),
                200
            );
        } catch (Exception $e) {
            return new WP_Error(
                'error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * Delete a page visit
     *
     * @since 1.2.0
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
     */
    public function delete_item($request)
    {
        try {
            $id    = $request->get_param('id');
            $visit = PageVisitModel::find($id);

            if (! $visit) {
                return new WP_Error(
                    'not_found',
                    __('Page visit not found.', 'doublescale'),
                    array('status' => 404)
                );
            }

            $visit->delete();

            return new WP_REST_Response(
                array(
                    'success' => true,
                    'message' => __('Page visit deleted successfully.', 'doublescale'),
                ),
                200
            );
        } catch (Exception $e) {
            return new WP_Error(
                'error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * Bulk delete page visits
     *
     * @since 1.2.0
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
     */
    public function bulk_delete($request)
    {
        try {
            $ids        = $request->get_param('ids');
            $contact_id = $request->get_param('contact_id');
            $date_from  = $request->get_param('date_from');
            $date_to    = $request->get_param('date_to');

            $query = PageVisitModel::query();

            // Delete by IDs
            if (! empty($ids) && is_array($ids)) {
                $query->whereIn('id', $ids);
            } else {
                // Delete by filters
                if ($contact_id) {
                    $query->where('contact_id', $contact_id);
                }

                if ($date_from) {
                    $query->where('created_at', '>=', $date_from);
                }

                if ($date_to) {
                    $query->where('created_at', '<=', $date_to);
                }
            }

            $deleted_count = $query->delete();

            return new WP_REST_Response(
                array(
                    'success' => true,
                    'message' => sprintf(
                        /* translators: %d: number of deleted page visits */
                        __('%d page visits deleted successfully.', 'doublescale'),
                        $deleted_count
                    ),
                    'deleted' => $deleted_count,
                ),
                200
            );
        } catch (Exception $e) {
            return new WP_Error(
                'error',
                $e->getMessage(),
                array('status' => 500)
            );
        }
    }

    /**
     * Prepare item for response
     *
     * @since 1.2.0
     *
     * @param PageVisitModel $visit Page visit model.
     * @param WP_REST_Request $request Request object.
     *
     * @return array Formatted item.
     */
    public function prepare_item_for_response($visit, $request = null)
    {
        $item = array(
            'id'         => $visit->id,
            'contact_id' => $visit->contact_id,
            'path'       => $visit->path,
            'query'      => $visit->query,
            'ip_address' => $this->format_ip_address($visit->ip_address),
            'user_agent' => $visit->user_agent,
            'created_at' => $visit->created_at,
            'updated_at' => $visit->updated_at,
        );

        return $item;
    }

    /**
     * Format IP address from binary to readable format
     *
     * @since 1.2.0
     *
     * @param string $binary_ip Binary IP address.
     *
     * @return string Readable IP address.
     */
    private function format_ip_address($binary_ip)
    {
        if (empty($binary_ip)) {
            return '0.0.0.0';
        }

        $readable_ip = @inet_ntop($binary_ip);
        return $readable_ip !== false ? $readable_ip : '0.0.0.0';
    }

    /**
     * Check if user can view page visits
     *
     * @since 1.2.0
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return bool True if the request has access, false otherwise.
     */
    public function get_items_permissions_check($request)
    {
        return Permissions::has_crm_manager_access();
    }

    /**
     * Check if user can view a single page visit
     *
     * @since 1.2.0
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return bool True if the request has access, false otherwise.
     */
    public function get_item_permissions_check($request)
    {
        return Permissions::has_crm_manager_access();
    }

    /**
     * Check if user can delete page visits
     *
     * @since 1.2.0
     *
     * @param WP_REST_Request $request Full details about the request.
     *
     * @return bool True if the request has access, false otherwise.
     */
    public function delete_item_permissions_check($request)
    {
        return Permissions::has_crm_manager_access();
    }
}
