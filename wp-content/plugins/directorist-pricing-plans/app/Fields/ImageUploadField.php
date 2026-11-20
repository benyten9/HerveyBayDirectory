<?php

namespace DirectoristPricingPlan\App\Fields;

defined( 'ABSPATH' ) || exit;

use Directorist\Fields\Image_Upload_Field;
use DirectoristPricingPlan\App\Providers\FormFields;

class ImageUploadField extends Image_Upload_Field {
    public $type = 'pricing_plan_image_upload';

    public function validate( $posted_data ) {
        $provider = directorist_pricing_plans_singleton( FormFields::class );
        $feature  = $provider->get_image_upload_feature( $posted_data );

        if ( ! $feature ) {
            return parent::validate( $posted_data );
        }

        $unlimited = ! empty( $feature->data['is_unlimited'] );
        $limit     = isset( $feature->data['limit'] ) ? absint( $feature->data['limit'] ) : null;

        if ( ! $feature->is_enabled || ( ! $unlimited && 0 === $limit ) ) {
            $this->add_error( __( 'Images are not allowed by your plan.', 'directorist-pricing-plans' ) );

            return false;
        }

        // Override only the image count. Core still validates the file types and upload sizes.
        if ( $unlimited || null !== $limit ) {
            $this->max_image_limit = $unlimited ? 0 : $limit;
        }

        return parent::validate( $posted_data );
    }
}
